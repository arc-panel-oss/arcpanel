<?php

namespace App\Services\WebDomains;

/**
 * Outcome of running a plan: the steps that ran, and the step that failed
 * with the command's message. Steps that ran before a failure stay applied.
 */
final class ApplyResult
{
    /**
     * @param  list<Step>  $applied
     * @param  array<string, string>  $stdoutByCommand  stdout of each step that ran
     */
    public function __construct(
        public readonly array $applied,
        public readonly ?Step $failed = null,
        public readonly string $error = '',
        /** exit code of the failed command, 0 when it did not run */
        public readonly int $exitCode = 0,
        public readonly array $stdoutByCommand = [],
    ) {}

    public function stdoutOf(string $command): ?string
    {
        if (! array_key_exists($command, $this->stdoutByCommand)) {
            return null;
        }

        return trim($this->stdoutByCommand[$command]);
    }

    public function ok(): bool
    {
        return $this->failed === null;
    }

    public function changed(): bool
    {
        return $this->applied !== [];
    }
}
