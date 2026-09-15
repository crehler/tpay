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
use Crehler\Tpay\Handler\TpayHandler;

/**
 * Named simply "Tpay" on purpose. The other three tell the customer which instrument they
 * are choosing; this one tells them who they are paying, because the instrument is chosen
 * on the next page.
 *
 * Position 4 puts it after the three explicit methods. A shop that would rather lead with
 * it can reorder in the admin — position is only the value this plugin installs with, and
 * the installer does not rewrite it on update.
 */
final class Paywall extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: TpayHandler::class,
            position: 4,
            technicalName: Methods::PAYWALL_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'Tpay',
                    description: 'Zapłać wybraną przez siebie metodą na stronie Tpay.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'Tpay',
                    description: 'Pay with the method of your choice on the Tpay page.',
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'Tpay',
                    description: 'Zahlen Sie mit der Methode Ihrer Wahl auf der Tpay-Seite.',
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'tpay',
        );
    }
}
