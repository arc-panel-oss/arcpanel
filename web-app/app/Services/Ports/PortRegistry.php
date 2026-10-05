<?php

namespace App\Services\Ports;

/**
 * The whole registry for Admin → Server → Ports (`v-list-sys-ports json`):
 * per-user totals, every entry, reserved ports, listening sockets in the
 * server range and the reconciliation findings.
 */
final class PortRegistry
{
    /**
     * @param  array<string, array<string, string>>  $users  name => OWNER, ROLE, LIMIT, ALLOCATED …
     * @param  list<PortEntry>  $entries
     * @param  list<PortEntry>  $reserved
     * @param  list<array<string, string>>  $sockets
     * @param  list<PortFinding>  $findings
     */
    public function __construct(
        public readonly int $rangeStart,
        public readonly int $rangeEnd,
        public readonly bool $enabled,
        public readonly string $firewall,
        public readonly array $users,
        public readonly array $entries,
        public readonly array $reserved,
        public readonly array $sockets,
        public readonly array $findings,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $list = fn (string $key) => is_array($data[$key] ?? null) ? array_values(array_filter($data[$key], 'is_array')) : [];
        $users = [];
        foreach (is_array($data['USERS'] ?? null) ? $data['USERS'] : [] as $name => $row) {
            if (is_string($name) && is_array($row)) {
                $users[$name] = array_map('strval', array_filter($row, 'is_scalar'));
            }
        }

        return new self(
            rangeStart: (int) ($data['RANGE_START'] ?? 30000),
            rangeEnd: (int) ($data['RANGE_END'] ?? 39999),
            enabled: ($data['REGISTRY'] ?? 'yes') !== 'no',
            firewall: is_scalar($data['FIREWALL'] ?? null) ? (string) $data['FIREWALL'] : '',
            users: $users,
            entries: array_map(fn (array $e) => PortEntry::from($e), $list('ENTRIES')),
            reserved: array_map(fn (array $e) => PortEntry::from($e), $list('RESERVED')),
            sockets: array_map(fn (array $s) => array_map('strval', array_filter($s, 'is_scalar')), $list('SOCKETS')),
            findings: array_map(fn (array $f) => PortFinding::from($f), $list('FINDINGS')),
        );
    }

    public function firewallOn(): bool
    {
        return $this->firewall !== '';
    }

    /** @return list<PortEntry> */
    public function entriesOf(string $user): array
    {
        return array_values(array_filter($this->entries, fn (PortEntry $e) => $e->user === $user));
    }

    /**
     * Users that hold ports or have a PORTS limit, by name.
     *
     * @return array<string, array<string, string>>
     */
    public function usersWithPorts(): array
    {
        return array_filter($this->users, fn (array $row) => (int) ($row['ALLOCATED'] ?? 0) > 0 || ! in_array($row['LIMIT'] ?? '0', ['0', ''], true));
    }

    /** The entry that holds PORT, or null. */
    public function holderOf(int $port): ?PortEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->start <= $port && $entry->end >= $port) {
                return $entry;
            }
        }

        return null;
    }
}
