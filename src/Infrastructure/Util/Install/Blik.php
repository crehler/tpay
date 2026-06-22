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
use Crehler\Tpay\Handler\BlikHandler;

final class Blik extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: BlikHandler::class,
            position: 1,
            technicalName: Methods::BLIK_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'BLIK',
                    description: 'Podaj kod z aplikacji bankowej. Obsługiwane przez Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'BLIK',
                    description: 'Enter the code from your bank app. Powered by Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'BLIK',
                    description: 'Geben Sie den Code aus Ihrer Bank-App ein. Powered by Tpay.',
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'blik',
        );
    }
}
