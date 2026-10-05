<?php

namespace App\Services\Dns;

/**
 * One record of a DNS zone as `v-list-dns-records USER DOMAIN json` returns it.
 */
final class DnsRecordRecord
{
    public function __construct(
        public readonly string $user,
        public readonly string $domain,
        public readonly string $id,
        public readonly string $record,
        public readonly string $type,
        public readonly string $priority,
        public readonly string $value,
        /** '' when the record uses the zone's default TTL */
        public readonly string $ttl,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $domain, string $id, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            user: $user,
            domain: $domain,
            id: $value('ID') !== '' ? $value('ID') : $id,
            record: $value('RECORD'),
            type: $value('TYPE'),
            priority: $value('PRIORITY'),
            value: (string) ($row['VALUE'] ?? ''),
            ttl: $value('TTL'),
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }

    /** MX and SRV records carry a priority (templates/pages/add_dns_rec.php). */
    public function hasPriority(): bool
    {
        return in_array($this->type, DnsService::PRIORITY_TYPES, true);
    }

    /**
     * The fields of the record form.
     *
     * @return array{record: string, type: string, value: string, priority: string, ttl: string}
     */
    public function fields(): array
    {
        return ['record' => $this->record, 'type' => $this->type, 'value' => $this->value, 'priority' => $this->priority, 'ttl' => $this->ttl];
    }
}
