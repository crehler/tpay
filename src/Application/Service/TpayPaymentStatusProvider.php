<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Application\Service;

use Crehler\PaymentBundle\Application\Port\Driven\PaymentGatewayStatusProviderInterface;
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Domain\ValueObjects\PaymentStatus;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Enums\TpayApiTransactionStatus;
use Crehler\Tpay\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

use function in_array;

#[AutoconfigureTag('crehler.payment.gateway_status_provider')]
final readonly class TpayPaymentStatusProvider implements PaymentGatewayStatusProviderInterface
{
    private const TPAY_HANDLER_IDENTIFIERS = [
        BlikHandler::class,
        BankHandler::class,
        CardHandler::class,
    ];

    public function __construct(
        private TpayClientFactory $tpayClientFactory,
        private EnhancedLogger $logger,
    ) {
    }

    public function supports(OrderTransactionEntity $orderTransaction): bool
    {
        $paymentMethod = $orderTransaction->getPaymentMethod();

        if ($paymentMethod === null) {
            return false;
        }

        return in_array($paymentMethod->getHandlerIdentifier(), self::TPAY_HANDLER_IDENTIFIERS, true);
    }

    public function getPaymentStatus(OrderTransactionEntity $orderTransaction): ?PaymentStatus
    {
        $customFields = $orderTransaction->getCustomFields();
        $gatewayPaymentId = $customFields[PaymentCustomFields::GATEWAY_PAYMENT_ID] ?? null;

        if ($gatewayPaymentId === null) {
            $this->logger->warning('Tpay gateway payment ID not found in transaction custom fields', [
                'orderTransactionId' => $orderTransaction->getId(),
            ]);

            return null;
        }

        try {
            $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();
            $tpay = $this->tpayClientFactory->create($salesChannelId);
            $transaction = $tpay->transactions()->getTransactionById($gatewayPaymentId);

            $status = $transaction['status'] ?? 'unknown';

            $this->logger->debug('Tpay payment status retrieved', [
                'orderTransactionId' => $orderTransaction->getId(),
                'gatewayPaymentId' => $gatewayPaymentId,
                'status' => $status,
            ]);

            return $this->mapTpayStatus(status: $status, orderTransactionId: $orderTransaction->getId());
        } catch (Throwable $e) {
            $this->logger->error('Failed to get Tpay payment status', [
                'orderTransactionId' => $orderTransaction->getId(),
                'gatewayPaymentId' => $gatewayPaymentId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Map Tpay Open API `status` string to a canonical PaymentStatus.
     *
     * `chargeback` and `refund` intentionally return null so the caller falls back
     * to the Shopware state machine — they represent post-paid lifecycle states
     * that don't fit the paid/waiting/failed triad used by the frontend polling.
     */
    private function mapTpayStatus(string $status, string $orderTransactionId): ?PaymentStatus
    {
        return match (TpayApiTransactionStatus::tryFrom($status)) {
            TpayApiTransactionStatus::CORRECT, TpayApiTransactionStatus::PAID => PaymentStatus::paid(),
            TpayApiTransactionStatus::PENDING, TpayApiTransactionStatus::NEW => PaymentStatus::waiting(),
            TpayApiTransactionStatus::ERROR, TpayApiTransactionStatus::FAILED, TpayApiTransactionStatus::EXPIRED => PaymentStatus::failed(),
            TpayApiTransactionStatus::CHARGEBACK, TpayApiTransactionStatus::REFUND => null,
            null => $this->logUnknownAndDeferToStateMachine($status, $orderTransactionId),
        };
    }

    private function logUnknownAndDeferToStateMachine(string $status, string $orderTransactionId): ?PaymentStatus
    {
        $this->logger->warning('Tpay returned unmapped transaction status; deferring to state machine', [
            'orderTransactionId' => $orderTransactionId,
            'status' => $status,
        ]);

        return null;
    }
}
