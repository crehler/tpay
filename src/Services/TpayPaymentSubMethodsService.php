<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Services;

use Crehler\PaymentBundle\Infrastructure\Provider\{AbstractPaymentSubMethodProvider, RawSubMethod};
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Constant\TpayPayGroup;
use Crehler\Tpay\Handler\BankHandler;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Throwable;

use function in_array;

/**
 * Provides Tpay bank channels as sub-methods for the Tpay Bank handler.
 * Filtering/mapping (min/max + PaymentSubMethod) is handled by the bundle base;
 * this only fetches and applies Tpay-specific group/availability filtering.
 */
final class TpayPaymentSubMethodsService extends AbstractPaymentSubMethodProvider
{
    /**
     * @var mixed[]
     */
    private const SUPPORTED_HANDLERS = [
        BankHandler::class,
    ];

    /**
     * @var mixed[]
     */
    private const EXCLUDED_GROUP_IDS = TpayPayGroup::EXCLUDED_FROM_BANK;

    public function __construct(
        private readonly TpayClientFactory $tpayClientFactory,
        private readonly EnhancedLogger $logger,
    ) {
    }

    public function supportsPaymentMethod(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return in_array($paymentMethodEntity->getHandlerIdentifier(), self::SUPPORTED_HANDLERS, true);
    }

    protected function fetchRawSubMethods(
        PaymentMethodEntity $paymentMethodEntity,
        int $paymentValue,
        SalesChannelContext $context,
    ): iterable {
        try {
            $tpay = $this->tpayClientFactory->create($context->getSalesChannelId());
        } catch (Throwable $e) {
            $this->logger->error('Tpay client creation failed', ['exception' => $e]);

            return [];
        }

        try {
            $channels = $tpay->transactions()->getChannels();
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch Tpay channels: ' . $e->getMessage(), ['exception' => $e]);

            return [];
        }

        $raw = [];

        foreach ($channels['channels'] ?? [] as $channel) {
            $groupId = (int) ($channel['groups'][0]['id'] ?? 0);

            // Skip channels belonging to excluded groups (Card, BLIK, etc.)
            if (in_array($groupId, self::EXCLUDED_GROUP_IDS, true)) {
                continue;
            }

            // Only include available channels
            if (!($channel['available'] ?? false)) {
                continue;
            }

            $raw[] = new RawSubMethod(
                providerId: (string) $channel['id'],
                name: $channel['fullName'] ?? $channel['name'] ?? '',
                mediaUrl: $channel['image']['url'] ?? '',
            );
        }

        return $raw;
    }
}
