<?php

namespace App\Services\Firewall;

/**
 * `v-check-firewall json` (E40 D13): whether the loaded nftables table is the
 * one the panel loaded last.
 */
final class FirewallStatus
{
    public const STATUSES = ['ok', 'drift', 'missing', 'off'];

    public function __construct(
        public readonly string $engine,
        public readonly string $status,
        public readonly string $checkedAt,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $status = (string) ($row['STATUS'] ?? '');

        return new self(
            engine: (string) ($row['ENGINE'] ?? ''),
            status: in_array($status, self::STATUSES, true) ? $status : 'missing',
            checkedAt: (string) ($row['CHECKED'] ?? ''),
        );
    }

    /** The table that runs differs from the configuration or is gone. */
    public function needsReload(): bool
    {
        return $this->status === 'drift' || $this->status === 'missing';
    }
}
