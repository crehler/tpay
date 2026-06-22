<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Provider;

use Crehler\PaymentBundle\Application\Service\StoredCardService;
use Crehler\PaymentBundle\Domain\ValueObjects\SavedCard;
use Crehler\PaymentBundle\Infrastructure\StoreApi\CustomerSavedCard\SavedCardTokenProviderInterface;
use Crehler\Tpay\Handler\CardHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

use function strtolower;

class TpaySavedCardTokenProvider implements SavedCardTokenProviderInterface
{
    public function __construct(
        private readonly StoredCardService $savedCardService,
    ) {
    }

    public function operated(PaymentMethodEntity $paymentMethod): bool
    {
        return $paymentMethod->getHandlerIdentifier() === CardHandler::class;
    }

    /**
     * @return SavedCard[]
     */
    public function getCustomerCardTokens(PaymentMethodEntity $paymentMethod, SalesChannelContext $salesChannelContext): array
    {
        $customer = $salesChannelContext->getCustomer();

        if ($customer === null || $customer->getGuest()) {
            return [];
        }

        $cards = $this->savedCardService->findActiveByCustomerAndChannel(
            customerId: $customer->getId(),
            salesChannelId: $salesChannelContext->getSalesChannelId(),
            context: $salesChannelContext->getContext(),
        );

        $result = [];
        $isFirst = true;

        foreach ($cards as $card) {
            $brand = (string) $card->getBrand();

            $result[] = new SavedCard(
                token: $card->getId(),
                brandImgUrl: '',
                status: 'active',
                expirationYear: (int) $card->getExpirationYear(),
                expirationMonth: (int) $card->getExpirationMonth(),
                cardNumberMasked: '**** **** **** ' . $card->getTail(),
                cardBrand: $brand,
                preferred: $isFirst,
                cardScheme: strtolower($brand),
            );

            $isFirst = false;
        }

        return $result;
    }
}
