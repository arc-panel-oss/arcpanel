<?php

namespace App\Services\Firewall;

/**
 * `v-check-firewall json` (E40 D13): whether the loaded nftables table is the
 * one the panel loaded last.
 */
final class FirewallStatus
{
    public const STATUSES = ['ok', 'drift', 'missing', 'off'];

    /**
     * Warnings that leave the status as it is (E40 spec §16): a server that
     * the upgrade left on iptables, an outdated Fail2Ban action, the
     * distribution's nftables.service in use, SSH or the panel open to IPv4
     * sources only on a server with IPv6.
     */
    public const WARNINGS = ['iptables-engine', 'fail2ban-action', 'nftables-service', 'ipv6-ssh', 'ipv6-panel'];

    /** @param  list<string>  $warnings */
    public function __construct(
        public readonly string $engine,
        public readonly string $status,
        public readonly string $checkedAt,
        public readonly array $warnings = [],
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $status = (string) ($row['STATUS'] ?? '');
        $warnings = is_array($row['WARNINGS'] ?? null) ? $row['WARNINGS'] : [];

        return new self(
            engine: (string) ($row['ENGINE'] ?? ''),
            status: in_array($status, self::STATUSES, true) ? $status : 'missing',
            checkedAt: (string) ($row['CHECKED'] ?? ''),
            warnings: array_values(array_intersect(self::WARNINGS, $warnings)),
        );
    }

    /**
     * The table that runs differs from the configuration or is gone. A server
     * left on iptables has no table either; its own warning says why.
     */
    public function needsReload(): bool
    {
        return ($this->status === 'drift' || $this->status === 'missing') && ! in_array('iptables-engine', $this->warnings, true);
    }
}
