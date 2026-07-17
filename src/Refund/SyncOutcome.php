<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Refund;

/**
 * Outcome of TpayRefundReconciliationProvider::syncRow(), so reconcileAccount() can keep
 * refunds intentionally skipped by rule out of the "unmatched" bucket that
 * RefundReconciliationReport uses to surface genuinely lost panel refunds. Without this
 * distinction the report is flooded with routine cancels and masks real orphans (WT-905).
 */
enum SyncOutcome
{
    // Refund recorded in Shopware.
    case SYNCED;
    // Intentionally ignored by rule (cancel / non-positive amount / missing transactionId) — not a mismatch.
    case SKIPPED;
    // No matching Shopware order transaction — a real, actionable mismatch.
    case UNMATCHED;
    // Matched a transaction, but RefundSynchronizer could not record it.
    case FAILED;
}
