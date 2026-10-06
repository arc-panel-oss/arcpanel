<?php

namespace App\Services\Firewall;

/**
 * One banned address of `v-list-firewall-ban json` (keyed by IP).
 */
final class FirewallBan
{
    public function __construct(
        public readonly string $ip,
        public readonly string $chain,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $ip, array $row): self
    {
        return new self(
            ip: (string) ($row['IP'] ?? $ip),
            chain: (string) ($row['CHAIN'] ?? ''),
            date: (string) ($row['DATE'] ?? ''),
            time: (string) ($row['TIME'] ?? ''),
        );
    }

    /** The bulk item: chain first (spec §12.3). */
    public function item(): string
    {
        return $this->chain.':'.$this->ip;
    }
}
