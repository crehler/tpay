<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Factory;

use Crehler\PaymentBundle\Application\Service\TransactionDescriptionRenderer;
use Crehler\PaymentBundle\Domain\Entity\Order\Order;
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\Tpay\Constant\TpayPayGroup;

use function number_format;
use function preg_replace;
use function trim;

final readonly class TpayTransactionPayloadFactory
{
    private const CONFIG_DOMAIN = 'CrehlerTpay.config';

    public function __construct(
        private TransactionDescriptionRenderer $descriptionRenderer,
    ) {
    }

    /**
     * Create base payload for Tpay transaction.
     *
     * Uses the order transaction amount (not the full order total) so hybrid
     * payments like Trade Credit + Tpay charge only the remaining balance.
     *
     * @return array<string, mixed>
     */
    public function createBasePayload(
        OrderTransaction $orderTransaction,
        string $returnUrl,
        string $notificationUrl,
    ): array {
        $order = $orderTransaction->order;

        return [
            'amount' => number_format($orderTransaction->totalAmount->amount / 100, 2, '.', ''),
            'description' => $this->descriptionRenderer->render(self::CONFIG_DOMAIN, $order, $order->salesChannelId),
            'hiddenDescription' => $orderTransaction->id,
            'payer' => $this->createPayerPayload($order),
            'callbacks' => [
                'payerUrls' => [
                    'success' => $returnUrl,
                    'error' => $returnUrl,
                ],
                'notification' => [
                    'url' => $notificationUrl,
                ],
            ],
        ];
    }

    /**
     * Create bank transfer payload (with channelId).
     *
     * @return array<string, mixed>
     */
    public function createBankPayload(
        OrderTransaction $orderTransaction,
        string $returnUrl,
        string $notificationUrl,
        int $channelId,
    ): array {
        $payload = $this->createBasePayload($orderTransaction, $returnUrl, $notificationUrl);
        $payload['pay'] = [
            'channelId' => $channelId,
        ];

        return $payload;
    }

    /**
     * Create card payment payload (groupId = 103).
     *
     * Priority: $cardToken (saved card) > $encryptedCard (embedded form) > redirect fallback.
     *
     * @return array<string, mixed>
     */
    public function createCardPayload(
        OrderTransaction $orderTransaction,
        string $returnUrl,
        string $notificationUrl,
        bool $saveCard = false,
        ?string $cardToken = null,
        ?string $encryptedCard = null,
    ): array {
        $payload = $this->createBasePayload($orderTransaction, $returnUrl, $notificationUrl);
        $payload['pay'] = [
            'groupId' => TpayPayGroup::CARD,
        ];

        if ($cardToken !== null && $cardToken !== '') {
            $payload['pay']['cardPaymentData'] = [
                'token' => $cardToken,
            ];
        } elseif ($encryptedCard !== null && $encryptedCard !== '') {
            $payload['pay']['cardPaymentData'] = [
                'card' => $encryptedCard,
            ];

            if ($saveCard) {
                $payload['pay']['cardPaymentData']['save'] = true;
            }
        } elseif ($saveCard) {
            $payload['pay']['cardPaymentData'] = [
                'save' => true,
            ];
        }

        return $payload;
    }

    /**
     * Create BLIK payment payload (groupId = 150).
     *
     * @return array<string, mixed>
     */
    public function createBlikPayload(
        OrderTransaction $orderTransaction,
        string $returnUrl,
        string $notificationUrl,
        ?string $blikToken = null,
    ): array {
        $payload = $this->createBasePayload($orderTransaction, $returnUrl, $notificationUrl);
        $payload['pay'] = [
            'groupId' => TpayPayGroup::BLIK,
        ];

        if ($blikToken !== null && $blikToken !== '') {
            $payload['pay']['blikPaymentData'] = [
                'blikToken' => $blikToken,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayerPayload(Order $order): array
    {
        $customer = $order->customer;
        $billing = $order->billingAddress;

        $payer = [
            'email' => $customer->email,
            'name' => trim($customer->firstName . ' ' . $customer->lastName),
        ];

        $phone = $this->normalizePhone($billing->phone ?? $customer->phone ?? '');
        if ($phone !== '') {
            $payer['phone'] = $phone;
        }

        if ($billing->street !== null && $billing->street !== '') {
            $payer['address'] = $billing->street;
        }

        if ($billing->city !== null && $billing->city !== '') {
            $payer['city'] = $billing->city;
        }

        if ($billing->zipCode !== null && $billing->zipCode !== '') {
            $payer['code'] = $billing->zipCode;
        }

        if ($billing->countryCode !== null && $billing->countryCode !== '') {
            $payer['country'] = $billing->countryCode;
        }

        return $payer;
    }

    /**
     * Strip Polish dial-code prefix because Tpay re-applies it on its side.
     *
     * NOTE: the bundle's PaymentRequestDtoFactory::parsePhoneNumber() is a
     * prefix/number splitter that requires a country code and uses Brick
     * PhoneNumber; it is not a drop-in normalizer for Tpay's flat "phone" field,
     * which expects the bare 9-digit national number. Delegating would change the
     * payload shape, so the local strip stays here intentionally.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return (string) preg_replace('/^(?:0048|48)(?=\d{9}$)/', '', $digits);
    }
}
