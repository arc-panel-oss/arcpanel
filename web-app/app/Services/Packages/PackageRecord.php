<?php

namespace App\Services\Packages;

/**
 * A hosting package with its reseller settings (`v-list-package-tenancy
 * json`, E11 spec §8).
 */
final class PackageRecord
{
    /** Limits shown and edited as "number or unlimited". */
    public const COUNTED = ['WEB_DOMAINS', 'WEB_ALIASES', 'DNS_DOMAINS', 'DNS_RECORDS', 'MAIL_DOMAINS', 'MAIL_ACCOUNTS', 'DATABASES', 'CRON_JOBS', 'DISK_QUOTA', 'BANDWIDTH'];

    /** cgroup limits, only with RESOURCES_LIMIT='yes' (values such as 50%, 100ms, 512M). */
    public const RESOURCES = ['CPU_QUOTA', 'CPU_QUOTA_PERIOD', 'MEMORY_LIMIT', 'SWAP_LIMIT'];

    /** @param  array<string, string>  $data */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly int $users = 0,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(string $name, array $data, int $users = 0): self
    {
        return new self($name, array_map('strval', array_filter($data, 'is_scalar')), $users);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->data[$key] ?? $default;
    }

    /** Reseller that created the package, or empty. */
    public function owner(): string
    {
        return $this->get('RESELLER_OWNER');
    }

    public function visibleToResellers(): bool
    {
        return $this->get('RESELLER_VISIBLE') === 'yes';
    }

    /** @return list<string> */
    public function nameservers(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get('NS')))));
    }
}
