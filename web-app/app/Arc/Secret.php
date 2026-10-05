<?php

namespace App\Arc;

use Stringable;

/**
 * A secret command argument (password, hash, key).
 *
 * A file secret is written to a 0600 temporary file and the command receives
 * the file path; use it for commands that read the value from a file
 * (v-check-user-password, v-check-user-hash). A directory secret is a 0700
 * temporary directory of 0600 files, for commands that read several files
 * from a directory (v-add-web-domain-ssl SSL_DIR). An inline secret is passed
 * as the argument itself. All are masked in every log line and exception.
 */
final class Secret implements Stringable
{
    /**
     * @param  array<string, string>|null  $files  file name => content (directory secrets)
     */
    private function __construct(
        private readonly string $value,
        public readonly bool $viaFile,
        private readonly ?array $files = null,
    ) {}

    public static function file(string $value): self
    {
        return new self($value, true);
    }

    public static function inline(string $value): self
    {
        return new self($value, false);
    }

    /** @param  array<string, string>  $files  file name => content */
    public static function directory(array $files): self
    {
        foreach (array_keys($files) as $name) {
            if (! is_string($name) || preg_match('/^[\w.-]+$/', $name) !== 1 || str_contains($name, '..')) {
                throw new \InvalidArgumentException('Invalid file name in a secret directory.');
            }
        }

        return new self('', true, $files);
    }

    public function isDirectory(): bool
    {
        return $this->files !== null;
    }

    /** @return array<string, string> */
    public function files(): array
    {
        return $this->files ?? [];
    }

    /**
     * The secret value. A directory secret reveals its files as JSON, which
     * is what the test fake records.
     */
    public function reveal(): string
    {
        return $this->files !== null
            ? json_encode($this->files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : $this->value;
    }

    public function __toString(): string
    {
        return '******';
    }

    public function __debugInfo(): array
    {
        return ['value' => '******', 'viaFile' => $this->viaFile, 'directory' => $this->files !== null];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A Secret cannot be serialized.');
    }
}
