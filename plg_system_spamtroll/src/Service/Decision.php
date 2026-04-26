<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Spamtroll\Service;

/**
 * Verdict produced by {@see Scanner} for a single submission.
 *
 * Plain class instead of an enum so the value object can carry the score and
 * the list of detection symbols alongside the action label.
 */
final class Decision
{
    public const ALLOW = 'allow';
    public const MODERATE = 'moderate';
    public const BLOCK = 'block';

    public const STATUS_SAFE = 'safe';
    public const STATUS_SUSPICIOUS = 'suspicious';
    public const STATUS_BLOCKED = 'blocked';

    public string $action;

    public string $status;

    public float $score;

    /** @var array<int, string> */
    public array $symbols;

    /**
     * @param array<int, string> $symbols
     */
    public function __construct(string $action, string $status, float $score, array $symbols = [])
    {
        $this->action = $action;
        $this->status = $status;
        $this->score = $score;
        $this->symbols = $symbols;
    }

    public static function allow(): self
    {
        return new self(self::ALLOW, self::STATUS_SAFE, 0.0, []);
    }

    public function isBlocked(): bool
    {
        return $this->action === self::BLOCK;
    }

    public function isModerated(): bool
    {
        return $this->action === self::MODERATE;
    }
}
