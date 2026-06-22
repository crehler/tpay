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
use Crehler\Tpay\Handler\BankHandler;

final class Bank extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: BankHandler::class,
            position: 3,
            technicalName: Methods::BANK_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'Przelew online',
                    description: 'Wybierz swój bank i dokonaj płatności. Obsługiwane przez Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'Online transfer',
                    description: 'Choose your bank and make a payment. Powered by Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'Online-Uberweisung',
                    description: 'Wahlen Sie Ihre Bank und fuhren Sie die Zahlung durch. Powered by Tpay.',
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'bank',
            subMethodsEnabled: true,
        );
    }
}
