<?php

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

declare(strict_types=1);

namespace Crehler\Tpay\Tests\Refund;

use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Refund\TpayRefundApiClient;
use PHPUnit\Framework\TestCase;

final class TpayRefundApiClientTest extends TestCase
{
    public function testGetAlreadyRefundedSumsNonCancelledRefunds(): void
    {
        $tpay = $this->makeTpay([
            'refunds' => [
                ['amount' => 10.00, 'status' => 'done'],
                ['amount' => 5.50, 'status' => 'refunded'],
                ['amount' => 99.99, 'status' => 'cancel'], // excluded from the total
            ],
        ]);

        self::assertSame(15.5, $this->client()->getAlreadyRefunded($tpay, 'TR123', 'title'));
    }

    public function testListRefundsReadsRefundsListKey(): void
    {
        $tpay = $this->makeTpay([
            'refundsList' => [
                ['amount' => 1.0, 'status' => 'done'],
            ],
        ]);

        $refunds = $this->client()->listRefunds($tpay, 'TR123', null);

        self::assertCount(1, $refunds);
        self::assertSame(1.0, $refunds[0]['amount']);
    }

    public function testListRefundsFallsBackToGlobalListWhenTransactionListEmpty(): void
    {
        $tpay = $this->makeTpay(
            ['refunds' => []],
            globalRefunds: [
                ['amount' => 7.0, 'status' => 'done', 'transactionId' => 'TR123'],
                ['amount' => 3.0, 'status' => 'done', 'transactionId' => 'OTHER'],
            ],
        );

        $refunds = $this->client()->listRefunds($tpay, 'TR123', null);

        self::assertCount(1, $refunds);
        self::assertSame(7.0, $refunds[0]['amount']);
    }

    public function testIsCountableStatus(): void
    {
        $client = $this->client();

        self::assertTrue($client->isCountableStatus('done'));
        self::assertTrue($client->isCountableStatus(''));
        self::assertFalse($client->isCountableStatus('cancel'));
        self::assertFalse($client->isCountableStatus('CANCEL'));
    }

    private function client(): TpayRefundApiClient
    {
        return new TpayRefundApiClient($this->createMock(EnhancedLogger::class));
    }

    /**
     * @param array<string, mixed>            $byTransaction
     * @param array<int, array<string, mixed>> $globalRefunds
     */
    private function makeTpay(array $byTransaction, array $globalRefunds = []): object
    {
        $transactions = new class($byTransaction) {
            /** @param array<string, mixed> $byTransaction */
            public function __construct(private array $byTransaction)
            {
            }

            /** @return array<string, mixed> */
            public function getRefundsByTransactionId(string $id, array $opts): array
            {
                return $this->byTransaction;
            }

            /** @return array<string, mixed> */
            public function getTransactionById(string $id): array
            {
                return ['amount' => 100.0, 'title' => 'title'];
            }
        };

        $refunds = new class($globalRefunds) {
            /** @param array<int, array<string, mixed>> $globalRefunds */
            public function __construct(private array $globalRefunds)
            {
            }

            /** @return array<int, array<string, mixed>> */
            public function getRefunds(): array
            {
                return $this->globalRefunds;
            }
        };

        return new class($transactions, $refunds) {
            public function __construct(private object $transactions, private object $refunds)
            {
            }

            public function transactions(): object
            {
                return $this->transactions;
            }

            public function refunds(): object
            {
                return $this->refunds;
            }
        };
    }
}
