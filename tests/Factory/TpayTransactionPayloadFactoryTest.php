<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Factory;

use Crehler\PaymentBundle\Application\Service\TransactionDescriptionRenderer;
use Crehler\PaymentBundle\Domain\Entity\Customer;
use Crehler\PaymentBundle\Domain\Entity\Order\{BillingAddress, Order};
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\{OrderTransaction, PaymentMethod, PaymentStatus};
use Crehler\PaymentBundle\Domain\ValueObjects\Money;
use Crehler\Tpay\Factory\TpayTransactionPayloadFactory;
use Crehler\Tpay\Handler\BankHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function str_repeat;
use function strlen;

final class TpayTransactionPayloadFactoryTest extends TestCase
{
    private const ORDER_ID = 'e7cd0a2b4f1e4c8ba9d6f30512c7bb41';
    private const TRANSACTION_ID = '4d2f9a1c8e3b47569012ab34cd56ef78';

    #[DataProvider('phoneProvider')]
    public function testNormalizePhoneStripsPolishPrefix(string $input, string $expected): void
    {
        $factory = new TpayTransactionPayloadFactory($this->renderer(''));
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

    /**
     * The configured description carries the order id(s) that integrations map payments by.
     */
    public function testDescriptionCarriesTheRenderedTemplate(): void
    {
        $payload = $this->basePayload('74449 ' . self::ORDER_ID);

        self::assertSame('74449 ' . self::ORDER_ID, $payload['description']);
    }

    /**
     * Regression guard: hiddenDescription is the tr_crc key TpayNotificationSubscriber matches
     * notifications on. It is the only "clean" machine-readable field in the payload, which makes
     * it a tempting place to put an order id — doing so would silently break payment booking.
     */
    public function testHiddenDescriptionStaysTheOrderTransactionId(): void
    {
        $payload = $this->basePayload('74449 ' . self::ORDER_ID);

        self::assertSame(self::TRANSACTION_ID, $payload['hiddenDescription']);
    }

    /**
     * The factory must hand the gateway limit to the renderer — Tpay validates the field with
     * strlen(), so an over-long description fails the whole transaction.
     */
    public function testDescriptionIsCappedAtTheGatewayByteLimit(): void
    {
        $payload = $this->basePayload(str_repeat('x', 200));

        self::assertSame(128, strlen($payload['description']));
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(string $renderedDescription): array
    {
        $factory = new TpayTransactionPayloadFactory($this->renderer($renderedDescription));

        return $factory->createBasePayload(
            $this->orderTransaction(),
            'https://example.test/return',
            'https://example.test/notify',
        );
    }

    /**
     * Real renderer over a stubbed config: keeps the byte-limit contract between the two classes
     * under test instead of asserting against a mocked return value.
     */
    private function renderer(string $template): TransactionDescriptionRenderer
    {
        $systemConfigService = $this->createStub(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturn($template);

        return new TransactionDescriptionRenderer(
            $systemConfigService,
            $this->createStub(EntityRepository::class),
            new EventDispatcher(),
            new NullLogger(),
        );
    }

    private function orderTransaction(): OrderTransaction
    {
        $order = new Order(
            id: self::ORDER_ID,
            orderNumber: '74449',
            totalAmount: new Money(12300, 'PLN'),
            netAmount: new Money(10000, 'PLN'),
            shippingAmount: new Money(0, 'PLN'),
            currencyCode: 'PLN',
            customer: new Customer(
                id: 'c0ffee00000000000000000000000001',
                customerNumber: '10000',
                email: 'jan@example.com',
                firstName: 'Jan',
                lastName: 'Kowalski',
            ),
            billingAddress: new BillingAddress(
                id: 'b1111100000000000000000000000001',
                firstName: 'Jan',
                lastName: 'Kowalski',
                street: 'Testowa 1',
                city: 'Gdańsk',
                zipCode: '80-000',
                countryCode: 'PL',
                countryName: 'Polska',
            ),
            lineItems: [],
        );

        return new OrderTransaction(
            id: self::TRANSACTION_ID,
            paymentMethod: new PaymentMethod(
                id: 'aa11111111111111111111111111111f',
                handlerIdentifier: BankHandler::class,
                active: true,
                technicalName: 'payment_crehler_tpay_bank',
            ),
            paymentStatus: new PaymentStatus(
                stateId: 'bb11111111111111111111111111111f',
                name: 'open',
            ),
            totalAmount: new Money(12300, 'PLN'),
            order: $order,
        );
    }
}
