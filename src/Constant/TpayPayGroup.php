<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Constant;

final class TpayPayGroup
{
    public const CARD = 103;
    public const BLIK = 150;
    public const GOOGLE_PAY = 166;
    public const APPLE_PAY = 170;
    public const VISA_MOBILE = 171;
    public const BLIK_RECURRING = 177;

    /**
     * @var int[]
     */
    public const EXCLUDED_FROM_BANK = [
        self::CARD,
        self::BLIK,
        self::GOOGLE_PAY,
        self::APPLE_PAY,
        self::VISA_MOBILE,
        self::BLIK_RECURRING,
    ];
}
