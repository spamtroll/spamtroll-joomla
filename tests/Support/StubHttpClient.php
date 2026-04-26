<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Support;

use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Http\HttpResponse;
use Throwable;

/**
 * Test double for {@see HttpClientInterface} that pops scripted responses
 * (or exceptions) off a queue.
 */
final class StubHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse|Throwable> */
    private array $queue;

    public int $callCount = 0;

    /**
     * @param array<int, HttpResponse|Throwable> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        unset($method, $url, $headers, $body, $timeout);

        $this->callCount++;

        if ($this->queue === []) {
            return new HttpResponse(200, '{}', []);
        }

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }
}
