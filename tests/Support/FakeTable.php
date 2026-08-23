<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Support;

/**
 * Stands in for the `Joomla\CMS\Table\Table` instance that Joomla passes as
 * the `subject` of `onContentBeforeSave`. Only the two behaviours the plugin
 * relies on are reproduced: `getProperties()` exposes the row's columns (the
 * plugin must not fall back to `get_object_vars()`, which would miss the
 * protected ones) and `setError()` is what `AdminModel::save()` reads back
 * when a listener vetoes the save.
 */
final class FakeTable
{
    public string $title = '';

    public string $introtext = '';

    /** Deliberately not public — mirrors Table's internal columns. */
    private string $fulltext = '';

    private string $error = '';

    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(array $properties = [])
    {
        foreach ($properties as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return [
            'title' => $this->title,
            'introtext' => $this->introtext,
            'fulltext' => $this->fulltext,
        ];
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }

    public function getError(): string
    {
        return $this->error;
    }
}
