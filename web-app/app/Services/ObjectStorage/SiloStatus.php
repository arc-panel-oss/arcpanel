<?php

namespace App\Services\ObjectStorage;

/**
 * The object storage service of the server, `v-list-sys-silo json` (E44
 * spec §4): installed, version, data path, endpoint, state and the totals
 * of every account's buckets.
 */
final class SiloStatus
{
    public function __construct(
        public readonly bool $installed,
        public readonly string $version,
        public readonly string $dataPath,
        public readonly string $hostname,
        public readonly string $endpoint,
        public readonly bool $running,
        public readonly string $region,
        public readonly int $accounts,
        public readonly int $buckets,
        public readonly int $objects,
        public readonly int $bytes,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            installed: $value('INSTALLED') === 'yes',
            version: $value('VERSION'),
            dataPath: $value('DATA'),
            hostname: $value('HOSTNAME'),
            endpoint: $value('ENDPOINT'),
            running: $value('STATE') === 'running',
            region: $value('REGION') ?: 'us-east-1',
            accounts: (int) $value('ACCOUNTS'),
            buckets: (int) $value('BUCKETS'),
            objects: (int) $value('OBJECTS'),
            bytes: (int) $value('BYTES'),
        );
    }
}
