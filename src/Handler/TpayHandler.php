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
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

use function is_string;

/**
 * The whole Tpay checkout, with the instrument left to the customer.
 *
 * The three other handlers each commit the transaction to something before the customer
 * leaves the shop — a bank, the card group, the BLIK group. This one commits to nothing:
 * the request carries no `pay` object at all, which is what turns transactionPaymentUrl
 * into Tpay's own selection page instead of a redirect to one instrument. The SDK's
 * Transaction::getRequiredFields() names amount, description and payer, so `pay` is
 * genuinely optional rather than merely tolerated.
 *
 * Not the same as BankHandler falling back to channelId = 0. That is still a bank transfer
 * and lands on Tpay's bank list; this reaches the full paywall, including instruments this
 * plugin ships no handler for.
 */
#[AutoconfigureTag('shopware.payment.method.async')]
final class TpayHandler extends AbstractPaymentMethodHandler
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

    public static function paymentType(): PaymentType
    {
        return PaymentType::PAYWALL;
    }

    /** The customer chooses on Tpay's page, so the shop offers nothing to choose from. */
    public static function usesGatewayChannels(): bool
    {
        return false;
    }

    protected function getPaymentProviderName(): string
    {
        return 'Tpay';
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

        // $paymentSubMethodId is deliberately unused. The contract declares no gateway
        // channels, so nothing in the storefront offers one — and honouring a value that
        // reached us anyway would quietly turn this back into a bank transfer, which is the
        // one thing the method exists not to be.
        $payload = $this->payloadFactory->createBasePayload(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            notificationUrl: $notificationUrl,
        );

        $tpay = $this->tpayClientFactory->create($orderTransaction->order->salesChannelId);
        $result = $tpay->transactions()->createTransaction($payload);

        $status = $result['status'] ?? null;
        $tpayTransactionId = $result['transactionId'] ?? null;

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $tpayTransactionId, $context);

        // Mirrors BankHandler: Tpay can refuse at creation time, and reporting that as a
        // success sends the customer to a redirect URL that is not there.
        if ($status === 'declined' || $status === 'error' || $status === 'failed') {
            $reason = $result['reason'] ?? 'Payment declined by provider';
            $this->logger->warning('Tpay: transaction declined', [
                'transactionId' => $tpayTransactionId,
                'status' => $status,
                'reason' => $reason,
            ]);

            return PaymentResult::failure(errorMessage: (string) $reason);
        }

        // The status check above is not enough on its own. ApiAction::checkResponse() throws
        // only when an error response has an EMPTY body, so an HTTP 4xx carrying a JSON error
        // document is returned here as an ordinary array — result=failed plus an errors list,
        // with neither `status` nor `transactionPaymentUrl` in it. $status is then null, the
        // decline branch is skipped, and `?? ''` would report a successful payment whose
        // redirect goes nowhere. This method has no other way out: there is no card form and
        // no BLIK code, only the paywall the customer never reaches.
        $paymentUrl = $result['transactionPaymentUrl'] ?? null;

        if (!is_string($paymentUrl) || $paymentUrl === '') {
            $this->logger->warning('Tpay: transaction created without a payment URL', [
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
}
