<?php

namespace App\Services\Stats;

use DateTimeImmutable;

/**
 * One month of `v-list-user-stats`, `v-list-users-stats` or
 * `v-list-reseller-users-stats` (keyed by the month's DATE).
 */
final class StatsMonth
{
    /** The counters of web/list/stats, in its order: key => label. */
    public const COUNTERS = [
        'U_WEB_DOMAINS' => 'Web Domains',
        'U_WEB_SSL' => 'SSL Domains',
        'U_WEB_ALIASES' => 'Web Aliases',
        'U_DNS_DOMAINS' => 'DNS Zones',
        'U_DNS_RECORDS' => 'DNS Records',
        'U_MAIL_DOMAINS' => 'Mail Domains',
        'U_MAIL_ACCOUNTS' => 'Mail Accounts',
        'U_DATABASES' => 'Databases',
        'U_CRON_JOBS' => 'Cron Jobs',
        'U_BACKUPS' => 'Backups',
    ];

    /** @param  array<string, string>  $values */
    public function __construct(
        public readonly string $date,
        public readonly array $values,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $date, array $row): self
    {
        return new self($date, array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $row));
    }

    public function value(string $key): string
    {
        return $this->values[$key] ?? '0';
    }

    public function month(): ?DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $this->date) ?: null;
    }

    /** A limit the bars can use: null when there is none (unlimited, or 0 in the server totals). */
    public function limit(string $key): ?float
    {
        $value = $this->value($key);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
