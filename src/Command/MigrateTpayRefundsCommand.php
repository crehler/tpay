<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Command;

use Crehler\PaymentBundle\Application\Service\{CaptureManager, RefundSynchronizer};
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Domain\ValueObjects\RefundStatus;
use Crehler\Tpay\Constant\CustomFields;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Crehler\Tpay\Refund\TpayRefundApiClient;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\{EqualsFilter, MultiFilter, NotFilter};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function is_numeric;
use function round;
use function sprintf;
use function strtolower;
use function trim;

/**
 * One-off, idempotent migration of historical Tpay refunds stored in custom fields
 * into native refund entities. Reads the gateway as the source of truth (Tpay API)
 * and falls back to the legacy custom-field amount when the API returns nothing.
 *
 * Re-running is safe: refunds are deduplicated by gateway refund id (externalReference)
 * inside RefundSynchronizer.
 */
#[AsCommand(
    name: 'crehler:tpay:migrate-refunds',
    description: 'Migrate historical Tpay refunds from custom fields into native refund entities',
)]
final class MigrateTpayRefundsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'order_transaction.repository')]
        private readonly EntityRepository $orderTransactionRepository,
        private readonly TpayClientFactory $tpayClientFactory,
        private readonly TpayRefundApiClient $apiClient,
        private readonly CaptureManager $captureManager,
        private readonly RefundSynchronizer $refundSynchronizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $transactions = $this->loadRefundedTransactions($context);
        $io->title(sprintf('Migrating Tpay refunds for %d transaction(s)', $transactions->count()));

        $migrated = 0;
        $skipped = 0;

        /** @var OrderTransactionEntity $transaction */
        foreach ($transactions as $transaction) {
            $customFields = $transaction->getCustomFields() ?? [];
            $tpayTransactionId = $customFields[PaymentCustomFields::GATEWAY_PAYMENT_ID] ?? null;
            if ($tpayTransactionId === null) {
                ++$skipped;
                continue;
            }

            try {
                // Ensure the capture exists before synchronizing refunds onto it.
                $this->captureManager->ensureCapture($transaction->getId(), $context);

                $migrated += $this->migrateTransaction($transaction, (string) $tpayTransactionId, $customFields, $context);
            } catch (Throwable $e) {
                $io->warning(sprintf('Transaction %s: %s', $transaction->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf('Done. Refund entities ensured: %d, transactions skipped: %d', $migrated, $skipped));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function migrateTransaction(
        OrderTransactionEntity $transaction,
        string $tpayTransactionId,
        array $customFields,
        Context $context,
    ): int {
        $salesChannelId = $transaction->getOrder()?->getSalesChannelId();
        $tpay = $this->tpayClientFactory->create($salesChannelId);

        $title = $this->apiClient->getTransactionTitle($tpay, $tpayTransactionId);
        $refunds = $this->apiClient->listRefunds($tpay, $tpayTransactionId, $title);

        $count = 0;

        foreach ($refunds as $index => $refund) {
            $status = strtolower(trim((string) ($refund['status'] ?? '')));
            if ($status === 'cancel') {
                continue; // cancelled refunds are not carried over
            }

            $amount = (float) ($refund['amount'] ?? 0.0);
            if ($amount <= 0.0) {
                continue;
            }

            $gatewayRefundId = $refund['refundId'] ?? $refund['requestId'] ?? ('tpay-' . $tpayTransactionId . '-' . $index);

            $this->refundSynchronizer->syncExternalRefund(
                orderTransactionId: $transaction->getId(),
                amountMinor: (int) round($amount * 100),
                gatewayRefundId: (string) $gatewayRefundId,
                status: $this->mapStatus($status),
                context: $context,
            );
            ++$count;
        }

        // Legacy fallback: the API returned nothing but a refunded amount was recorded.
        if ($count === 0) {
            $legacyAmount = $customFields[CustomFields::TPAY_REFUNDED_AMOUNT]
                ?? $customFields[CustomFields::TPAY_LAST_REFUND_AMOUNT]
                ?? null;

            if (is_numeric($legacyAmount) && (float) $legacyAmount > 0.0) {
                $this->refundSynchronizer->syncExternalRefund(
                    orderTransactionId: $transaction->getId(),
                    amountMinor: (int) round((float) $legacyAmount * 100),
                    gatewayRefundId: 'tpay-legacy-' . $tpayTransactionId,
                    status: RefundStatus::COMPLETED,
                    context: $context,
                );
                ++$count;
            }
        }

        return $count;
    }

    private function mapStatus(string $tpayStatus): RefundStatus
    {
        return match ($tpayStatus) {
            'pending', 'new' => RefundStatus::IN_PROGRESS,
            default => RefundStatus::COMPLETED,
        };
    }

    private function loadRefundedTransactions(Context $context): \Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addAssociation('captures');
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsFilter('customFields.' . CustomFields::TPAY_REFUNDED_AMOUNT, null),
            ]),
            new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsFilter('customFields.' . CustomFields::TPAY_LAST_REFUND_AMOUNT, null),
            ]),
            new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsFilter('customFields.' . CustomFields::TPAY_LAST_REFUND_STATUS, null),
            ]),
        ]));

        return $this->orderTransactionRepository->search($criteria, $context);
    }
}
