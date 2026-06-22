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

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
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

        $tpayTransactionId = $result['transactionId'] ?? null;

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $tpayTransactionId, $context);

        return PaymentResult::success(
            redirectUrl: $result['transactionPaymentUrl'] ?? '',
            gatewayOrderId: $tpayTransactionId,
        );
    }
}
