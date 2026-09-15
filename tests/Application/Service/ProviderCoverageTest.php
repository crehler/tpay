<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Application\Service;

use Crehler\PaymentBundle\Infrastructure\Handler\AbstractPaymentMethodHandler;
use Crehler\PaymentBundle\Infrastructure\Util\Lifecycle\ShopwarePaymentMethod;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Application\Service\{TpayGatewayDetailsProvider, TpayPaymentStatusProvider};
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Crehler\Tpay\Refund\TpayRefundApiClient;
use Crehler\Tpay\Services\TpayConsentProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function basename;
use function class_exists;
use function glob;
use function is_subclass_of;
use function pathinfo;
use function sprintf;

use const PATHINFO_FILENAME;

/**
 * Three services answer "is this transaction mine?" from a hand-kept list of handler class
 * names, and a fourth from a list of technical names. Adding a payment method means adding
 * it to all four, and nothing makes you.
 *
 * Forgetting is silent and stays silent: the payment still works end to end, because the
 * webhook identifies Tpay by its JWS signature rather than by the method. What disappears is
 * everything around it — the admin order page reports no gateway transaction, the status
 * poll never asks, and the checkout drops the Tpay regulations the customer is supposed to
 * accept. So these are discovered rather than listed, and the lists are checked against what
 * actually ships.
 */
final class ProviderCoverageTest extends TestCase
{
    /**
     * @return array<string, array{class-string<AbstractPaymentMethodHandler>}>
     */
    public static function handlers(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../../../src/Handler/*.php') ?: [] as $file) {
            $class = 'Crehler\\Tpay\\Handler\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($class) && is_subclass_of($class, AbstractPaymentMethodHandler::class)) {
                $cases[basename($file)] = [$class];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function technicalNames(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../../../src/Infrastructure/Util/Install/*.php') ?: [] as $file) {
            $class = 'Crehler\\Tpay\\Infrastructure\\Util\\Install\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($class) && is_subclass_of($class, ShopwarePaymentMethod::class)) {
                $cases[basename($file)] = [(new $class())->technicalName];
            }
        }

        return $cases;
    }

    /**
     * @param class-string<AbstractPaymentMethodHandler> $handler
     */
    #[DataProvider('handlers')]
    public function testTheStatusProviderClaimsEveryHandler(string $handler): void
    {
        $provider = new TpayPaymentStatusProvider($this->clientFactory(), new EnhancedLogger(new NullLogger()));

        self::assertTrue(
            $provider->supports($this->transaction($handler)),
            sprintf('%s is missing from TpayPaymentStatusProvider — its status will never be polled', $handler),
        );
    }

    /**
     * @param class-string<AbstractPaymentMethodHandler> $handler
     */
    #[DataProvider('handlers')]
    public function testTheGatewayDetailsProviderClaimsEveryHandler(string $handler): void
    {
        $provider = new TpayGatewayDetailsProvider(
            $this->clientFactory(),
            new TpayRefundApiClient(new EnhancedLogger(new NullLogger())),
            new EnhancedLogger(new NullLogger()),
        );

        self::assertTrue(
            $provider->supports($this->transaction($handler)),
            sprintf('%s is missing from TpayGatewayDetailsProvider — the admin panel will report nothing', $handler),
        );
    }

    #[DataProvider('technicalNames')]
    public function testTheConsentProviderClaimsEveryMethod(string $technicalName): void
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('aa11111111111111111111111111111f');
        $paymentMethod->setTechnicalName($technicalName);

        self::assertTrue(
            (new TpayConsentProvider())->supportsPaymentMethod($paymentMethod),
            sprintf('%s is missing from TpayConsentProvider — checkout drops the Tpay regulations', $technicalName),
        );
    }

    /**
     * Both clients are final, so they are built rather than doubled. Neither is touched:
     * supports() is a list lookup and never reaches the gateway.
     */
    private function clientFactory(): TpayClientFactory
    {
        return new TpayClientFactory(
            $this->createStub(SystemConfigService::class),
            new ArrayAdapter(),
            new EnhancedLogger(new NullLogger()),
        );
    }

    private function transaction(string $handlerIdentifier): OrderTransactionEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('aa11111111111111111111111111111f');
        $paymentMethod->setHandlerIdentifier($handlerIdentifier);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('4d2f9a1c8e3b47569012ab34cd56ef78');
        $transaction->setPaymentMethod($paymentMethod);

        return $transaction;
    }
}
