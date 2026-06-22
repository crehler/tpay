<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Subscriber;

use Crehler\PaymentBundle\Application\Service\{OrderTransactionSalesChannelResolver, RefundSynchronizer, StoredCardService, TransactionStateApplier};
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Domain\Event\PaymentNotificationReceivedEvent;
use Crehler\PaymentBundle\Domain\ValueObjects\{RefundStatus, TransactionStateTransition};
use Crehler\PaymentBundle\Infrastructure\Subscriber\AbstractPaymentNotificationSubscriber;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\Tpay\Enums\TpayTransactionStatus;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;
use Tpay\OpenApi\Utilities\{Cache, CacheCertificateProvider, Logger};
use Tpay\OpenApi\Webhook\JWSVerifiedPaymentNotification;

class TpayNotificationSubscriber extends AbstractPaymentNotificationSubscriber
{
    public function __construct(
        TransactionStateApplier $transactionStateApplier,
        EnhancedLogger $logger,
        private readonly TpayClientFactory $tpayClientFactory,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly StoredCardService $savedCardService,
        private readonly RefundSynchronizer $refundSynchronizer,
        private readonly OrderTransactionSalesChannelResolver $salesChannelResolver,
    ) {
        parent::__construct($transactionStateApplier, $logger);
    }

    /**
     * Tpay notifications carry the x-jws-signature header and the form fields
     * tr_id/tr_crc/tr_status.
     */
    protected function supports(PaymentNotificationReceivedEvent $event): bool
    {
        $request = $event->request;

        $hasJwsSignature = $request->headers->has('x-jws-signature');
        $hasTpayFields = $request->request->has('tr_id')
            && $request->request->has('tr_crc')
            && $request->request->has('tr_status');

        return $hasJwsSignature && $hasTpayFields;
    }

    /**
     * Authenticity is verified by the official Tpay SDK
     * (Tpay\OpenApi\Webhook\JWSVerifiedPaymentNotification): mandatory JWS signature
     * (RFC 7515, certificate chain) plus the MD5 checksum for standard payment
     * notifications. The SDK is wired with our Shopware PSR-6 cache (certificate
     * caching) and our EnhancedLogger (PSR-3).
     *
     * NOTE: JWSVerifiedPaymentNotification reads the raw body and the
     * x-jws-signature header from PHP superglobals / getallheaders() internally —
     * the installed SDK version exposes no API to inject the dispatched
     * Symfony request ($event->request). We therefore cannot feed it the exact
     * bytes Shopware parsed. This is acceptable because the path is fail-closed
     * (any SDK exception below → false → the bundle responds 400); revisit if the
     * SDK gains a payload-injection entry point.
     */
    protected function verify(PaymentNotificationReceivedEvent $event): bool
    {
        $trCrc = (string) $event->request->request->get('tr_crc', '');
        // tr_crc carries the order transaction id (stored as hiddenDescription),
        // so the SDK can verify against that channel's security code and mode.
        // Validate the UUID before the by-id lookup: an attacker can post any
        // tr_crc, and a non-UUID value would make the DAL search() throw.
        $salesChannelId = Uuid::isValid($trCrc)
            ? $this->salesChannelResolver->resolve($trCrc, $event->context)
            : null;

        try {
            Logger::setLogger($this->logger);

            $certificateProvider = new CacheCertificateProvider(
                new Cache(cacheItemPool: $this->cachePool),
            );

            $notification = new JWSVerifiedPaymentNotification(
                $certificateProvider,
                $this->tpayClientFactory->getSecurityCode($salesChannelId),
                $this->tpayClientFactory->isProductionMode($salesChannelId),
            );

            // Throws TpayException when the JWS signature or MD5 checksum is invalid.
            $notification->getNotification();

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Tpay notification verification failed', [
                'exception' => $e->getMessage(),
                'tr_id' => $event->request->request->get('tr_id', 'unknown'),
            ]);

            return false;
        }
    }

    protected function resolveOrderTransaction(
        PaymentNotificationReceivedEvent $event,
        Context $context,
    ): ?OrderTransactionEntity {
        $trCrc = (string) $event->request->request->get('tr_crc', '');
        $trId = (string) $event->request->request->get('tr_id', '');

        $orderTransaction = null;

        // Primary: tr_crc is the order transaction id (stored in hiddenDescription).
        // Validate the UUID first — tr_crc is attacker-controlled and a non-UUID
        // value would make the by-id DAL search() throw.
        if (Uuid::isValid($trCrc)) {
            $criteria = new Criteria([$trCrc]);
            $criteria->addAssociation('order.orderCustomer');

            /** @var OrderTransactionEntity|null $orderTransaction */
            $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        }

        // Fallback: by the Tpay transaction id stored on custom fields.
        if ($orderTransaction === null && $trId !== '') {
            $fallback = new Criteria();
            $fallback->addAssociation('order.orderCustomer');
            $fallback->addFilter(new EqualsFilter('customFields.' . PaymentCustomFields::GATEWAY_PAYMENT_ID, $trId));
            $orderTransaction = $this->orderTransactionRepository->search($fallback, $context)->first();

            if ($orderTransaction !== null) {
                $this->logger->info('Tpay: order transaction found via gatewayPaymentId fallback', [
                    'tpayTransactionId' => $trId,
                    'orderTransactionId' => $orderTransaction->getId(),
                ]);
            }
        }

        if ($orderTransaction === null) {
            $this->logger->error('Tpay: order transaction not found', [
                'tr_crc' => $trCrc,
                'tr_id' => $trId,
            ]);
        }

        return $orderTransaction;
    }

    protected function mapStatus(
        PaymentNotificationReceivedEvent $event,
        OrderTransactionEntity $orderTransaction,
    ): TransactionStateTransition {
        $status = (string) $event->request->request->get('tr_status', '');
        $tpayStatus = TpayTransactionStatus::tryFrom($status);

        if ($tpayStatus === null) {
            $this->logger->warning('Tpay: unknown transaction status, transitioning to cancelled', [
                'tr_status' => $status,
                'orderTransactionId' => $orderTransaction->getId(),
            ]);
        }

        return match ($tpayStatus) {
            TpayTransactionStatus::TRUE => TransactionStateTransition::PAID,
            TpayTransactionStatus::CHARGEBACK => TransactionStateTransition::REFUNDED,
            // FALSE and unknown statuses both cancel.
            default => TransactionStateTransition::CANCELLED,
        };
    }

    protected function beforeApply(
        PaymentNotificationReceivedEvent $event,
        OrderTransactionEntity $orderTransaction,
        TransactionStateTransition $transition,
        Context $context,
    ): void {
        $this->tryExtractCardToken($event->request->request->all(), $orderTransaction, $context);

        // CHARGEBACK is a full refund (per Tpay docs) executed outside the shop — record it
        // as a native refund entity so it shows up in the universal "Payment refunds" module.
        if ($transition === TransactionStateTransition::REFUNDED) {
            $this->syncChargebackRefund($event, $orderTransaction, $context);
        }
    }

    private function syncChargebackRefund(
        PaymentNotificationReceivedEvent $event,
        OrderTransactionEntity $orderTransaction,
        Context $context,
    ): void {
        $trId = (string) $event->request->request->get('tr_id', '');
        // Deterministic id keeps repeated chargeback webhooks idempotent.
        $gatewayRefundId = $trId !== '' ? 'chargeback-' . $trId : null;

        $this->refundSynchronizer->syncExternalRefund(
            orderTransactionId: $orderTransaction->getId(),
            amountMinor: null, // full refund
            gatewayRefundId: $gatewayRefundId,
            status: RefundStatus::COMPLETED,
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function tryExtractCardToken(array $postData, OrderTransactionEntity $orderTransaction, Context $context): void
    {
        $cardToken = $postData['card_token'] ?? null;
        $cardBrand = $postData['card_brand'] ?? null;
        $cardTail = $postData['card_tail'] ?? null;
        $tokenExpiryDate = $postData['token_expiry_date'] ?? null;

        if ($cardToken === null || $cardBrand === null || $cardTail === null || $tokenExpiryDate === null) {
            return;
        }

        try {
            $order = $orderTransaction->getOrder();

            if ($order === null || $order->getOrderCustomer() === null) {
                $this->logger->warning('Tpay: cannot save card token - missing order or customer', [
                    'orderTransactionId' => $orderTransaction->getId(),
                ]);

                return;
            }

            $customerId = $order->getOrderCustomer()->getCustomerId();

            if ($customerId === null) {
                $this->logger->info('Tpay: skipping card token save for guest customer');

                return;
            }

            $this->savedCardService->saveFromNotification(
                token: $cardToken,
                brand: $cardBrand,
                tail: $cardTail,
                expiryDate: $tokenExpiryDate,
                customerId: $customerId,
                salesChannelId: $order->getSalesChannelId(),
                context: $context,
            );
        } catch (Throwable $e) {
            $this->logger->error('Tpay: failed to save card token from notification', [
                'exception' => $e,
                'orderTransactionId' => $orderTransaction->getId(),
            ]);
        }
    }
}
