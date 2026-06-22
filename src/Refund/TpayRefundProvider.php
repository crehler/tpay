<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Refund;

use Crehler\PaymentBundle\Application\DTO\Refund\RefundCommand;
use Crehler\PaymentBundle\Application\Port\Driven\RefundProviderPort;
use Crehler\PaymentBundle\Application\Service\OrderTransactionSalesChannelResolver;
use Crehler\PaymentBundle\Domain\ValueObjects\RefundResult;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Framework\Context;
use Throwable;

use function in_array;
use function round;
use function sprintf;
use function strtolower;

/**
 * Tpay implementation of the bundle's refund port. Translates a gateway-agnostic
 * RefundCommand into a Tpay refund SDK call and maps the response to a RefundResult.
 * All native-entity / state-machine handling lives in the bundle handler.
 */
final class TpayRefundProvider implements RefundProviderPort
{
    private const SUPPORTED_HANDLERS = [
        BankHandler::class,
        BlikHandler::class,
        CardHandler::class,
    ];

    public function __construct(
        private readonly TpayClientFactory $tpayClientFactory,
        private readonly TpayRefundApiClient $apiClient,
        private readonly OrderTransactionSalesChannelResolver $salesChannelResolver,
        private readonly EnhancedLogger $logger,
    ) {
    }

    public function supports(string $handlerIdentifier): bool
    {
        return in_array($handlerIdentifier, self::SUPPORTED_HANDLERS, true);
    }

    public function refund(RefundCommand $command, Context $context): RefundResult
    {
        $salesChannelId = $this->salesChannelResolver->resolve($command->orderTransactionId, $context);
        $tpay = $this->tpayClientFactory->create($salesChannelId);
        $amount = round($command->amount / 100, 2);

        // Gateway is the source of truth: guard against over-refunding even if the
        // entity-derived remaining amount drifts from Tpay's view.
        try {
            $title = $this->apiClient->getTransactionTitle($tpay, $command->gatewayPaymentId);
            $total = $this->apiClient->getTransactionAmount($tpay, $command->gatewayPaymentId);
            $alreadyRefunded = $this->apiClient->getAlreadyRefunded($tpay, $command->gatewayPaymentId, $title);
            $maxRefundable = round($total - $alreadyRefunded, 2);

            if ($total > 0.0 && $amount > $maxRefundable) {
                return RefundResult::failed(sprintf(
                    'Refund amount %.2f exceeds the refundable balance %.2f at Tpay',
                    $amount,
                    $maxRefundable,
                ));
            }
        } catch (Throwable $e) {
            $this->logger->warning('Tpay refundable-amount guard skipped (API error)', [
                'gatewayPaymentId' => $command->gatewayPaymentId,
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            $result = $this->apiClient->createRefund($tpay, $command->gatewayPaymentId, $amount);
        } catch (Throwable $e) {
            $this->logger->error('Tpay refund API call failed', [
                'gatewayPaymentId' => $command->gatewayPaymentId,
                'exception' => $e->getMessage(),
            ]);

            return RefundResult::failed($e->getMessage());
        }

        $this->logger->debug('Tpay refund response', [
            'orderTransactionId' => $command->orderTransactionId,
            'gatewayPaymentId' => $command->gatewayPaymentId,
            'result' => $result,
        ]);

        if (($result['result'] ?? null) !== 'success') {
            return RefundResult::failed('Tpay rejected the refund request');
        }

        // Tpay all but always returns refundId/requestId on success; the deterministic
        // fallback (never time()) keeps externalReference non-null and the refund entity
        // dedup-stable. It uses RefundCommand::refundId (the Shopware refund entity id —
        // unique per refund, identical across retries) so two same-amount partial refunds
        // do not collide on a single externalReference.
        $gatewayRefundId = $result['refundId']
            ?? $result['requestId']
            ?? ('tpay-' . $command->gatewayPaymentId . '-' . ($command->refundId ?? $command->amount));
        $tpayStatus = strtolower((string) ($result['status'] ?? ''));

        return match ($tpayStatus) {
            'pending' => RefundResult::inProgress($gatewayRefundId),
            default => RefundResult::completed($gatewayRefundId),
        };
    }
}
