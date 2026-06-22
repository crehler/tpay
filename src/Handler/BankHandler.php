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
use Crehler\PaymentBundle\Infrastructure\Handler\{AbstractPaymentMethodHandler, PaymentResult};
use Crehler\PaymentBundle\Shared\{EnhancedLogger, FinalizeTokenService};
use Crehler\Tpay\Factory\TpayTransactionPayloadFactory;
use Crehler\Tpay\Infrastructure\Client\TpayClientFactory;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

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
        if ($status === 'declined' || $status === 'error' || $status === 'failed') {
            $reason = $result['reason'] ?? 'Bank transfer declined by provider';
            $this->logger->warning('Tpay: bank transaction declined', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'reason' => $reason,
            ]);

            return PaymentResult::failure(errorMessage: (string) $reason);
        }

        return PaymentResult::success(
            redirectUrl: $result['transactionPaymentUrl'] ?? '',
            gatewayOrderId: $tpayTransactionId,
        );
    }

    /**
     * Fall back to the customer's previously saved bank choice when the session
     * has no value. Storefront renders the bank as selected from the same source,
     * so /checkout/finish/order (which does not re-fire SalesChannelContextSwitchEvent)
     * would otherwise send channelId=0 and Tpay would show its method list.
     *
     * Applies equally to guest customers: the Store API headless flow uses
     * PATCH /store-api/customer/cr/payment-sub-method to persist the choice
     * per customer.id (guest=true rows included) and never fires the session
     * subscriber, so without this fallback handle-payment would always send
     * channelId=0 for guests.
     */
    private function resolveFromCustomer(Customer $customer, string $paymentMethodId): ?string
    {
        return $this->customerPaymentSubMethodService
            ->getSubMethod(customer: $customer, paymentMethodId: $paymentMethodId)
            ?->subPaymentMethodId;
    }
}
