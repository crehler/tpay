<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Provider;

use Crehler\PaymentBundle\Application\Port\Driven\CardFormTemplateProviderPort;
use Crehler\Tpay\Handler\CardHandler;

use function in_array;

/**
 * Points the bundle at Tpay's card form template.
 *
 * Replaces the previous payment-form.html.twig block override, which could not work: its
 * path collided with the bundle's and the Storefront's, so Shopware flattened the
 * inheritance chain and the override never reached the output (see
 * CardFormTemplateProviderPort). The template itself is unchanged and still shared with
 * the inline render context in payment-method.html.twig — it defaults showHeader to false,
 * which is exactly what the embedded context needs.
 */
final class TpayCardFormTemplateProvider implements CardFormTemplateProviderPort
{
    /**
     * @var mixed[]
     */
    private const SUPPORTED_HANDLERS = [
        CardHandler::class,
    ];

    public function supports(string $handlerIdentifier): bool
    {
        return in_array($handlerIdentifier, self::SUPPORTED_HANDLERS, true);
    }

    public function getTemplate(): string
    {
        return '@CrehlerTpay/storefront/component/payment/card-form.html.twig';
    }
}
