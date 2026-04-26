<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Unit;

use Joomla\CMS\Http\Http;
use Joomla\Plugin\System\Spamtroll\Service\JoomlaHttpClient;
use PHPUnit\Framework\TestCase;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;

final class JoomlaHttpClientTest extends TestCase
{
    public function testGetReturnsHttpResponse(): void
    {
        $http = new class () extends Http {
            /** @var array{0: string, 1: array<string, string>, 2: int}|null */
            public ?array $lastCall = null;

            public function get(string $url, array $headers = [], int $timeout = 5): object
            {
                $this->lastCall = [$url, $headers, $timeout];
                return (object) [
                    'code' => 200,
                    'body' => '{"ok":true}',
                    'headers' => ['Content-Type' => 'application/json'],
                ];
            }
        };

        $client = new JoomlaHttpClient($http);
        $response = $client->send('GET', 'https://api.example.com/scan/status', ['X-API-Key' => 'k'], null, 5);

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json', $response->headers['content-type']);
        self::assertSame('https://api.example.com/scan/status', $http->lastCall[0] ?? null);
    }

    public function testPostForwardsBody(): void
    {
        $http = new class () extends Http {
            public string $lastBody = '';

            public function post(string $url, string $body, array $headers = [], int $timeout = 5): object
            {
                unset($url, $headers, $timeout);
                $this->lastBody = $body;
                return (object) ['code' => 200, 'body' => '{}', 'headers' => []];
            }
        };

        $client = new JoomlaHttpClient($http);
        $client->send('POST', 'https://api.example.com/scan/check', [], '{"hello":"world"}', 5);

        self::assertSame('{"hello":"world"}', $http->lastBody);
    }

    public function testTimeoutMessageIsTranslatedToTimeoutException(): void
    {
        $http = new class () extends Http {
            public function get(string $url, array $headers = [], int $timeout = 5): object
            {
                unset($url, $headers, $timeout);
                throw new \RuntimeException('Operation timed out after 5 seconds');
            }
        };

        $client = new JoomlaHttpClient($http);

        $this->expectException(TimeoutException::class);
        $client->send('GET', 'https://api.example.com/scan/status', [], null, 5);
    }

    public function testGenericFailureBecomesConnectionException(): void
    {
        $http = new class () extends Http {
            public function get(string $url, array $headers = [], int $timeout = 5): object
            {
                unset($url, $headers, $timeout);
                throw new \RuntimeException('DNS failure');
            }
        };

        $client = new JoomlaHttpClient($http);

        $this->expectException(ConnectionException::class);
        $client->send('GET', 'https://api.example.com/scan/status', [], null, 5);
    }

    public function testUnsupportedMethodThrowsConnectionException(): void
    {
        $client = new JoomlaHttpClient(new Http());

        $this->expectException(ConnectionException::class);
        $client->send('PATCH', 'https://api.example.com/scan/check', [], null, 5);
    }
}
