<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Unit;

use Joomla\Plugin\System\Spamtroll\Service\ClientFactory;
use Joomla\Plugin\System\Spamtroll\Service\Decision;
use Joomla\Plugin\System\Spamtroll\Service\Logger;
use Joomla\Plugin\System\Spamtroll\Service\Scanner;
use PHPUnit\Framework\TestCase;
use Spamtroll\Joomla\Tests\Support\InMemoryDatabase;
use Spamtroll\Joomla\Tests\Support\StubHttpClient;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Http\HttpResponse;

final class ScannerTest extends TestCase
{
    public function testReturnsAllowWhenApiKeyMissing(): void
    {
        $scanner = $this->buildScanner(new StubHttpClient([]), [
            'api_key' => '',
            'enable_user_check' => 1,
            'enable_content_check' => 1,
        ]);

        $decision = $scanner->checkUserRegistration('alice', 'alice@example.com', '203.0.113.1');

        self::assertSame(Decision::ALLOW, $decision->action);
        self::assertSame(Decision::STATUS_SAFE, $decision->status);
    }

    public function testReturnsAllowWhenChecksDisabled(): void
    {
        $http = new StubHttpClient([]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'enable_user_check' => 0,
            'enable_content_check' => 0,
        ]);

        self::assertSame(Decision::ALLOW, $scanner->checkUserRegistration('a', 'a@b.c', null)->action);
        self::assertSame(Decision::ALLOW, $scanner->checkContent('hello', 'com_content.article', null)->action);
        self::assertSame(0, $http->callCount, 'no HTTP request should be made when checks are disabled');
    }

    public function testFailsOpenOnConnectionError(): void
    {
        $http = new StubHttpClient([
            new ConnectionException('connection refused', 0),
        ]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'enable_user_check' => 1,
        ]);

        $decision = $scanner->checkUserRegistration('alice', 'alice@example.com', '203.0.113.1');

        self::assertSame(Decision::ALLOW, $decision->action);
    }

    public function testBlocksWhenScoreAboveSpamThreshold(): void
    {
        $http = new StubHttpClient([
            new HttpResponse(200, json_encode([
                'success' => true,
                'data' => [
                    'status' => 'blocked',
                    'spam_score' => 30.0, // raw / 30 = 1.0 normalised
                    'symbols' => ['BAYES_SPAM', 'KEYWORDS_HEAVY'],
                ],
            ]) ?: '{}'),
        ]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'spam_threshold' => 0.70,
            'suspicious_threshold' => 0.40,
            'enable_content_check' => 1,
            'action_blocked' => 'block',
        ]);

        $decision = $scanner->checkContent('buy cheap pills', 'com_content.article', '203.0.113.1');

        self::assertSame(Decision::BLOCK, $decision->action);
        self::assertSame(Decision::STATUS_BLOCKED, $decision->status);
        self::assertEqualsWithDelta(1.0, $decision->score, 0.0001);
        self::assertContains('BAYES_SPAM', $decision->symbols);
    }

    public function testBlockedConfigCanBeMappedToModeration(): void
    {
        $http = new StubHttpClient([
            new HttpResponse(200, json_encode([
                'success' => true,
                'data' => [
                    'status' => 'blocked',
                    'spam_score' => 30.0,
                    'symbols' => [],
                ],
            ]) ?: '{}'),
        ]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'spam_threshold' => 0.70,
            'suspicious_threshold' => 0.40,
            'enable_content_check' => 1,
            'action_blocked' => 'queue',
        ]);

        $decision = $scanner->checkContent('hello', 'com_content.article', null);

        self::assertSame(Decision::MODERATE, $decision->action);
        self::assertSame(Decision::STATUS_BLOCKED, $decision->status);
    }

    public function testModeratesWhenScoreInSuspiciousZone(): void
    {
        $http = new StubHttpClient([
            new HttpResponse(200, json_encode([
                'success' => true,
                'data' => [
                    'status' => 'suspicious',
                    'spam_score' => 15.0, // 0.5 normalised
                    'symbols' => [],
                ],
            ]) ?: '{}'),
        ]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'spam_threshold' => 0.70,
            'suspicious_threshold' => 0.40,
            'enable_content_check' => 1,
        ]);

        $decision = $scanner->checkContent('borderline content', 'com_content.article', null);

        self::assertSame(Decision::MODERATE, $decision->action);
        self::assertSame(Decision::STATUS_SUSPICIOUS, $decision->status);
    }

    public function testAllowsWhenScoreBelowSuspiciousThreshold(): void
    {
        $http = new StubHttpClient([
            new HttpResponse(200, json_encode([
                'success' => true,
                'data' => [
                    'status' => 'safe',
                    'spam_score' => 3.0, // 0.1 normalised
                    'symbols' => [],
                ],
            ]) ?: '{}'),
        ]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'spam_threshold' => 0.70,
            'suspicious_threshold' => 0.40,
            'enable_content_check' => 1,
        ]);

        $decision = $scanner->checkContent('totally fine content', 'com_content.article', null);

        self::assertSame(Decision::ALLOW, $decision->action);
        self::assertSame(Decision::STATUS_SAFE, $decision->status);
    }

    public function testFailsOpenOnHttp500(): void
    {
        $http = new StubHttpClient([
            new HttpResponse(500, '{"error":"upstream"}'),
            new HttpResponse(500, '{"error":"upstream"}'),
            new HttpResponse(500, '{"error":"upstream"}'),
        ]);
        $scanner = $this->buildScanner($http, [
            'api_key' => 'k_demo',
            'enable_content_check' => 1,
        ]);

        $decision = $scanner->checkContent('foo', 'com_content.article', null);

        self::assertSame(Decision::ALLOW, $decision->action);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildScanner(StubHttpClient $http, array $config): Scanner
    {
        $factory = new ClientFactory($http);
        $logger = new Logger(new InMemoryDatabase());
        return new Scanner($factory, $logger, $config);
    }
}
