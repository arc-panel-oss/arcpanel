<?php

namespace App\Services\Firewall;

use App\Policies\FirewallPolicy;

/**
 * One rule of `v-list-firewall json`; the key of the row is its position.
 */
final class FirewallRule
{
    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly string $protocol,
        public readonly string $port,
        public readonly string $ip,
        public readonly string $comment,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $id, array $row): self
    {
        return new self(
            id: $id,
            action: (string) ($row['ACTION'] ?? ''),
            protocol: (string) ($row['PROTOCOL'] ?? ''),
            port: (string) ($row['PORT'] ?? ''),
            ip: (string) ($row['IP'] ?? ''),
            comment: (string) ($row['COMMENT'] ?? ''),
            suspended: ($row['SUSPENDED'] ?? '') === 'yes',
            date: (string) ($row['DATE'] ?? ''),
            time: (string) ($row['TIME'] ?? ''),
        );
    }

    /** Written by the port registry (E12 spec D7): changed through the Ports pages only. */
    public function managed(): bool
    {
        return FirewallPolicy::isManaged($this->comment);
    }
}
