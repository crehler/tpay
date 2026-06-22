<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Constant;

final class CustomFields
{
    public const TPAY_TRANSACTION_ID = 'crehler_tpay_transaction_id';
    public const TPAY_REFUNDED_AMOUNT = 'crehler_tpay_refunded_amount';
    public const TPAY_REFUND_ID = 'crehler_tpay_refund_id';
    public const TPAY_LAST_REFUND_REQUEST_ID = 'crehler_tpay_last_refund_request_id';
    public const TPAY_LAST_REFUND_STATUS = 'crehler_tpay_last_refund_status';
    public const TPAY_LAST_REFUND_AMOUNT = 'crehler_tpay_last_refund_amount';
    public const TPAY_LAST_REFUND_CREATED_AT = 'crehler_tpay_last_refund_created_at';
}
