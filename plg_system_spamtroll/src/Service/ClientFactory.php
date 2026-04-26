<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Service;

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Http\HttpClientInterface;

/**
 * Builds a configured Spamtroll SDK {@see Client}.
 *
 * The plugin re-creates a client per scan so it can pick up configuration
 * changes without requiring a server restart, but the underlying HTTP
 * adapter is always the shared {@see JoomlaHttpClient} instance registered
 * with the DI container.
 */
final class ClientFactory
{
    private HttpClientInterface $http;

    public function __construct(HttpClientInterface $http)
    {
        $this->http = $http;
    }

    public function create(string $apiKey, string $baseUrl, int $timeout): Client
    {
        $config = new ClientConfig(
            $baseUrl !== '' ? $baseUrl : ClientConfig::DEFAULT_BASE_URL,
            $timeout,
            ClientConfig::DEFAULT_MAX_RETRIES,
            ClientConfig::DEFAULT_RETRY_BASE_DELAY_MS,
            'spamtroll-joomla/0.1.0',
            ClientConfig::DEFAULT_SCORE_DENOMINATOR,
        );

        return new Client($apiKey, $config, $this->http);
    }
}
