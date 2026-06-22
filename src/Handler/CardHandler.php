<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Handler;

use Crehler\PaymentBundle\Application\Port\Driven\{OrderTransactionRepositoryInterface, PaymentSubMethodSessionResolverPort};
use Crehler\PaymentBundle\Application\Port\Driving\OrderTransactionServicePort;
use Crehler\PaymentBundle\Application\Service\StoredCardService;
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Infrastructure\Handler\{AbstractPaymentMethodHandler, PaymentResult};
use Crehler\PaymentBundle\Shared\{EnhancedLogger, FinalizeTokenService};
use Crehler\Tpay\Factory\TpayTransactionPayloadFactory;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

use function is_string;
use function trim;

#[AutoconfigureTag('shopware.payment.method.async')]
final class CardHandler extends AbstractPaymentMethodHandler
{
    public function __construct(
        EnhancedLogger $logger,
        RouterInterface $router,
        OrderTransactionServicePort $orderTransactionServicePort,
        FinalizeTokenService $finalizeTokenService,
        PaymentSubMethodSessionResolverPort $paymentSubMethodSessionResolver,
        OrderTransactionRepositoryInterface $orderTransactionRepository,
        private readonly TpayTransactionPayloadFactory $payloadFactory,
        private readonly TpayClientFactory $tpayClientFactory,
        private readonly StoredCardService $savedCardService,
    ) {
        parent::__construct(
            $logger,
            $router,
            $orderTransactionServicePort,
            $finalizeTokenService,
            $paymentSubMethodSessionResolver,
            $orderTransactionRepository,
        );
    }

    protected function getPaymentProviderName(): string
    {
        return 'Tpay Card';
    }

    protected function getProviderLogo(): string
    {
        return 'tpay';
    }

    protected function processPayment(
        Request $request,
        PaymentTransactionStruct $transaction,
        OrderTransaction $orderTransaction,
        ?string $paymentSubMethodId,
        Context $context,
    ): PaymentResult {
        ['notifyUrl' => $notificationUrl, 'returnUrl' => $returnUrl] = $this->buildPaymentUrls($orderTransaction, $transaction);

        $savedCardToken = $request->get('tpayCardToken');
        $customerId = $orderTransaction->order->customer->id;
        $salesChannelId = $orderTransaction->order->salesChannelId;
        $decryptedToken = $this->resolveSavedCardToken($savedCardToken, $customerId, $salesChannelId, $context);

        $isLoggedIn = $orderTransaction->order->customer->isGuest === false;
        $saveCard = $decryptedToken === null && $isLoggedIn && $request->get('tpaySaveCard') === 'on';

        $encryptedCard = $decryptedToken === null
            ? $this->extractEncryptedCard($request)
            : null;

        $payload = $this->payloadFactory->createCardPayload(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            notificationUrl: $notificationUrl,
            saveCard: $saveCard,
            cardToken: $decryptedToken,
            encryptedCard: $encryptedCard,
        );

        $this->logger->debug('Tpay: card payment payload prepared', [
            'usingSavedCard' => $decryptedToken !== null,
            'usingEncryptedCard' => $encryptedCard !== null,
            'saveCard' => $saveCard,
        ]);

        $tpay = $this->tpayClientFactory->create($salesChannelId);
        $result = $tpay->transactions()->createTransaction($payload);

        $status = $result['status'] ?? null;
        $tpayTransactionId = $result['transactionId'] ?? null;
        $paymentUrl = $result['transactionPaymentUrl'] ?? null;

        $this->logger->debug('Tpay: card transaction created', [
            'transactionId' => $tpayTransactionId,
            'status' => $status,
            'hasPaymentUrl' => $paymentUrl !== null && $paymentUrl !== '',
        ]);

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $tpayTransactionId, $context);

        if ($status === 'declined' || $status === 'error' || $status === 'failed') {
            $reason = $result['reason'] ?? 'Card payment declined by provider';
            $this->logger->warning('Tpay: card transaction declined', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'reason' => $reason,
            ]);

            return PaymentResult::failure(errorMessage: (string) $reason);
        }

        // 3DS challenge or redirect fallback — user needs to be sent to provider URL
        if ($paymentUrl !== null && $paymentUrl !== '') {
            return PaymentResult::success(
                redirectUrl: $paymentUrl,
                gatewayOrderId: $tpayTransactionId,
            );
        }

        // Immediate success — Tpay accepted card directly, no 3DS. Webhook (notify) will
        // arrive separately and transition the transaction to 'paid' via TpayNotificationSubscriber.
        // Empty redirectUrl tells AbstractPaymentMethodHandler::pay() to return null (no redirect).
        return PaymentResult::success(
            redirectUrl: '',
            gatewayOrderId: $tpayTransactionId,
        );
    }

    private function extractEncryptedCard(Request $request): ?string
    {
        $value = $request->get('tpayCardEncrypted');

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resolveSavedCardToken(
        ?string $savedCardToken,
        string $customerId,
        string $salesChannelId,
        Context $context,
    ): ?string {
        if ($savedCardToken === null || $savedCardToken === '') {
            return null;
        }

        $card = $this->savedCardService->findById($savedCardToken, $context);

        if ($card === null) {
            $this->logger->warning('Tpay: saved card not found', ['savedCardToken' => $savedCardToken]);

            return null;
        }

        if ($card->getCustomerId() !== $customerId || $card->getSalesChannelId() !== $salesChannelId) {
            $this->logger->warning('Tpay: saved card ownership mismatch', [
                'savedCardToken' => $savedCardToken,
                'cardCustomerId' => $card->getCustomerId(),
                'requestCustomerId' => $customerId,
                'cardSalesChannelId' => $card->getSalesChannelId(),
                'requestSalesChannelId' => $salesChannelId,
            ]);

            return null;
        }

        return $this->savedCardService->decryptToken($card);
    }
}
