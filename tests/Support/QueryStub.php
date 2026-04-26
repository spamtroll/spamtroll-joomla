<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Support;

/**
 * Tiny stand-in for `JDatabaseQuery` used by {@see InMemoryDatabase}. It
 * captures fragments and serialises to a single SQL-ish string via
 * {@see __toString()} so the {@see \Joomla\Plugin\System\Spamtroll\Service\Logger}
 * can be exercised end-to-end in unit tests.
 */
final class QueryStub
{
    private string $verb = '';

    private string $table = '';

    /** @var array<int, string> */
    private array $columns = [];

    /** @var array<int, string> */
    private array $values = [];

    /** @var array<int, string> */
    private array $where = [];

    public function insert(string $table): self
    {
        $this->verb = 'INSERT';
        $this->table = $table;
        return $this;
    }

    public function delete(string $table): self
    {
        $this->verb = 'DELETE';
        $this->table = $table;
        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function values(string $values): self
    {
        $this->values[] = $values;
        return $this;
    }

    public function where(string $clause): self
    {
        $this->where[] = $clause;
        return $this;
    }

    public function __toString(): string
    {
        if ($this->verb === 'INSERT') {
            return sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table,
                implode(',', $this->columns),
                implode('),(', $this->values)
            );
        }
        if ($this->verb === 'DELETE') {
            $clause = $this->where === [] ? '' : ' WHERE ' . implode(' AND ', $this->where);
            return sprintf('DELETE FROM %s%s', $this->table, $clause);
        }
        return '';
    }
}
