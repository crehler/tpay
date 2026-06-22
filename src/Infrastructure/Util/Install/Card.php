<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Infrastructure\Util\Lifecycle\{ShopwarePaymentMethod, ShopwarePaymentMethodDescription};
use Crehler\Tpay\Constant\Methods;
use Crehler\Tpay\Handler\CardHandler;

final class Card extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: CardHandler::class,
            position: 2,
            technicalName: Methods::CARD_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'Karta',
                    description: 'Płatność kartą kredytową lub debetową. Obsługiwane przez Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'Card',
                    description: 'Credit or debit card payment. Powered by Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'Karte',
                    description: 'Kredit- oder Debitkartenzahlung. Powered by Tpay.',
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'card',
        );
    }
}
