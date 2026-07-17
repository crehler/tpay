<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Refund;

use Crehler\Tpay\Refund\SyncOutcome;
use Crehler\Tpay\Refund\TpayRefundReconciliationProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shopware\Core\Framework\Context;

/**
 * The provider's collaborators (TpayClientFactory, TpayRefundApiClient, CaptureManager,
 * RefundSynchronizer) are final concrete classes with no interfaces, matching the rest of
 * this bundle's convention (see TpayRefundProviderTest) — the full reconcile()/syncRow()
 * orchestration that touches them stays covered by manual/E2E verification against the Tpay
 * sandbox (WT-905 plan). What we CAN assert without a sandbox are the pure "skip by rule"
 * branches of syncRow(): they return before touching any collaborator, and getting them
 * right is exactly what keeps routine cancels out of the report's unmatched bucket. The
 * status-mapping logic moved to TpayRefundStatusMapper (see TpayRefundStatusMapperTest).
 */
final class TpayRefundReconciliationProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('skippedRowProvider')]
    public function testSyncRowSkipsRowsIgnoredByRule(array $row): void
    {
        $provider = (new ReflectionClass(TpayRefundReconciliationProvider::class))->newInstanceWithoutConstructor();
        $syncRow = (new ReflectionClass($provider))->getMethod('syncRow');

        // A skipped row is not a mismatch, so reconcileAccount() must not count it as
        // unmatched — otherwise RefundReconciliationReport is flooded with normal cancels.
        self::assertSame(SyncOutcome::SKIPPED, $syncRow->invoke($provider, $row, Context::createDefaultContext()));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function skippedRowProvider(): array
    {
        return [
            'cancel is intentionally skipped' => [['status' => 'cancel', 'amount' => 12.5, 'transactionId' => 'tr_1']],
            'cancel wins over amount/transaction' => [['status' => 'CANCEL ', 'amount' => 0.0]],
            'zero amount is skipped' => [['status' => 'done', 'amount' => 0.0, 'transactionId' => 'tr_1']],
            'negative amount is skipped' => [['status' => 'done', 'amount' => -5.0, 'transactionId' => 'tr_1']],
            'missing amount is skipped' => [['status' => 'done', 'transactionId' => 'tr_1']],
        ];
    }
}
