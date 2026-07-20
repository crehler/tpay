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
            'done', 'processed', 'blik_processed' => 'completed',
            // A refund is marked "completed" only for the statuses we explicitly know are
            // terminal-success. An unknown or newly-introduced Tpay status must never be
            // silently recorded as a completed refund (that would tell Shopware the money
            // was returned when it may not have been) — fall back to the non-terminal
            // in_progress so the hourly reconciliation re-checks it once Tpay settles (WT-905).
            default => 'in_progress',
        };
    }

    /**
     * RefundStatus the reconciliation task records via RefundSynchronizer, derived from
     * the same grouping as level() so both callers stay consistent.
     */
    public static function refundStatus(string $tpayStatus): RefundStatus
    {
        return match (self::level($tpayStatus)) {
            'completed' => RefundStatus::COMPLETED,
            // 'cancelled' is unreachable here (reconciliation skips cancel rows before
            // mapping), but map it defensively to FAILED so a cancel is never recorded
            // as a completed refund should this ever be called with one.
            'failed', 'cancelled' => RefundStatus::FAILED,
            // Everything else (including level()'s in_progress fallback for unknown Tpay
            // statuses) stays non-terminal; only an explicit "completed" level yields a
            // COMPLETED refund, so a future status can never silently settle as completed.
            default => RefundStatus::IN_PROGRESS,
        };
    }
}
