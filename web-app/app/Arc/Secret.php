<?php

namespace App\Arc;

use Stringable;

/**
 * A secret command argument (password, hash, key).
 *
 * A file secret is written to a 0600 temporary file and the command receives
 * the file path; use it for commands that read the value from a file
 * (v-check-user-password, v-check-user-hash). An inline secret is passed as
 * the argument itself. Both are masked in every log line and exception.
 */
final class Secret implements Stringable
{
    private function __construct(
        private readonly string $value,
        public readonly bool $viaFile,
    ) {}

    public static function file(string $value): self
    {
        return new self($value, true);
    }

    public static function inline(string $value): self
    {
        return new self($value, false);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return '******';
    }

    public function __debugInfo(): array
    {
        return ['value' => '******', 'viaFile' => $this->viaFile];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A Secret cannot be serialized.');
    }
}
