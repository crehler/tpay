<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Refund;

use Crehler\PaymentBundle\Domain\ValueObjects\RefundStatus;
use Crehler\Tpay\Refund\TpayRefundStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single Tpay-status grouping shared by TpayGatewayDetailsProvider (string level)
 * and TpayRefundReconciliationProvider (RefundStatus enum). Both representations are
 * asserted here so a change to the grouping can never silently diverge between the live
 * "Szczegóły" readout and the reconciliation task.
 */
final class TpayRefundStatusMapperTest extends TestCase
{
    #[DataProvider('levelProvider')]
    public function testLevel(string $tpayStatus, string $expected): void
    {
        self::assertSame($expected, TpayRefundStatusMapper::level($tpayStatus));
    }

    #[DataProvider('refundStatusProvider')]
    public function testRefundStatus(string $tpayStatus, RefundStatus $expected): void
    {
        self::assertSame($expected, TpayRefundStatusMapper::refundStatus($tpayStatus));
    }

    public function testMapperNormalizesCasingAndWhitespace(): void
    {
        self::assertSame('in_progress', TpayRefundStatusMapper::level('  PENDING '));
        self::assertSame(RefundStatus::IN_PROGRESS, TpayRefundStatusMapper::refundStatus('  PENDING '));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function levelProvider(): array
    {
        return [
            'cancel' => ['cancel', 'cancelled'],
            'pending' => ['pending', 'in_progress'],
            'new' => ['new', 'in_progress'],
            'hold' => ['hold', 'in_progress'],
            'blik pending' => ['blik_pending', 'in_progress'],
            'blik incorrect' => ['blik_incorrect', 'failed'],
            'done' => ['done', 'completed'],
            'processed' => ['processed', 'completed'],
            'blik processed' => ['blik_processed', 'completed'],
            // Unknown / newly-introduced statuses must fall back to the non-terminal
            // in_progress, never be silently grouped as a completed refund (WT-905).
            'unknown status defaults to in_progress' => ['something_else', 'in_progress'],
        ];
    }

    /**
     * @return array<string, array{string, RefundStatus}>
     */
    public static function refundStatusProvider(): array
    {
        return [
            // Defensive: RefundStatus has no CANCELLED case, so the 'cancelled' level
            // must fall back to FAILED rather than being silently treated as COMPLETED.
            'cancel maps defensively to failed' => ['cancel', RefundStatus::FAILED],
            'pending' => ['pending', RefundStatus::IN_PROGRESS],
            'new' => ['new', RefundStatus::IN_PROGRESS],
            'hold' => ['hold', RefundStatus::IN_PROGRESS],
            'blik pending' => ['blik_pending', RefundStatus::IN_PROGRESS],
            'blik incorrect' => ['blik_incorrect', RefundStatus::FAILED],
            'done' => ['done', RefundStatus::COMPLETED],
            'processed' => ['processed', RefundStatus::COMPLETED],
            'blik processed' => ['blik_processed', RefundStatus::COMPLETED],
            // Unknown / newly-introduced statuses must fall back to IN_PROGRESS, never be
            // silently recorded as a COMPLETED refund (WT-905).
            'unknown status defaults to in_progress' => ['something_else', RefundStatus::IN_PROGRESS],
        ];
    }
}
