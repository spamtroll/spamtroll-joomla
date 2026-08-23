<?php

declare(strict_types=1);

namespace Spamtroll\Joomla\Tests\Support;

use Joomla\CMS\Application\CMSApplicationInterface;

/**
 * Captures the messages the plugin pushes to the user so tests can assert on
 * them without a Joomla application.
 */
final class RecordingApplication implements CMSApplicationInterface
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $messages = [];

    public function enqueueMessage(string $msg, string $type = 'info'): void
    {
        $this->messages[] = [$msg, $type];
    }

    /**
     * @return array<int, string>
     */
    public function messagesOfType(string $type): array
    {
        $out = [];

        foreach ($this->messages as [$msg, $msgType]) {
            if ($msgType === $type) {
                $out[] = $msg;
            }
        }

        return $out;
    }
}
