<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;
use function preg_match;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The plugin must require the payment bundle of its own minor line, pinned — 6.6.* for a
 * 6.6.x plugin, never >= and never ^.
 *
 * An open constraint is what broke the public 6.2.3: it said `^6.0 >=6.2.0`, so Composer
 * happily resolved bundle 6.5.0, which had turned paymentType() abstract and dropped
 * subMethodsEnabled from ShopwarePaymentMethod. Every install and every update of that
 * plugin version fataled — not on a rare path, but at class-load time, for the whole shop.
 *
 * The bundle carries the handler contract, and that contract changes on minor releases. So
 * "the bundle we were built against" is the only version this plugin can honestly claim to
 * work with, and the minor is the unit that changes. Pinning also forces the whole family
 * onto one line: two plugins pinned to different minors cannot be installed together,
 * which is the intended outcome — mixing them is what produces the fatals.
 *
 * A release therefore bumps the plugin, the bundle and this constraint together.
 */
final class BundleConstraintTest extends TestCase
{
    public function testTheBundleIsPinnedToThePluginsOwnMinorLine(): void
    {
        $composer = $this->composer();

        $version = $composer['version'] ?? null;
        self::assertIsString($version, 'composer.json must declare a version');
        self::assertSame(
            1,
            preg_match('/^(\d+)\.(\d+)\.\d+$/', $version, $parts),
            sprintf('Version "%s" is not x.y.z', $version)
        );

        $constraint = $composer['require']['crehler/payment-bundle'] ?? null;
        self::assertIsString($constraint, 'composer.json must require crehler/payment-bundle');

        self::assertSame(
            sprintf('%s.%s.*', $parts[1], $parts[2]),
            $constraint,
            sprintf(
                'Plugin %s must require crehler/payment-bundle "%s.%s.*", not "%s". '
                . 'An open constraint lets Composer pull a bundle whose handler contract this '
                . 'plugin was never built against, which fatals the shop at class-load time.',
                $version,
                $parts[1],
                $parts[2],
                $constraint
            )
        );
    }

    /**
     * Guards the shape as well as the value: ">=", "^" and "~" all reintroduce the hole
     * even when the numbers happen to look right today.
     */
    public function testTheConstraintCarriesNoOpenRangeOperator(): void
    {
        $constraint = $this->composer()['require']['crehler/payment-bundle'] ?? '';

        self::assertSame(
            1,
            preg_match('/^\d+\.\d+\.\*$/', (string) $constraint),
            sprintf('Constraint "%s" must be exactly x.y.*', $constraint)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $raw = file_get_contents(__DIR__ . '/../composer.json');
        self::assertIsString($raw, 'composer.json is unreadable');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
