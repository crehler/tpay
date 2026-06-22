<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Enums;

/**
 * Transaction `status` values returned by the Tpay Open API (REST).
 *
 * Distinct from {@see TpayTransactionStatus} which represents `tr_status`
 * values posted by the legacy notification webhook (TRUE/PAID/CHARGEBACK).
 */
enum TpayApiTransactionStatus: string
{
    case CORRECT = 'correct';
    case PAID = 'paid';
    case PENDING = 'pending';
    case NEW = 'new';
    case ERROR = 'error';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case CHARGEBACK = 'chargeback';
    case REFUND = 'refund';
}
