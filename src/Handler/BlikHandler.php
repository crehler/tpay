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
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Domain\Enum\PaymentType;
use Crehler\PaymentBundle\Infrastructure\Handler\{AbstractPaymentMethodHandler, PaymentResult};
use Crehler\PaymentBundle\Shared\{EnhancedLogger, FinalizeTokenService};
use Crehler\Tpay\Factory\TpayTransactionPayloadFactory;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\{RedirectResponse, Request};
use Symfony\Component\Routing\RouterInterface;

use function is_string;

#[AutoconfigureTag('shopware.payment.method.async')]
final class BlikHandler extends AbstractPaymentMethodHandler
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

    /**
     * BLIK is layer-zero (driven through pay(), never Shopware's async path), but a BLIK
     * order is still refundable — defer REFUND to the base handler so it resolves the
     * registered RefundProviderPort instead of reporting "unsupported".
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        if ($type === PaymentHandlerType::REFUND) {
            return parent::supports($type, $paymentMethodId, $context);
        }

        return false;
    }

    /**
     * BLIK uses the shared layer-zero flow: authorize in-place on a BLIK code,
     * otherwise redirect to the Tpay payment page.
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct,
    ): ?RedirectResponse {
        return $this->payViaBlikAuthorize($request, $transaction, $context);
    }

    public static function paymentType(): PaymentType
    {
        return PaymentType::BLIK;
    }

    /** BLIK is a code-entry payment with no channels to choose. */
    public static function usesGatewayChannels(): bool
    {
        return false;
    }

    protected function getPaymentProviderName(): string
    {
        return 'Tpay BLIK';
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
        $blikCode = $request->get('blikCode');

        $payload = $this->payloadFactory->createBlikPayload(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            notificationUrl: $notificationUrl,
            blikToken: !empty($blikCode) ? $blikCode : null,
        );

        $salesChannelId = $orderTransaction->order->salesChannelId;
        $tpay = $this->tpayClientFactory->create($salesChannelId);
        $result = $tpay->transactions()->createTransaction($payload);

        $status = $result['status'] ?? null;
        $tpayTransactionId = $result['transactionId'] ?? null;
        $paymentUrl = $result['transactionPaymentUrl'] ?? null;

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $tpayTransactionId, $context);

        // This handler read neither the status nor the URL, which the other three all do.
        // A BLIK code Tpay rejects outright came back as a success, and the customer landed
        // on a finish page for a payment that had already failed.
        // `result` is checked alongside `status` because an HTTP 4xx carrying a JSON error
        // document comes back as an ordinary array — result=failed plus an errors list, with
        // no `status` at all. Same idiom as TpayConnectionChecker and TpayRefundProvider.
        $outcome = $result['result'] ?? null;

        if ($outcome === 'failed' || $status === 'declined' || $status === 'error' || $status === 'failed') {
            $reason = $result['reason'] ?? 'BLIK payment declined by provider';
            $this->logger->warning('Tpay: BLIK transaction declined', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'result' => $outcome,
                'reason' => $reason,
                // Populated instead of `reason` when the decline arrived as an error document.
                'errors' => $result['errors'] ?? null,
            ]);

            return PaymentResult::failure(errorMessage: (string) $reason);
        }

        $hasPaymentUrl = is_string($paymentUrl) && $paymentUrl !== '';

        // With a code, Tpay authorizes in place and there is nothing to redirect to: the
        // empty redirect is the signal that sends the customer to the in-shop polling page.
        // Without one, the URL is the only way forward, so a missing URL is a dead end —
        // and ApiAction::checkResponse() lets an HTTP 4xx with a JSON error body through as
        // an ordinary array, with neither `status` nor `transactionPaymentUrl` set.
        if (!$hasPaymentUrl && empty($blikCode)) {
            $this->logger->warning('Tpay: BLIK transaction created without a payment URL', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'result' => $result['result'] ?? null,
                'errors' => $result['errors'] ?? null,
            ]);

            return PaymentResult::failure(errorMessage: 'Tpay did not return a payment URL');
        }

        return PaymentResult::success(
            redirectUrl: $hasPaymentUrl ? $paymentUrl : '',
            gatewayOrderId: $tpayTransactionId,
        );
    }
}
