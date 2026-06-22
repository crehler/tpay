<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Connection;

use Crehler\PaymentBundle\Application\DTO\Connection\ConnectionCheckResult;
use Crehler\PaymentBundle\Infrastructure\Connection\AbstractGatewayConnectionChecker;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;
use Tpay\OpenApi\Api\TpayApi;
use Tpay\OpenApi\Utilities\Cache;

use function count;
use function sprintf;

/**
 * Verifies Tpay Open API credentials by listing payment channels (an authenticated
 * call). Builds the client from the values the operator typed in the form (falling
 * back to stored config for untouched fields), so the test reflects the unsaved state
 * of whichever environment's card the button sits in.
 */
final class TpayConnectionChecker extends AbstractGatewayConnectionChecker
{
    private const CONFIG_DOMAIN = 'CrehlerTpay.config';

    public function __construct(
        SystemConfigService $systemConfigService,
        private readonly CacheItemPoolInterface $cachePool,
        private readonly EnhancedLogger $logger,
    ) {
        parent::__construct($systemConfigService);
    }

    public function check(string $environment, array $config, ?string $salesChannelId): ConnectionCheckResult
    {
        $sandbox = $environment === 'sandbox';

        $clientId = $this->resolveValue($config, $sandbox ? 'sandboxClientId' : 'clientId', $salesChannelId);
        $clientSecret = $this->resolveValue($config, $sandbox ? 'sandboxClientSecret' : 'clientSecret', $salesChannelId);

        if ($clientId === '' || $clientSecret === '') {
            return ConnectionCheckResult::failure('Client ID and Client Secret are required.');
        }

        try {
            $api = new TpayApi(
                cache: new Cache(cacheItemPool: $this->cachePool),
                clientId: $clientId,
                clientSecret: $clientSecret,
                productionMode: !$sandbox,
            );

            $channels = $api->transactions()->getChannels();
        } catch (Throwable $e) {
            // Log the raw gateway/client detail server-side; never surface it to the
            // admin UI (it can carry endpoint/credential-validation fragments).
            $this->logger->error('Tpay connection test failed', [
                'environment' => $environment,
                'exception' => $e->getMessage(),
            ]);

            return ConnectionCheckResult::failure('Żądanie do API Tpay nie powiodło się. Sprawdź dane dostępowe i spróbuj ponownie.');
        }

        if (($channels['result'] ?? null) === 'failed') {
            $this->logger->warning('Tpay connection test rejected by gateway', [
                'environment' => $environment,
                'errorMessage' => $channels['requestError']['errorMessage'] ?? null,
            ]);

            return ConnectionCheckResult::failure('Uwierzytelnienie w Tpay nie powiodło się. Sprawdź Client ID i Client Secret.');
        }

        $channelCount = isset($channels['channels']) ? count($channels['channels']) : 0;

        return ConnectionCheckResult::ok(sprintf('Połączenie udane. Liczba dostępnych kanałów płatności: %d.', $channelCount));
    }

    protected function configDomain(): string
    {
        return self::CONFIG_DOMAIN;
    }
}
