<?php

namespace App\Services\Dns;

/**
 * One DNS zone as `v-list-dns-domain(s) USER [DOMAIN] json` returns it.
 */
final class DnsZoneRecord
{
    public function __construct(
        public readonly string $user,
        public readonly string $domain,
        public readonly string $ip,
        public readonly string $template,
        public readonly string $ttl,
        public readonly string $exp,
        public readonly string $soa,
        public readonly string $serial,
        public readonly bool $dnssec,
        /** the zone this one is a copy of (DNS cluster), '' for none */
        public readonly string $src,
        public readonly int $records,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $domain, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            user: $user,
            domain: $domain,
            ip: $value('IP'),
            template: $value('TPL'),
            ttl: $value('TTL'),
            exp: $value('EXP'),
            soa: $value('SOA'),
            serial: $value('SERIAL'),
            dnssec: $value('DNSSEC') === 'yes',
            src: $value('SRC'),
            records: (int) $value('RECORDS'),
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }
}
