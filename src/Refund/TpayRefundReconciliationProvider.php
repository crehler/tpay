<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Refund;

use Crehler\PaymentBundle\Application\DTO\RefundReconciliation\RefundReconciliationReport;
use Crehler\PaymentBundle\Application\Port\Driven\{OrderTransactionRepositoryInterface, RefundReconciliationProviderPort};
use Crehler\PaymentBundle\Application\Service\{CaptureManager, RefundSynchronizer};
use Crehler\PaymentBundle\Domain\Exception\GatewayConfigurationException;
use Crehler\PaymentBundle\Infrastructure\Configuration\RefundReconciliationCursorStore;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use DateInterval;
use DateTimeImmutable;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function count;
use function round;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Detects refunds created directly in the Tpay panel that never reached Shopware
 * through a webhook — Tpay only sends a webhook (CHARGEBACK) for a FULL panel refund;
 * a partial one is silent regardless of payment method (WT-905, Bug 2).
 */
final class TpayRefundReconciliationProvider implements RefundReconciliationProviderPort
{
    private const PROVIDER_ID = 'tpay';
    private const PAGE_LIMIT = 100;
    // Margin against clock drift / window-boundary refunds landing just before the cursor.
    private const OVERLAP_SECONDS = 300;
    // No cursor yet (first run for this account) — look back this far.
    private const INITIAL_LOOKBACK = 'P1D';

    public function __construct(
        #[Autowire(service: 'sales_channel.repository')]
        private readonly EntityRepository $salesChannelRepository,
        private readonly TpayClientFactory $tpayClientFactory,
        private readonly TpayRefundApiClient $apiClient,
        private readonly RefundReconciliationCursorStore $cursorStore,
        private readonly OrderTransactionRepositoryInterface $orderTransactionRepository,
        private readonly CaptureManager $captureManager,
        private readonly RefundSynchronizer $refundSynchronizer,
        private readonly EnhancedLogger $logger,
    ) {
    }

    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }

    public function reconcile(Context $context): RefundReconciliationReport
    {
        $accounts = $this->resolveDistinctAccounts($context);

        $accountsChecked = 0;
        $seen = 0;
        $synced = 0;
        $unmatched = 0;
        $errors = [];

        foreach ($accounts as $accountIdentity => $salesChannelId) {
            ++$accountsChecked;

            try {
                [$accountSeen, $accountSynced, $accountUnmatched] = $this->reconcileAccount($accountIdentity, $salesChannelId, $context);
                $seen += $accountSeen;
                $synced += $accountSynced;
                $unmatched += $accountUnmatched;
            } catch (Throwable $e) {
                $errors[] = sprintf('%s: %s', $accountIdentity, $e->getMessage());
                $this->logger->error('Tpay refund reconciliation: account failed', [
                    'account' => $accountIdentity,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return new RefundReconciliationReport(self::PROVIDER_ID, $accountsChecked, $seen, $synced, $unmatched, $errors);
    }

    /**
     * @return array<string, ?string> accountIdentity => a representative salesChannelId
     */
    private function resolveDistinctAccounts(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $salesChannelIds = $this->salesChannelRepository->searchIds($criteria, $context)->getIds();

        $accounts = [];

        foreach ($salesChannelIds as $salesChannelId) {
            try {
                $identity = $this->tpayClientFactory->resolveAccountIdentity((string) $salesChannelId);
            } catch (GatewayConfigurationException) {
                continue; // this channel has no Tpay configured
            }

            // Dedup: sales channels sharing one Tpay account query /refunds only once.
            $accounts[$identity] ??= (string) $salesChannelId;
        }

        return $accounts;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [refundsSeen, refundsSynced, unmatchedRefunds]
     */
    private function reconcileAccount(string $accountIdentity, ?string $salesChannelId, Context $context): array
    {
        $tpay = $this->tpayClientFactory->create($salesChannelId);

        $since = $this->cursorStore->getLastSyncedAt(self::PROVIDER_ID, $accountIdentity)
            ?? (new DateTimeImmutable())->sub(new DateInterval(self::INITIAL_LOOKBACK));
        $from = $since->modify(sprintf('-%d seconds', self::OVERLAP_SECONDS));
        $to = new DateTimeImmutable();

        $seen = 0;
        $synced = 0;
        $unmatched = 0;
        $page = 1;

        do {
            $rows = $this->apiClient->listRefundsSince($tpay, $from, $to, $page, self::PAGE_LIMIT);

            foreach ($rows as $row) {
                ++$seen;

                switch ($this->syncRow($row, $context)) {
                    case SyncOutcome::SYNCED:
                        ++$synced;
                        break;
                    case SyncOutcome::UNMATCHED:
                    case SyncOutcome::FAILED:
                        // Both are genuine problems the report must surface: an orphan
                        // refund with no Shopware transaction, or a matched refund that
                        // could not be recorded.
                        ++$unmatched;
                        break;
                    case SyncOutcome::SKIPPED:
                        // Intentionally ignored (cancel / non-positive amount / missing
                        // transactionId) — counted in $seen only, never as a mismatch.
                        break;
                }
            }

            ++$page;
        } while (count($rows) === self::PAGE_LIMIT);

        $this->cursorStore->setLastSyncedAt(self::PROVIDER_ID, $accountIdentity, $to);

        return [$seen, $synced, $unmatched];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function syncRow(array $row, Context $context): SyncOutcome
    {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === 'cancel') {
            return SyncOutcome::SKIPPED;
        }

        $amount = (float) ($row['amount'] ?? 0.0);
        if ($amount <= 0.0) {
            return SyncOutcome::SKIPPED;
        }

        $tpayTransactionId = $row['transactionId'] ?? $row['transaction_id'] ?? null;
        if ($tpayTransactionId === null || $tpayTransactionId === '') {
            $this->logger->warning('Tpay refund reconciliation: row without transactionId, skipping', ['row' => $row]);

            return SyncOutcome::SKIPPED;
        }

        $orderTransaction = $this->orderTransactionRepository->findByGatewayPaymentId((string) $tpayTransactionId, $context);
        if ($orderTransaction === null) {
            $this->logger->info('Tpay refund reconciliation: no matching order transaction for refund', [
                'tpayTransactionId' => $tpayTransactionId,
            ]);

            return SyncOutcome::UNMATCHED;
        }

        $this->captureManager->ensureCapture($orderTransaction->getId(), $context);

        $gatewayRefundId = (string) ($row['refundId'] ?? $row['requestId'] ?? ('tpay-recon-' . $tpayTransactionId . '-' . $amount));

        $refundId = $this->refundSynchronizer->syncExternalRefund(
            orderTransactionId: $orderTransaction->getId(),
            amountMinor: (int) round($amount * 100),
            gatewayRefundId: $gatewayRefundId,
            status: TpayRefundStatusMapper::refundStatus($status),
            context: $context,
        );

        return $refundId !== null ? SyncOutcome::SYNCED : SyncOutcome::FAILED;
    }
}
