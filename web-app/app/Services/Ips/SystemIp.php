<?php

namespace App\Services\Ips;

/**
 * One address of `v-list-sys-ips json` / `v-list-sys-ip IP json`.
 */
final class SystemIp
{
    public function __construct(
        public readonly string $ip,
        public readonly string $netmask,
        public readonly string $interface,
        public readonly string $status,
        public readonly string $owner,
        public readonly string $name,
        public readonly string $nat,
        public readonly string $domains,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $ip, array $row): self
    {
        return new self(
            ip: $ip,
            netmask: (string) ($row['NETMASK'] ?? ''),
            interface: (string) ($row['INTERFACE'] ?? ''),
            status: (string) ($row['STATUS'] ?? ''),
            owner: (string) ($row['OWNER'] ?? ''),
            name: (string) ($row['NAME'] ?? ''),
            nat: (string) ($row['NAT'] ?? ''),
            domains: (string) ($row['U_WEB_DOMAINS'] ?? '0'),
            date: (string) ($row['DATE'] ?? ''),
            time: (string) ($row['TIME'] ?? ''),
        );
    }

    public function shared(): bool
    {
        return $this->status === 'shared';
    }
}
