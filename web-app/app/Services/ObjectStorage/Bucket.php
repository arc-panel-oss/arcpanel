<?php

namespace App\Services\ObjectStorage;

/**
 * One bucket of `v-list-user-bucket(s) USER [BUCKET] json` or of
 * `v-list-sys-buckets` (E44 spec §6). Usage is what the last repair counted.
 */
final class Bucket
{
    public function __construct(
        public readonly string $user,
        public readonly string $name,
        public readonly int $quotaMb,
        public readonly bool $versioning,
        public readonly int $usedBytes,
        public readonly int $objects,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
        /** Above its quota on disk: the repair denies writes (E39 W5 N25) */
        public readonly bool $full = false,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $name, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            user: $user,
            name: $value('BUCKET') !== '' ? $value('BUCKET') : $name,
            quotaMb: (int) $value('QUOTA'),
            versioning: $value('VERSIONING') === 'yes',
            usedBytes: (int) $value('USED'),
            objects: (int) $value('OBJECTS'),
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
            full: $value('FULL') === 'yes',
        );
    }

    /** Usage as a share of the quota, 0–100 (above 100 when over it). */
    public function percent(): int
    {
        return $this->quotaMb > 0 ? (int) round($this->usedBytes / ($this->quotaMb * 1048576) * 100) : 0;
    }
}
