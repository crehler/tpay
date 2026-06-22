<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Api\Controller;

use Crehler\PaymentBundle\Application\Service\StoredCardService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
#[Autoconfigure(public: true)]
class SavedCardController extends AbstractController
{
    public function __construct(
        private readonly StoredCardService $savedCardService,
    ) {
    }

    #[Route(
        path: '/store-api/cr/tpay/saved-card/{id}',
        name: 'store-api.cr.tpay.saved-card.delete',
        defaults: ['_loginRequired' => true],
        methods: ['DELETE']
    )]
    public function delete(string $id, SalesChannelContext $context): JsonResponse
    {
        $customer = $context->getCustomer();

        if ($customer === null || $customer->getGuest()) {
            return new JsonResponse(['error' => 'Not authenticated'], Response::HTTP_FORBIDDEN);
        }

        $card = $this->savedCardService->findById($id, $context->getContext());

        if ($card === null) {
            return new JsonResponse(['error' => 'Card not found'], Response::HTTP_NOT_FOUND);
        }

        if ($card->getCustomerId() !== $customer->getId() || $card->getSalesChannelId() !== $context->getSalesChannelId()) {
            return new JsonResponse(['error' => 'Card not found'], Response::HTTP_NOT_FOUND);
        }

        $this->savedCardService->delete($id, $context->getContext());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
