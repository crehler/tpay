<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Struct;

use Shopware\Core\Framework\Struct\Struct;

final class TpayCardFormStruct extends Struct
{
    public const API_ALIAS = 'cr_tpay_card_form';

    public function __construct(
        public readonly string $cardsApiPublicKey,
    ) {
    }

    public function getApiAlias(): string
    {
        return self::API_ALIAS;
    }
}
