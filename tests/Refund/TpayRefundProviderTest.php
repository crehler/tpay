<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Refund;

use Crehler\Tpay\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\Tpay\Refund\TpayRefundProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TpayRefundProviderTest extends TestCase
{
    #[DataProvider('handlerProvider')]
    public function testSupportsMatchesTpayHandlers(string $handlerIdentifier, bool $expected): void
    {
        // supports() only reads the static handler list, so no collaborators are needed.
        $provider = (new ReflectionClass(TpayRefundProvider::class))->newInstanceWithoutConstructor();

        self::assertSame($expected, $provider->supports($handlerIdentifier));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function handlerProvider(): array
    {
        return [
            'card handler' => [CardHandler::class, true],
            'blik handler' => [BlikHandler::class, true],
            'bank handler' => [BankHandler::class, true],
            'foreign handler' => ['Crehler\\PayU\\Handler\\CardHandler', false],
            'arbitrary string' => ['not-a-handler', false],
        ];
    }
}
