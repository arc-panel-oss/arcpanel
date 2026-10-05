<?php

namespace App\Services\Files;

/**
 * A path inside a user's home directory, relative to it (E3 spec §6.1).
 * "." and ".." are resolved here; a path that would leave the home, an
 * absolute path and control characters are refused. The commands check the
 * resolved path again on the server (symlinks), so this check exists for
 * clear errors and to never pass garbage to sudo.
 */
final class FilePath
{
    /** @param  list<string>  $segments */
    private function __construct(private readonly array $segments) {}

    public static function root(): self
    {
        return new self([]);
    }

    /** @throws InvalidFilePath */
    public static function from(?string $path): self
    {
        $path ??= '';
        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || str_starts_with($path, '/') || strlen($path) > 4096) {
            throw new InvalidFilePath;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new InvalidFilePath;
                }
                array_pop($segments);

                continue;
            }
            if (strlen($segment) > 255) {
                throw new InvalidFilePath;
            }
            $segments[] = $segment;
        }

        return new self($segments);
    }

    /** A single file name: no slash, not "." or "..", no control character. */
    public static function validName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && strlen($name) <= 255
            && ! str_contains($name, '/') && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1;
    }

    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    /** "" for the home directory itself */
    public function relative(): string
    {
        return implode('/', $this->segments);
    }

    public function absolute(string $home): string
    {
        $home = rtrim($home, '/');

        return $this->isRoot() ? $home : $home.'/'.$this->relative();
    }

    public function name(): string
    {
        return $this->segments === [] ? '' : $this->segments[array_key_last($this->segments)];
    }

    public function parent(): self
    {
        return new self(array_slice($this->segments, 0, -1));
    }

    /** @throws InvalidFilePath */
    public function child(string $name): self
    {
        if (! self::validName($name)) {
            throw new InvalidFilePath;
        }

        return new self([...$this->segments, $name]);
    }

    /** @throws InvalidFilePath */
    public function join(string $relative): self
    {
        $joined = $this;
        foreach (explode('/', $relative) as $segment) {
            if ($segment !== '' && $segment !== '.') {
                $joined = $joined->child($segment);
            }
        }

        return $joined;
    }

    /** Whether $other is this path or below it. */
    public function contains(self $other): bool
    {
        return array_slice($other->segments, 0, count($this->segments)) === $this->segments;
    }

    public function equals(self $other): bool
    {
        return $this->segments === $other->segments;
    }
}
