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
use Crehler\PaymentBundle\Application\Service\CustomerPaymentSubMethod\CustomerPaymentSubMethodService;
use Crehler\PaymentBundle\Domain\Entity\Customer;
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Domain\Enum\PaymentType;
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

#[AutoconfigureTag('shopware.payment.method.async')]
final class BankHandler extends AbstractPaymentMethodHandler
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
        private readonly CustomerPaymentSubMethodService $customerPaymentSubMethodService,
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

    public static function paymentType(): PaymentType
    {
        return PaymentType::PAY_BY_LINK;
    }

    /** The PBL bank list comes from the gateway. */
    public static function usesGatewayChannels(): bool
    {
        return true;
    }

    protected function getPaymentProviderName(): string
    {
        return 'Tpay Bank';
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

        $resolvedSubMethodId = $paymentSubMethodId ?? $this->resolveFromCustomer(
            customer: $orderTransaction->order->customer,
            paymentMethodId: $orderTransaction->paymentMethod->id,
        );

        $channelId = $resolvedSubMethodId !== null ? (int) $resolvedSubMethodId : 0;

        $payload = $this->payloadFactory->createBankPayload(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            notificationUrl: $notificationUrl,
            channelId: $channelId,
        );

        $salesChannelId = $orderTransaction->order->salesChannelId;
        $tpay = $this->tpayClientFactory->create($salesChannelId);
        $result = $tpay->transactions()->createTransaction($payload);

        $status = $result['status'] ?? null;
        $tpayTransactionId = $result['transactionId'] ?? null;

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $tpayTransactionId, $context);

        // Tpay can reject a bank transfer at creation time; mirror CardHandler and surface
        // it as a failure instead of reporting a declined transaction as success.
        // `result` is checked alongside `status` because an HTTP 4xx carrying a JSON error
        // document comes back as an ordinary array — result=failed plus an errors list, with
        // no `status` at all. Same idiom as TpayConnectionChecker and TpayRefundProvider.
        $outcome = $result['result'] ?? null;

        if ($outcome === 'failed' || $status === 'declined' || $status === 'error' || $status === 'failed') {
            $reason = $result['reason'] ?? 'Bank transfer declined by provider';
            $this->logger->warning('Tpay: bank transaction declined', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'result' => $outcome,
                'reason' => $reason,
                // Populated instead of `reason` when the decline arrived as an error document.
                'errors' => $result['errors'] ?? null,
            ]);

            return PaymentResult::failure(errorMessage: (string) $reason);
        }

        // The status check above is not enough on its own. ApiAction::checkResponse() throws
        // only when an error response has an EMPTY body, so an HTTP 4xx carrying a JSON error
        // document comes back here as an ordinary array — result=failed plus an errors list,
        // with neither `status` nor `transactionPaymentUrl` in it. $status is then null, the
        // decline branch is skipped, and `?? ''` reported a successful payment with an empty
        // redirect — which the shared flow reads as "accepted without a redirect, the webhook
        // finishes it". A bank transfer has no such path: the customer has to reach the bank.
        $paymentUrl = $result['transactionPaymentUrl'] ?? null;

        if (!is_string($paymentUrl) || $paymentUrl === '') {
            $this->logger->warning('Tpay: bank transaction created without a payment URL', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'result' => $result['result'] ?? null,
                'errors' => $result['errors'] ?? null,
            ]);

            return PaymentResult::failure(errorMessage: 'Tpay did not return a payment URL');
        }

        return PaymentResult::success(
            redirectUrl: $paymentUrl,
            gatewayOrderId: $tpayTransactionId,
        );
    }

    /**
     * Fall back to the customer's previously saved bank choice when the session
     * has no value. Storefront renders the bank as selected from the same source,
     * so /checkout/finish/order (which does not re-fire SalesChannelContextSwitchEvent)
     * would otherwise send channelId=0 and Tpay would show its method list.
     *
     * The choice is persisted on the customer account by the context switch
     * (PATCH /store-api/context with `paymentSubMethod`) — there is no dedicated
     * write endpoint. This applies equally to guests: a guest is a real customer
     * row (guest=1), so its choice is stored and read back the same way. In a
     * headless flow the finish step commonly runs on a fresh session/token, so
     * without this account fallback handle-payment would send channelId=0.
     */
    private function resolveFromCustomer(Customer $customer, string $paymentMethodId): ?string
    {
        return $this->customerPaymentSubMethodService
            ->getSubMethod(customer: $customer, paymentMethodId: $paymentMethodId)
            ?->subPaymentMethodId;
    }
}
