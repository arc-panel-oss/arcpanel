<?php

namespace App\Arc;

final class CommandResult
{
    /**
     * @param  list<string>  $maskedArgs  arguments as they may appear in logs
     */
    public function __construct(
        public readonly string $command,
        public readonly array $maskedArgs,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr = '',
        public readonly float $durationMs = 0.0,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $out = rtrim($this->stdout, "\n");

        return $out === '' ? [] : explode("\n", $out);
    }

    public function exit(): ?ExitCode
    {
        return ExitCode::tryFrom($this->exitCode);
    }

    /** The message a v-* command printed, as the legacy app shows it. */
    public function message(): string
    {
        $text = trim($this->stdout) !== '' ? $this->stdout : $this->stderr;

        return trim($text);
    }

    /** The message of a failed command, or its exit code when it printed nothing. */
    public function errorMessage(): string
    {
        $message = $this->message();

        return $message !== '' ? $message : sprintf(__('Error code: %s'), $this->exitCode);
    }
}
