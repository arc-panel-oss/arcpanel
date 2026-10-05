<?php

namespace App\Services\Ports;

/**
 * The ports of one user with the totals of its pool (`v-list-user-ports USER
 * json`): LIMIT is the package PORTS, TREE_* count a reseller's customers.
 */
final class PortAccount
{
    /** @param  list<PortEntry>  $entries */
    public function __construct(
        public readonly string $user,
        public readonly string $owner,
        public readonly string $role,
        public readonly bool $suspended,
        /** integer or unlimited */
        public readonly string $limit,
        public readonly int $allocated,
        public readonly int $assigned,
        public readonly int $public,
        public readonly int $free,
        public readonly ?int $treeTotal,
        public readonly ?int $treeUsed,
        public readonly string $firewall,
        public readonly int $rangeStart,
        public readonly int $rangeEnd,
        public readonly array $entries,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(string $user, array $data): self
    {
        $value = fn (string $key) => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $entries = [];
        foreach (is_array($data['ENTRIES'] ?? null) ? $data['ENTRIES'] : [] as $entry) {
            if (is_array($entry)) {
                $entries[] = PortEntry::from($entry, $user);
            }
        }

        return new self(
            user: $user,
            owner: $value('OWNER'),
            role: $value('ROLE'),
            suspended: $value('SUSPENDED') === 'yes',
            limit: $value('LIMIT') !== '' ? $value('LIMIT') : '0',
            allocated: (int) $value('ALLOCATED'),
            assigned: (int) $value('ASSIGNED'),
            public: (int) $value('PUBLIC'),
            free: (int) $value('FREE'),
            treeTotal: $value('TREE_TOTAL') !== '' ? (int) $value('TREE_TOTAL') : null,
            treeUsed: $value('TREE_USED') !== '' ? (int) $value('TREE_USED') : null,
            firewall: $value('FIREWALL'),
            rangeStart: (int) $value('RANGE_START'),
            rangeEnd: (int) $value('RANGE_END'),
            entries: $entries,
        );
    }

    public function unlimited(): bool
    {
        return $this->limit === 'unlimited';
    }

    public function firewallOn(): bool
    {
        return $this->firewall !== '';
    }

    /** The single port entry PORT, or null. */
    public function entry(int $port): ?PortEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->start === $port && $entry->end === $port) {
                return $entry;
            }
        }

        return null;
    }

    /** Whether every port of START-END belongs to the user. */
    public function holds(int $start, int $end): bool
    {
        $held = 0;
        foreach ($this->entries as $entry) {
            $from = max($entry->start, $start);
            $to = min($entry->end, $end);
            if ($from <= $to) {
                $held += $to - $from + 1;
            }
        }

        return $held === $end - $start + 1;
    }

    /** Whether any port of START-END is allowed to be public. */
    public function publicOkIn(int $start, int $end): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->publicOk && $entry->start <= $end && $entry->end >= $start) {
                return true;
            }
        }

        return false;
    }
}
