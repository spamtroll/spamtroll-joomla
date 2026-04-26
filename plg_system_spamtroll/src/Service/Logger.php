<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Service;

use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Persists scan results to `#__spamtroll_log` and prunes old entries.
 *
 * Failures here are swallowed and reported through the Joomla log facility
 * so the scanner is never disrupted by storage problems — fail-open extends
 * to the audit log as well.
 */
final class Logger
{
    private const TABLE = '#__spamtroll_log';

    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<int, string> $symbols
     */
    public function record(
        string $source,
        string $status,
        float $score,
        string $contentHash,
        ?string $ip,
        ?string $email,
        ?string $username,
        array $symbols,
    ): void {
        try {
            $columns = ['created', 'source', 'status', 'score', 'content_hash', 'ip', 'email', 'username', 'symbols'];
            $values = [
                $this->db->quote(gmdate('Y-m-d H:i:s')),
                $this->db->quote($source),
                $this->db->quote($status),
                (string) $score,
                $this->db->quote($contentHash),
                $this->db->quote((string) ($ip ?? '')),
                $this->db->quote((string) ($email ?? '')),
                $this->db->quote((string) ($username ?? '')),
                $this->db->quote($this->encodeSymbols($symbols)),
            ];

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName(self::TABLE))
                ->columns(array_map([$this->db, 'quoteName'], $columns))
                ->values(implode(',', $values));

            $this->db->setQuery($query)->execute();
        } catch (Throwable $e) {
            // Swallow — audit logging must never break the request.
            error_log('Spamtroll: failed to write scan log entry: ' . $e->getMessage());
        }
    }

    /**
     * Delete entries older than the configured retention window.
     *
     * @return int Number of rows pruned (or 0 on failure).
     */
    public function cleanup(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('created') . ' < ' . $this->db->quote($cutoff));

            $this->db->setQuery($query)->execute();
            $affected = $this->db->getAffectedRows();
            return is_int($affected) ? $affected : 0;
        } catch (Throwable $e) {
            error_log('Spamtroll: failed to prune log entries: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @param array<int, string> $symbols
     */
    private function encodeSymbols(array $symbols): string
    {
        $encoded = json_encode(array_values($symbols));
        return $encoded === false ? '[]' : $encoded;
    }
}
