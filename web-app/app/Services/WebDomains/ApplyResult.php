<?php

namespace App\Services\WebDomains;

/**
 * Outcome of running a plan: the steps that ran, and the step that failed
 * with the command's message. Steps that ran before a failure stay applied.
 */
final class ApplyResult
{
    /** @param  list<Step>  $applied */
    public function __construct(
        public readonly array $applied,
        public readonly ?Step $failed = null,
        public readonly string $error = '',
    ) {}

    public function ok(): bool
    {
        return $this->failed === null;
    }

    public function changed(): bool
    {
        return $this->applied !== [];
    }
}
