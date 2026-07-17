<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Refund;

use Crehler\PaymentBundle\Domain\ValueObjects\RefundStatus;

use function strtolower;
use function trim;

/**
 * Single source of truth for grouping a raw Tpay refund status into a normalized
 * level. Both the live "Szczegóły" readout (TpayGatewayDetailsProvider, which needs
 * the string level of GatewayRefundSummary::statusLevel) and the hourly reconciliation
 * task (TpayRefundReconciliationProvider, which needs a RefundStatus enum) derive from
 * level() here, so the two views can never disagree about the same refund when Tpay
 * adds or renames a status (WT-905).
 */
final class TpayRefundStatusMapper
{
    /**
     * Normalized level string as stored on GatewayRefundSummary::statusLevel.
     */
    public static function level(string $tpayStatus): string
    {
        return match (strtolower(trim($tpayStatus))) {
            'cancel' => 'cancelled',
            'pending', 'new', 'hold', 'blik_pending' => 'in_progress',
            'blik_incorrect' => 'failed',
            default => 'completed', // done, processed, blik_processed
        };
    }

    /**
     * RefundStatus the reconciliation task records via RefundSynchronizer, derived from
     * the same grouping as level() so both callers stay consistent.
     */
    public static function refundStatus(string $tpayStatus): RefundStatus
    {
        return match (self::level($tpayStatus)) {
            'in_progress' => RefundStatus::IN_PROGRESS,
            // 'cancelled' is unreachable here (reconciliation skips cancel rows before
            // mapping), but map it defensively to FAILED so a cancel is never recorded
            // as a completed refund should this ever be called with one.
            'failed', 'cancelled' => RefundStatus::FAILED,
            default => RefundStatus::COMPLETED,
        };
    }
}
