<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Enums;

enum TpayTransactionStatus: string
{
    case TRUE = 'TRUE';
    case FALSE = 'FALSE';
    case CHARGEBACK = 'CHARGEBACK';
}
