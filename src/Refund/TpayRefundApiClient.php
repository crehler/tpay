<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Refund;

use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_values;
use function in_array;
use function is_array;
use function round;
use function strtolower;
use function trim;

/**
 * Thin adapter over the Tpay OpenAPI SDK refund shapes. Keeps the gateway as the
 * source of truth for amounts (the bundle's refund entities mirror it). Shared by
 * TpayRefundProvider (live refunds) and the historical migration command.
 */
final class TpayRefundApiClient
{
    private const NON_COUNTED_REFUND_STATUSES = ['cancel'];

    public function __construct(
        private readonly EnhancedLogger $logger,
    ) {
    }

    public function getTransactionAmount(object $tpay, string $tpayTransactionId): float
    {
        $transaction = $tpay->transactions()->getTransactionById($tpayTransactionId);

        return (float) ($transaction['amount'] ?? 0.0);
    }

    public function getTransactionTitle(object $tpay, string $tpayTransactionId): ?string
    {
        $transaction = $tpay->transactions()->getTransactionById($tpayTransactionId);

        return $transaction['title'] ?? null;
    }

    /**
     * Total amount already refunded for the transaction (refunds in "cancel" state
     * no longer reserve the amount, so they are excluded).
     */
    public function getAlreadyRefunded(object $tpay, string $tpayTransactionId, ?string $transactionTitle): float
    {
        $sum = 0.0;

        foreach ($this->listRefunds($tpay, $tpayTransactionId, $transactionTitle) as $refund) {
            if ($this->shouldCountRefund($refund)) {
                $sum += (float) ($refund['amount'] ?? 0.0);
            }
        }

        return round($sum, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRefunds(object $tpay, string $tpayTransactionId, ?string $transactionTitle): array
    {
        $refunds = $this->extractRefundRows($tpay->transactions()->getRefundsByTransactionId($tpayTransactionId, []));

        if ($refunds === []) {
            $refunds = $this->getRefundsFromGlobalList($tpay, $tpayTransactionId, $transactionTitle);
        }

        return $refunds;
    }

    /**
     * @return array<string, mixed>
     */
    public function createRefund(object $tpay, string $tpayTransactionId, float $amount): array
    {
        // Always send an explicit amount to avoid the SDK's empty-body edge case.
        return $tpay->transactions()->createRefundByTransactionId(
            ['amount' => round($amount, 2)],
            $tpayTransactionId,
        );
    }

    public function isCountableStatus(string $status): bool
    {
        return $this->shouldCountRefund(['status' => $status]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRefundsFromGlobalList(object $tpay, string $tpayTransactionId, ?string $transactionTitle): array
    {
        try {
            $refunds = $this->extractRefundRows($tpay->refunds()->getRefunds());

            return array_values(array_filter(
                $refunds,
                static function (array $refund) use ($tpayTransactionId, $transactionTitle): bool {
                    $refundTransactionId = $refund['transactionId'] ?? $refund['transaction_id'] ?? null;
                    if ($refundTransactionId !== null && $refundTransactionId !== '') {
                        // A refund tied to a known (different) transaction must never be
                        // matched by title — that would cross-attribute another order's refund.
                        return (string) $refundTransactionId === $tpayTransactionId;
                    }

                    if ($transactionTitle === null || $transactionTitle === '') {
                        return false;
                    }

                    $refundTransactionTitle = $refund['transactionTitle'] ?? $refund['transaction_title'] ?? null;

                    return $refundTransactionTitle === $transactionTitle;
                },
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Could not load Tpay refunds global fallback', [
                'tpayTransactionId' => $tpayTransactionId,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRefundRows(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        foreach (['refundsList', 'refunds'] as $key) {
            if (is_array($result[$key] ?? null)) {
                return array_values(array_filter($result[$key], 'is_array'));
            }
        }

        if (array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $refund
     */
    private function shouldCountRefund(array $refund): bool
    {
        $status = strtolower(trim((string) ($refund['status'] ?? '')));

        return $status === '' || !in_array($status, self::NON_COUNTED_REFUND_STATUSES, true);
    }
}
