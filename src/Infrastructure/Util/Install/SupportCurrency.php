<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Domain\Port\PaymentGatewayCurrencyProviderInterface;
use Crehler\Tpay\Constant\Methods;

final readonly class SupportCurrency implements PaymentGatewayCurrencyProviderInterface
{
    /**
     * @var string
     */
    private const ID = '0190593661c6753bb92a998a89c0fc60';

    public function getGatewayIdentifier(): string
    {
        return Methods::NAME;
    }

    public function getRuleId(): string
    {
        return self::ID;
    }

    public function getSupportedCurrencyIsoCodes(): array
    {
        return [
            'PLN',
        ];
    }

    public function getTranslations(): array
    {
        return [
            'en-GB' => [
                'name' => 'Tpay - Supported currencies',
                'description' => 'This rule was automatically added by the Tpay payment plugin. It represents all currencies supported by Tpay and should be assigned to Tpay payment methods.',
            ],
            'pl-PL' => [
                'name' => 'Tpay - Obsługiwane waluty',
                'description' => 'Ta reguła została automatycznie dodana przez wtyczkę płatności Tpay. Reprezentuje wszystkie waluty obsługiwane przez Tpay i powinna być przypisana do metod płatności Tpay.',
            ],
            'de-DE' => [
                'name' => 'Tpay - Unterstutzte Wahrungen',
                'description' => 'Diese Regel wurde automatisch vom Tpay-Zahlungs-Plugin hinzugefugt. Sie reprasentiert alle von Tpay unterstutzten Wahrungen.',
            ],
        ];
    }
}
