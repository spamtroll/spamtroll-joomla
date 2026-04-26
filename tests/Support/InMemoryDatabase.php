<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Support;

use Joomla\Database\DatabaseInterface;

/**
 * Minimal in-memory implementation of {@see DatabaseInterface} that records
 * SQL fragments instead of executing them. The {@see Logger} only needs the
 * fluent query builder hooks plus `setQuery()->execute()`.
 */
final class InMemoryDatabase implements DatabaseInterface
{
    /** @var array<int, string> */
    public array $executedQueries = [];

    private int $affectedRows = 0;

    public function getQuery(bool $new = false): QueryStub
    {
        unset($new);
        return new QueryStub();
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function quoteName(string $name): string
    {
        return '`' . $name . '`';
    }

    public function setQuery(mixed $query): self
    {
        $this->executedQueries[] = (string) $query;
        return $this;
    }

    public function execute(): bool
    {
        $this->affectedRows = 1;
        return true;
    }

    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }
}
