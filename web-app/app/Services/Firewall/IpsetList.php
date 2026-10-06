<?php

namespace App\Services\Firewall;

/**
 * One list of `v-list-firewall-ipset json`.
 */
final class IpsetList
{
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly string $ipVersion,
        public readonly bool $autoUpdate,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $name, array $row): self
    {
        return new self(
            name: $name,
            source: (string) ($row['SOURCE'] ?? ''),
            ipVersion: (string) ($row['IP_VERSION'] ?? ''),
            // web/list/firewall/ipset: anything but "no" is enabled
            autoUpdate: ($row['AUTOUPDATE'] ?? '') !== 'no',
            suspended: ($row['SUSPENDED'] ?? '') === 'yes',
            date: (string) ($row['DATE'] ?? ''),
            time: (string) ($row['TIME'] ?? ''),
        );
    }
}
