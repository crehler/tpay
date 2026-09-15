<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Domain\Enum\PaymentType;
use Crehler\PaymentBundle\Infrastructure\Handler\AbstractPaymentMethodHandler;
use Crehler\PaymentBundle\Infrastructure\Util\Lifecycle\ShopwarePaymentMethod;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Constant\Methods;
use Crehler\Tpay\Handler\{BankHandler, BlikHandler, CardHandler, TpayHandler};
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Crehler\Tpay\Infrastructure\Util\Install\Paywall;
use Crehler\Tpay\Services\TpayPaymentSubMethodsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function array_map;
use function array_unique;
use function basename;
use function class_exists;
use function count;
use function glob;
use function is_file;
use function is_subclass_of;
use function pathinfo;
use function sprintf;

use const PATHINFO_FILENAME;

/**
 * These classes are the ones PaymentMethodClassLocator instantiates, and it does so on
 * install, update, activate AND deactivate. When the bundle dropped the subMethodsEnabled
 * parameter and this plugin kept passing it, every one of those four operations died with
 * "Unknown named parameter" — a hard fatal on a plugin that was already active. Constructing
 * each class is the whole check, and it is the check that was missing.
 */
final class PaymentMethodDeclarationTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function installClasses(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../../../../src/Infrastructure/Util/Install/*.php') ?: [] as $file) {
            $class = 'Crehler\\Tpay\\Infrastructure\\Util\\Install\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($class) && is_subclass_of($class, ShopwarePaymentMethod::class)) {
                $cases[basename($file)] = [$class];
            }
        }

        return $cases;
    }

    /**
     * @param class-string<ShopwarePaymentMethod> $class
     */
    #[DataProvider('installClasses')]
    public function testTheLifecycleCanConstructTheDeclaration(string $class): void
    {
        $method = new $class();

        self::assertNotSame('', $method->technicalName);
        self::assertTrue(
            class_exists($method->handlerIdentifier),
            sprintf('%s names a handler that does not exist: %s', $class, $method->handlerIdentifier),
        );
        self::assertTrue(
            is_subclass_of($method->handlerIdentifier, AbstractPaymentMethodHandler::class),
            sprintf('%s names a handler that is not a payment handler', $class),
        );
    }

    /**
     * An iconName the installer cannot resolve leaves the method with no image at all, and
     * nothing in the install path complains about it.
     *
     * @param class-string<ShopwarePaymentMethod> $class
     */
    #[DataProvider('installClasses')]
    public function testTheDeclaredIconExists(string $class): void
    {
        $method = new $class();

        if ($method->iconName === null) {
            self::markTestSkipped($class . ' declares no icon');
        }

        $iconDir = __DIR__ . '/../../../../src/Resources/icons/';
        $found = false;
        foreach (['png', 'svg', 'jpg', 'jpeg', 'webp'] as $extension) {
            if (is_file($iconDir . pathinfo($method->iconName, PATHINFO_FILENAME) . '.' . $extension)) {
                $found = true;

                break;
            }
        }

        self::assertTrue($found, sprintf('%s declares icon "%s" but no such file exists', $class, $method->iconName));
    }

    public function testTechnicalNamesAreUnique(): void
    {
        $names = array_map(static fn (array $case) => (new $case[0]())->technicalName, self::installClasses());

        self::assertCount(count($names), array_unique($names), 'two payment methods share a technical name');
    }

    /**
     * The contract the storefront reads off each handler. usesGatewayChannels drives the
     * channel widget, so a wrong answer here is not a type error — it is a missing bank list
     * on checkout, or an empty picker on a method that has nothing to pick.
     *
     * requiresGatewayChannel stays false across all three: Tpay accepts channelId = 0 and
     * falls back to its own bank list, which is what BankHandler sends when the customer
     * picked nothing.
     */
    public function testEachHandlerDeclaresItsFamily(): void
    {
        self::assertSame(PaymentType::PAY_BY_LINK, BankHandler::paymentType());
        self::assertTrue(BankHandler::usesGatewayChannels());

        self::assertSame(PaymentType::BLIK, BlikHandler::paymentType());
        self::assertFalse(BlikHandler::usesGatewayChannels());

        self::assertSame(PaymentType::CARD, CardHandler::paymentType());
        self::assertFalse(CardHandler::usesGatewayChannels());

        self::assertSame(PaymentType::PAYWALL, TpayHandler::paymentType());
        self::assertFalse(TpayHandler::usesGatewayChannels());

        foreach ([BankHandler::class, BlikHandler::class, CardHandler::class, TpayHandler::class] as $handler) {
            self::assertFalse($handler::requiresGatewayChannel(), $handler . ' would block the order button');
        }
    }

    /**
     * The paywall method is the one declaration in this plugin whose type is load-bearing
     * rather than descriptive. PAY_BY_LINK here would have PaymentSubMethodAdapter hand it
     * the bank list from TpayPaymentSubMethodsService — which matches on type alone and
     * never sees usesGatewayChannels — for a method that sends no channel to the gateway.
     */
    public function testThePaywallMethodIsNotServedChannelsByOurOwnProvider(): void
    {
        $paywall = new Paywall();

        self::assertSame(Methods::PAYWALL_NAME, $paywall->technicalName);
        self::assertSame(TpayHandler::class, $paywall->handlerIdentifier);
        self::assertNotContains(
            TpayHandler::paymentType(),
            (new TpayPaymentSubMethodsService(
                new TpayClientFactory(
                    self::createStub(SystemConfigService::class),
                    new ArrayAdapter(),
                    new EnhancedLogger(new NullLogger()),
                ),
                new EnhancedLogger(new NullLogger()),
            ))->supportedPaymentTypes(),
        );
    }
}
