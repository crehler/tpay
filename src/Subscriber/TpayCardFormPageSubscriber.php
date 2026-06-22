<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Subscriber;

use Crehler\PaymentBundle\Infrastructure\Configuration\PaymentBundleConfigService;
use Crehler\Tpay\Handler\CardHandler;
use Crehler\Tpay\Struct\TpayCardFormStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function trim;

#[Autoconfigure(tags: [['name' => 'kernel.event_subscriber']])]
final readonly class TpayCardFormPageSubscriber implements EventSubscriberInterface
{
    private const PUBLIC_KEY_CONFIG = 'CrehlerTpay.config.cardsApiPublicKey';
    private const SANDBOX_PUBLIC_KEY_CONFIG = 'CrehlerTpay.config.sandboxCardsApiPublicKey';
    private const ENABLE_SANDBOX_CONFIG = 'CrehlerTpay.config.enableSandbox';

    public function __construct(
        private SystemConfigService $systemConfigService,
        private PaymentBundleConfigService $bundleConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => ['onPageLoaded', 900],
            AccountEditOrderPageLoadedEvent::class => ['onPageLoaded', 900],
        ];
    }

    public function onPageLoaded(CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $paymentMethod = $context->getPaymentMethod();

        if ($paymentMethod->getHandlerIdentifier() !== CardHandler::class) {
            return;
        }

        $salesChannelId = $context->getSalesChannelId();

        if (!$this->bundleConfigService->isEmbedCardFormEnabled($paymentMethod, $salesChannelId)) {
            return;
        }

        $sandbox = $this->systemConfigService->getBool(self::ENABLE_SANDBOX_CONFIG, $salesChannelId);
        $publicKeyConfig = $sandbox ? self::SANDBOX_PUBLIC_KEY_CONFIG : self::PUBLIC_KEY_CONFIG;

        $publicKey = trim((string) $this->systemConfigService->get($publicKeyConfig, $salesChannelId));

        if ($publicKey === '') {
            return;
        }

        $struct = new TpayCardFormStruct(cardsApiPublicKey: $publicKey);
        $event->getPage()->addExtension($struct->getApiAlias(), $struct);
    }
}
