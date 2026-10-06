<?php

namespace App\Services\Keys;

/**
 * An API access key of `v-list-access-keys USER json` or
 * `v-list-access-key KEY json`. The secret is only kept for the answer of
 * `v-add-access-key` (spec §8.2); every other record drops it.
 */
final class AccessKey
{
    /** @param  list<string>  $permissions */
    public function __construct(
        public readonly string $id,
        public readonly string $user,
        public readonly array $permissions,
        public readonly string $comment,
        public readonly string $date,
        public readonly string $time,
        public readonly ?string $secret = null,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row, bool $withSecret = false): self
    {
        $permissions = $row['PERMISSIONS'] ?? [];

        return new self(
            id: (string) ($row['ACCESS_KEY_ID'] ?? ''),
            user: (string) ($row['USER'] ?? ''),
            permissions: is_array($permissions) ? array_values(array_map('strval', $permissions)) : [],
            comment: (string) ($row['COMMENT'] ?? ''),
            date: (string) ($row['DATE'] ?? ''),
            time: (string) ($row['TIME'] ?? ''),
            secret: $withSecret && isset($row['SECRET_ACCESS_KEY']) ? (string) $row['SECRET_ACCESS_KEY'] : null,
        );
    }
}
