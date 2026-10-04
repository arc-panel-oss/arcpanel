<?php

namespace App\Arc;

final class Options
{
    public function __construct(
        /** Seconds; null uses config('arc.command_timeout'). */
        public ?int $timeoutSeconds = null,
        /** Return a failed result instead of throwing ArcCommandException. */
        public bool $allowFailure = false,
    ) {}

    public static function allowFailure(): self
    {
        return new self(allowFailure: true);
    }
}
