<?php

namespace App\Services\ObjectStorage;

/**
 * One S3 key of `v-list-user-s3-keys USER json` (E44 spec §7): never the
 * secret, which only the answer of `v-add-user-s3-key` holds, once.
 */
final class S3Key
{
    public function __construct(
        public readonly string $user,
        public readonly string $key,
        public readonly string $name,
        public readonly string $bucket,
        public readonly string $access,
        public readonly string $expires,
        public readonly bool $expired,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $key, array $row): self
    {
        $value = fn (string $field): string => trim((string) ($row[$field] ?? ''));

        return new self(
            user: $user,
            key: $value('KEY') !== '' ? $value('KEY') : $key,
            name: $value('NAME'),
            bucket: $value('BUCKET'),
            access: $value('ACCESS') === 'ro' ? 'ro' : 'rw',
            expires: $value('EXPIRES'),
            expired: $value('EXPIRED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }
}
