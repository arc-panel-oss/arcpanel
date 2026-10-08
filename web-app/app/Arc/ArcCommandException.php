<?php

namespace App\Arc;

use RuntimeException;

/**
 * A v-* command exited with a non-zero code, or was refused before it ran.
 */
class ArcCommandException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?CommandResult $result = null,
        /** The command did not run: one of its arguments is not allowed (CommandArguments). */
        public readonly bool $invalidArgument = false,
    ) {
        parent::__construct($message, $result?->exitCode ?? 0);
    }

    public static function failed(CommandResult $result): self
    {
        $detail = $result->message() !== '' ? $result->message() : 'no output';

        return new self(
            sprintf('%s exited with code %d: %s', $result->command, $result->exitCode, $detail),
            $result,
        );
    }

    public static function refused(string $command, string $reason): self
    {
        return new self(sprintf('Refused to run %s: %s', $command, $reason));
    }

    /** An argument value the command line cannot carry (a line break, a NUL byte): the command did not run. */
    public static function invalidArgument(string $command, string $reason): self
    {
        return new self(sprintf('Refused to run %s: %s', $command, $reason), invalidArgument: true);
    }

    public function exit(): ?ExitCode
    {
        return $this->result?->exit();
    }

    /** Text safe to show to the user: the command's own message. */
    public function userMessage(): string
    {
        $message = $this->result?->message() ?? '';

        // Legacy msgid with a printf placeholder, so the imported translation matches.
        return $message !== '' ? $message : sprintf(__('Error code: %s'), $this->getCode());
    }

    public function httpStatus(): int
    {
        return $this->exit()?->httpStatus() ?? 500;
    }
}
