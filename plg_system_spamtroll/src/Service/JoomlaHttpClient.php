<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Service;

use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;
use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Http\HttpResponse;
use Throwable;

/**
 * SDK HTTP adapter that routes Spamtroll requests through Joomla's
 * {@see HttpFactory}. Using the framework transport means proxy settings,
 * SSL overrides and any future request hooks configured globally for
 * Joomla still apply to every Spamtroll API call.
 */
final class JoomlaHttpClient implements HttpClientInterface
{
    private ?Http $http;

    public function __construct(?Http $http = null)
    {
        $this->http = $http;
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException
     * @throws TimeoutException
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $client = $this->http ?? HttpFactory::getHttp();

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $headers, $timeout),
                'POST' => $client->post($url, $body ?? '', $headers, $timeout),
                'PUT' => $client->put($url, $body ?? '', $headers, $timeout),
                'DELETE' => $client->delete($url, $headers, $timeout),
                default => throw new ConnectionException(
                    'Unsupported HTTP method: ' . $method,
                    0
                ),
            };
        } catch (ConnectionException | TimeoutException $e) {
            throw $e;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $lower = strtolower($message);
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                throw TimeoutException::afterSeconds($timeout);
            }
            throw ConnectionException::fromMessage($message);
        }

        $statusCode = is_object($response) && property_exists($response, 'code')
            ? (int) ($response->code ?? 0)
            : 0;
        $rawBody = is_object($response) && property_exists($response, 'body')
            ? (string) ($response->body ?? '')
            : '';

        $rawHeaders = is_object($response) && property_exists($response, 'headers') && is_array($response->headers)
            ? $response->headers
            : [];

        $normalised = [];
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $normalised[strtolower($name)] = (string) $value;
        }

        return new HttpResponse($statusCode, $rawBody, $normalised);
    }
}
