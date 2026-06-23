<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Application\Service;

use Crehler\PaymentBundle\Application\DTO\GatewayDetails\{GatewayPaymentDetails, GatewayStatusLevel};
use Crehler\PaymentBundle\Application\Port\Driven\GatewayPaymentDetailsProviderInterface;
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Throwable;

use function in_array;
use function strtolower;

/**
 * Exposes Tpay transaction details (Open API GET /transactions/{id}) for the admin
 * order "Szczegóły" tab.
 */
final readonly class TpayGatewayDetailsProvider implements GatewayPaymentDetailsProviderInterface
{
    private const HANDLERS = [BlikHandler::class, BankHandler::class, CardHandler::class];

    public function __construct(
        private TpayClientFactory $tpayClientFactory,
        private EnhancedLogger $logger,
    ) {
    }

    public function supports(OrderTransactionEntity $orderTransaction): bool
    {
        return in_array($orderTransaction->getPaymentMethod()?->getHandlerIdentifier(), self::HANDLERS, true);
    }

    public function getDetails(OrderTransactionEntity $orderTransaction): ?GatewayPaymentDetails
    {
        $customFields = $orderTransaction->getCustomFields() ?? [];
        $gatewayId = $customFields[PaymentCustomFields::GATEWAY_PAYMENT_ID] ?? null;
        if (!$gatewayId) {
            return null;
        }

        try {
            $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();
            $tpay = $this->tpayClientFactory->create($salesChannelId);
            $tx = $tpay->transactions()->getTransactionById((string) $gatewayId);

            $status = (string) ($tx['status'] ?? 'unknown');

            return new GatewayPaymentDetails(
                provider: 'Tpay',
                gatewayId: (string) ($tx['transactionId'] ?? $gatewayId),
                rawStatus: $status,
                statusLevel: $this->mapLevel($status),
                amount: isset($tx['amount']) ? (float) $tx['amount'] : null,
                currency: isset($tx['currency']) ? (string) $tx['currency'] : null,
                method: $this->resolveMethod($tx),
                createdAt: $tx['date']['creation'] ?? null,
                title: isset($tx['title']) ? (string) $tx['title'] : null,
                sandbox: !$this->tpayClientFactory->isProductionMode($salesChannelId),
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to load Tpay gateway details', [
                'orderTransactionId' => $orderTransaction->getId(),
                'gatewayId' => $gatewayId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $tx
     */
    private function resolveMethod(array $tx): ?string
    {
        return $tx['payments']['method']
            ?? $tx['payment']['method']
            ?? (isset($tx['payments']['channelId']) ? (string) $tx['payments']['channelId'] : null);
    }

    private function mapLevel(string $status): string
    {
        return match (strtolower($status)) {
            'correct', 'paid' => GatewayStatusLevel::PAID->value,
            'pending', 'new' => GatewayStatusLevel::PENDING->value,
            'error', 'failed', 'expired' => GatewayStatusLevel::FAILED->value,
            'chargeback', 'refund' => GatewayStatusLevel::REFUNDED->value,
            default => GatewayStatusLevel::UNKNOWN->value,
        };
    }
}
