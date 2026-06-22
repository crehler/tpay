<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Factory;

use Crehler\Tpay\Factory\TpayTransactionPayloadFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TpayTransactionPayloadFactoryTest extends TestCase
{
    #[DataProvider('phoneProvider')]
    public function testNormalizePhoneStripsPolishPrefix(string $input, string $expected): void
    {
        $factory = new TpayTransactionPayloadFactory();
        $method = new ReflectionMethod($factory, 'normalizePhone');

        self::assertSame($expected, $method->invoke($factory, $input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function phoneProvider(): array
    {
        return [
            'plus 48 with spaces' => ['+48 555 666 444', '555666444'],
            'plus 48 no spaces' => ['+48555666444', '555666444'],
            'double zero 48 prefix' => ['0048 555 666 444', '555666444'],
            'bare 48 prefix' => ['48555666444', '555666444'],
            'mixed separators' => ['+48 (555) 666-444', '555666444'],
            'plain 9 digits unchanged' => ['555666444', '555666444'],
            'with dashes only' => ['555-666-444', '555666444'],
            'empty input' => ['', ''],
            'foreign +49 untouched' => ['+49 30 1234567', '49301234567'],
            'too short to strip' => ['48', '48'],
            'wrong length after 48' => ['4855566644', '4855566644'],
        ];
    }
}
