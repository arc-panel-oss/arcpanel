<?php

namespace App\Services\Files;

/**
 * One fetch of upload from URL, a record of `v-list-fs-fetches USER json`
 * (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §4.2).
 */
final class FileFetch
{
    public const STATUSES = ['queued', 'running', 'done', 'skipped', 'failed', 'cancelled'];

    public function __construct(
        public readonly string $id,
        public readonly string $url,
        /** Folder relative to the home directory */
        public readonly string $path,
        public readonly string $name,
        public readonly string $status,
        public readonly int $bytes,
        public readonly ?int $total,
        public readonly string $error,
        public readonly ?int $http,
        public readonly string $created,
        public readonly string $started,
        public readonly string $ended,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function from(array $row, string $home): self
    {
        $value = fn (string $key) => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        $dir = $value('DIR');
        $path = $dir === $home ? '' : (str_starts_with($dir, $home.'/') ? substr($dir, strlen($home) + 1) : '');

        return new self(
            id: $value('ID'),
            url: $value('URL'),
            path: $path,
            name: $value('NAME'),
            status: in_array($value('STATUS'), self::STATUSES, true) ? $value('STATUS') : 'failed',
            bytes: (int) $value('BYTES'),
            total: ctype_digit($value('TOTAL')) ? (int) $value('TOTAL') : null,
            error: $value('ERROR'),
            http: ctype_digit($value('HTTP')) ? (int) $value('HTTP') : null,
            created: $value('CREATED'),
            started: $value('START'),
            ended: $value('END'),
        );
    }

    public function finished(): bool
    {
        return in_array($this->status, ['done', 'skipped', 'failed', 'cancelled'], true);
    }

    public function active(): bool
    {
        return ! $this->finished();
    }

    /** Percent received, or null while the size is unknown */
    public function percent(): ?int
    {
        if ($this->status === 'done') {
            return 100;
        }
        if ($this->total === null || $this->total === 0) {
            return null;
        }

        return min(100, intdiv($this->bytes * 100, $this->total));
    }

    /** @return array<string, string|int|bool|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'path' => $this->path,
            'name' => $this->name,
            'status' => $this->status,
            'bytes' => $this->bytes,
            'total' => $this->total,
            'percent' => $this->percent(),
            'error' => $this->error,
            'http' => $this->http,
            'created' => $this->created,
            'started' => $this->started,
            'ended' => $this->ended,
        ];
    }
}
