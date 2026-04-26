<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Service;

use Joomla\CMS\Log\Log;
use Spamtroll\Sdk\Exception\SpamtrollException;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Throwable;

/**
 * Scanner orchestrates a single Spamtroll API call and maps the response into
 * a {@see Decision}.
 *
 * Fail-open contract: every code path that does not return an explicit
 * {@see Decision::BLOCK} verdict — including missing API key, unconfigured
 * thresholds, network errors, malformed responses or unhandled exceptions —
 * MUST produce {@see Decision::allow()}. The Spamtroll integration policy
 * forbids blocking legitimate traffic when the API is unavailable.
 */
final class Scanner
{
    public const SOURCE_REGISTRATION = CheckSpamRequest::SOURCE_REGISTRATION;
    public const SOURCE_COMMENT = CheckSpamRequest::SOURCE_COMMENT;
    public const SOURCE_GENERIC = CheckSpamRequest::SOURCE_GENERIC;

    private ClientFactory $clientFactory;

    private Logger $logger;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(ClientFactory $clientFactory, Logger $logger, array $config)
    {
        $this->clientFactory = $clientFactory;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function checkUserRegistration(string $username, string $email, ?string $ip): Decision
    {
        if ((int) ($this->config['enable_user_check'] ?? 1) !== 1) {
            return Decision::allow();
        }

        $content = trim($username . "\n" . $email);
        if ($content === '') {
            return Decision::allow();
        }

        return $this->scan($content, self::SOURCE_REGISTRATION, $ip, $username !== '' ? $username : null, $email !== '' ? $email : null);
    }

    /**
     * Scan generic content (article, comment, contact submission).
     *
     * `$context` is the Joomla context string (e.g. `com_content.article`)
     * and is currently used only for the source mapping.
     */
    public function checkContent(string $content, string $context, ?string $ip, ?string $username = null, ?string $email = null): Decision
    {
        if ((int) ($this->config['enable_content_check'] ?? 1) !== 1) {
            return Decision::allow();
        }

        $content = trim($content);
        if ($content === '') {
            return Decision::allow();
        }

        $source = $this->mapContextToSource($context);

        return $this->scan($content, $source, $ip, $username, $email);
    }

    private function scan(string $content, string $source, ?string $ip, ?string $username, ?string $email): Decision
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            // No API key configured → fail-open, but don't even hit the network.
            return Decision::allow();
        }

        $apiUrl = (string) ($this->config['api_url'] ?? '');
        $timeout = (int) ($this->config['timeout'] ?? 5);

        try {
            $client = $this->clientFactory->create($apiKey, $apiUrl, $timeout);
            $request = new CheckSpamRequest(
                $content,
                $source,
                $ip !== null && $ip !== '' ? $ip : null,
                $username !== null && $username !== '' ? $username : null,
                $email !== null && $email !== '' ? $email : null,
            );
            $response = $client->checkSpam($request);

            // Quota exhausted — record locally for the admin panel and
            // let the message through. The user's plan ran out, the
            // content itself wasn't judged.
            if ($response->httpCode === 402) {
                self::recordQuotaSkipped($response);
                return Decision::allow();
            }

            if (!$response->success) {
                Log::add(
                    'API returned error: ' . ($response->error ?? 'unknown'),
                    Log::WARNING,
                    'spamtroll'
                );
                return Decision::allow();
            }

            $score = $response->getSpamScore();
            $action = $this->determineAction($score);
            $status = $this->determineStatus($score);
            $symbols = $response->getSymbols();

            $this->logger->record(
                $source,
                $status,
                $score,
                hash('sha256', $content),
                $ip,
                $email,
                $username,
                $symbols,
            );

            return new Decision($action, $status, $score, $symbols);
        } catch (SpamtrollException $e) {
            Log::add('SDK exception during scan: ' . $e->getMessage(), Log::WARNING, 'spamtroll');
            return Decision::allow();
        } catch (Throwable $e) {
            Log::add('Unexpected error during scan: ' . $e->getMessage(), Log::WARNING, 'spamtroll');
            return Decision::allow();
        }
    }

    private function determineAction(float $score): string
    {
        $spamThreshold = (float) ($this->config['spam_threshold'] ?? 0.70);
        $suspiciousThreshold = (float) ($this->config['suspicious_threshold'] ?? 0.40);

        if ($score >= $spamThreshold) {
            $action = (string) ($this->config['action_blocked'] ?? Decision::BLOCK);
            return $action === 'queue' ? Decision::MODERATE : Decision::BLOCK;
        }

        if ($score >= $suspiciousThreshold) {
            return Decision::MODERATE;
        }

        return Decision::ALLOW;
    }

    private function determineStatus(float $score): string
    {
        $spamThreshold = (float) ($this->config['spam_threshold'] ?? 0.70);
        $suspiciousThreshold = (float) ($this->config['suspicious_threshold'] ?? 0.40);

        if ($score >= $spamThreshold) {
            return Decision::STATUS_BLOCKED;
        }

        if ($score >= $suspiciousThreshold) {
            return Decision::STATUS_SUSPICIOUS;
        }

        return Decision::STATUS_SAFE;
    }

    private function mapContextToSource(string $context): string
    {
        if ($context === '' || str_starts_with($context, 'com_content')) {
            return self::SOURCE_GENERIC;
        }
        if (str_contains($context, 'comment') || str_contains($context, 'jcomments')) {
            return self::SOURCE_COMMENT;
        }
        if (str_contains($context, 'contact')) {
            return self::SOURCE_GENERIC;
        }
        return self::SOURCE_GENERIC;
    }

    /**
     * Records a quota-exhausted scan in Joomla's params storage so the
     * plugin admin form can show "X messages skipped because you hit
     * your daily quota — upgrade your plan". Storage shape: a single
     * #__extensions params entry mirroring the layout used by the
     * other plugins (per-day count map pruned to 30 days plus the
     * latest usage block from the API).
     */
    public static function recordQuotaSkipped(\Spamtroll\Sdk\Response\CheckSpamResponse $response): void
    {
        $stored = self::loadQuotaLog();
        $byDay = isset($stored['days']) && is_array($stored['days']) ? $stored['days'] : [];
        $today = gmdate('Y-m-d');
        $byDay[$today] = (isset($byDay[$today]) ? (int) $byDay[$today] : 0) + 1;

        $cutoff = gmdate('Y-m-d', strtotime('-30 days'));
        foreach (array_keys($byDay) as $day) {
            if (!is_string($day) || $day < $cutoff) {
                unset($byDay[$day]);
            }
        }

        $usage = method_exists($response, 'getQuotaUsage') ? $response->getQuotaUsage() : [];

        self::saveQuotaLog([
            'days' => $byDay,
            'last_at' => time(),
            'last_usage' => is_array($usage) ? $usage : [],
        ]);
    }

    /**
     * Snapshot of the quota log windowed to the last $days days plus
     * the latest usage block. Always returns the canonical shape so
     * the admin form template can render without null-checks.
     *
     * @return array{total: int, today: int, days: array<string,int>, last_usage: array<string,mixed>, last_at: int}
     */
    public static function getQuotaSkippedStats(int $days = 7): array
    {
        $stored = self::loadQuotaLog();
        $byDay = isset($stored['days']) && is_array($stored['days']) ? $stored['days'] : [];
        $cutoff = gmdate('Y-m-d', strtotime('-' . max(1, $days) . ' days'));
        $window = [];
        $total = 0;
        foreach ($byDay as $day => $count) {
            if (!is_string($day) || !is_int($count) || $day < $cutoff) {
                continue;
            }
            $window[$day] = $count;
            $total += $count;
        }
        $today = gmdate('Y-m-d');
        return [
            'total' => $total,
            'today' => isset($byDay[$today]) && is_int($byDay[$today]) ? $byDay[$today] : 0,
            'days' => $window,
            'last_usage' => isset($stored['last_usage']) && is_array($stored['last_usage']) ? $stored['last_usage'] : [],
            'last_at' => isset($stored['last_at']) && is_int($stored['last_at']) ? $stored['last_at'] : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadQuotaLog(): array
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('spamtroll'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
            $params = (array) (json_decode((string) $db->setQuery($query)->loadResult(), true) ?? []);
            $log = $params['quota_skipped_log'] ?? '';
            $decoded = is_string($log) ? json_decode($log, true) : null;
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $log
     */
    private static function saveQuotaLog(array $log): void
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $select = $db->getQuery(true)
                ->select($db->quoteName(['extension_id', 'params']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('spamtroll'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
            $row = $db->setQuery($select)->loadObject();
            if (!$row) {
                return;
            }
            $params = (array) (json_decode((string) $row->params, true) ?? []);
            $params['quota_skipped_log'] = json_encode($log);

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $row->extension_id);
            $db->setQuery($update)->execute();
        } catch (Throwable $e) {
            // Never let logging break the scan path.
        }
    }
}
