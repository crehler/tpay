<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Exception;

use RuntimeException;

final class TpayConfigurationException extends RuntimeException
{
    public static function missingCredentials(): self
    {
        return new self('Tpay Client ID or Client Secret is not configured. Please check plugin settings.');
    }
}
