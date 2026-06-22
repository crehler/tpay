<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Infrastructure\Client;

use Crehler\PaymentBundle\Infrastructure\Client\AbstractGatewayClientFactory;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Tpay\OpenApi\Api\TpayApi;
use Tpay\OpenApi\Utilities\Cache;

use function mb_strlen;
use function mb_substr;

final class TpayClientFactory extends AbstractGatewayClientFactory
{
    private const CLIENT_ID = 'CrehlerTpay.config.clientId';
    private const CLIENT_SECRET = 'CrehlerTpay.config.clientSecret';
    private const SECURITY_CODE = 'CrehlerTpay.config.securityCode';
    private const SANDBOX_CLIENT_ID = 'CrehlerTpay.config.sandboxClientId';
    private const SANDBOX_CLIENT_SECRET = 'CrehlerTpay.config.sandboxClientSecret';
    private const SANDBOX_SECURITY_CODE = 'CrehlerTpay.config.sandboxSecurityCode';
    private const ENABLE_SANDBOX = 'CrehlerTpay.config.enableSandbox';

    public function __construct(
        SystemConfigService $systemConfigService,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly EnhancedLogger $logger,
    ) {
        parent::__construct($systemConfigService);
    }

    public function create(?string $salesChannelId = null): TpayApi
    {
        $sandbox = $this->isSandbox(self::ENABLE_SANDBOX, $salesChannelId);
        $clientId = $this->requireString($this->selectKey($sandbox, self::CLIENT_ID, self::SANDBOX_CLIENT_ID), $salesChannelId);
        $clientSecret = $this->requireString($this->selectKey($sandbox, self::CLIENT_SECRET, self::SANDBOX_CLIENT_SECRET), $salesChannelId);

        $this->logger->debug('Creating Tpay API client', [
            'salesChannelId' => $salesChannelId,
            'sandbox' => $sandbox,
            'productionMode' => !$sandbox,
            'clientId' => mb_substr($clientId, 0, 5) . '***',
            'clientSecretLength' => mb_strlen($clientSecret),
            'expectedApiUrl' => !$sandbox ? 'https://api.tpay.com' : 'https://openapi.sandbox.tpay.com',
        ]);

        return new TpayApi(
            cache: new Cache(cacheItemPool: $this->cachePool),
            clientId: $clientId,
            clientSecret: $clientSecret,
            productionMode: !$sandbox,
        );
    }

    /**
     * Notification "security code" (Tpay panel: Settings → Notifications → Security).
     * Required by the SDK's JWSVerifiedPaymentNotification to verify the MD5 checksum
     * of standard payment notifications.
     */
    public function getSecurityCode(?string $salesChannelId = null): string
    {
        $sandbox = $this->isSandbox(self::ENABLE_SANDBOX, $salesChannelId);

        return $this->requireString($this->selectKey($sandbox, self::SECURITY_CODE, self::SANDBOX_SECURITY_CODE), $salesChannelId);
    }

    public function isProductionMode(?string $salesChannelId = null): bool
    {
        return !$this->isSandbox(self::ENABLE_SANDBOX, $salesChannelId);
    }
}
