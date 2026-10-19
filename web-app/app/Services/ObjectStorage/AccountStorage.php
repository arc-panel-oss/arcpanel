<?php

namespace App\Services\ObjectStorage;

/**
 * The object storage of one account, `v-list-user-object-storage USER json`
 * (E44 spec §6): whether its package includes it, the limits and what the
 * account holds against them, and how to connect.
 */
final class AccountStorage
{
    /** Silo's S3 port on this server (func/silo.sh SILO_PORT): apps of the server reach it without the public endpoint. */
    public const LOCAL_ENDPOINT = 'http://127.0.0.1:9310';

    public function __construct(
        public readonly bool $installed,
        public readonly bool $allowed,
        public readonly bool $backup,
        public readonly int $buckets,
        public readonly ?int $bucketsLimit,
        public readonly int $quotaUsedMb,
        public readonly ?int $quotaLimitMb,
        public readonly int $usedBytes,
        public readonly int $objects,
        public readonly int $keys,
        public readonly int $keysLimit,
        public readonly string $endpoint,
        public readonly string $region,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));
        $limit = fn (string $key): ?int => $value($key) === 'unlimited' ? null : (int) $value($key);

        return new self(
            installed: $value('INSTALLED') === 'yes',
            allowed: $value('ALLOWED') === 'yes',
            backup: $value('BACKUP') === 'yes',
            buckets: (int) $value('BUCKETS'),
            bucketsLimit: $limit('BUCKETS_LIMIT'),
            quotaUsedMb: (int) $value('QUOTA_USED'),
            quotaLimitMb: $limit('QUOTA_LIMIT'),
            usedBytes: (int) $value('USED'),
            objects: (int) $value('OBJECTS'),
            keys: (int) $value('KEYS'),
            keysLimit: (int) ($value('KEYS_LIMIT') ?: 20),
            endpoint: $value('ENDPOINT'),
            region: $value('REGION') ?: 'us-east-1',
        );
    }

    /** The endpoint for the examples: the public one, or the local one until the administrator publishes it. */
    public function connectEndpoint(): string
    {
        return $this->endpoint !== '' ? $this->endpoint : self::LOCAL_ENDPOINT;
    }

    /** Whether the account may add a bucket now. */
    public function canAddBucket(): bool
    {
        return $this->installed && $this->allowed && ($this->bucketsLimit === null || $this->buckets < $this->bucketsLimit)
            && ($this->quotaLimitMb === null || $this->quotaUsedMb < $this->quotaLimitMb);
    }

    /** MB the bucket quotas may still grow by, null without a limit. */
    public function quotaLeftMb(): ?int
    {
        return $this->quotaLimitMb === null ? null : max(0, $this->quotaLimitMb - $this->quotaUsedMb);
    }
}
