<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Services;

use Crehler\PaymentBundle\Infrastructure\Port\ConsentProvider;
use Crehler\PaymentBundle\Infrastructure\Struct\ConsentStruct;
use Crehler\Tpay\Constant\Methods;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

use function in_array;
use function sprintf;

/**
 * Tpay consent provider.
 * Returns static consent text for Tpay payment methods (Tpay regulations).
 */
final readonly class TpayConsentProvider implements ConsentProvider
{
    private const TPAY_REGULATIONS_URL = 'https://tpay.com/user/assets/files_for_download/regulamin.pdf';

    public function supportsPaymentMethod(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return in_array($paymentMethodEntity->getTechnicalName(), [
            Methods::BLIK_NAME,
            Methods::BANK_NAME,
            Methods::CARD_NAME,
        ], true);
    }

    public function getConsent(PaymentMethodEntity $paymentMethodEntity, SalesChannelContext $context): ?ConsentStruct
    {
        return new ConsentStruct(
            content: sprintf(
                'Akceptuję postanowienia <a href="%s" target="_blank" rel="noopener noreferrer">regulaminu Tpay</a> '
                . '(płatność obsługuje Krajowy Integrator Płatności S.A.).',
                self::TPAY_REGULATIONS_URL,
            ),
            locale: 'pl-PL',
            title: 'Tpay',
        );
    }
}
