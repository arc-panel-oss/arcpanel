<?php

namespace App\Http\Resources\V2;

use App\Services\Stats\StatsMonth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One month of statistics (`v-list-user-stats`, `v-list-users-stats` or
 * `v-list-reseller-users-stats`), E34 batch 6 §4.2. The counters are the
 * ten of the Statistics page (StatsMonth::COUNTERS); the monthly rows keep
 * a limit for disk and bandwidth only.
 *
 * @property StatsMonth $resource
 */
final class StatsMonthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $month = $this->resource;
        $count = fn (string $key): int => (int) $month->value($key);

        return [
            /** @var string YYYY-MM */
            'month' => substr($month->date, 0, 7),
            /** @var string the package of the account at the time; empty in totals */
            'package' => $month->values['PACKAGE'] ?? '',
            /** Usage and quota in MB; the limit is null when unlimited. */
            'disk_mb' => [
                /** @var int */
                'used' => $count('U_DISK'),
                /** @var int|null */
                'limit' => self::limit($month, 'DISK_QUOTA'),
                /** @var int */
                'web' => $count('U_DISK_WEB'),
                /** @var int */
                'mail' => $count('U_DISK_MAIL'),
                /** @var int */
                'databases' => $count('U_DISK_DB'),
                /** @var int the user's directories outside web, mail and databases */
                'directories' => $count('U_DISK_DIRS'),
            ],
            /** Traffic and its limit in MB; the limit is null when unlimited. */
            'bandwidth_mb' => [
                /** @var int */
                'used' => $count('U_BANDWIDTH'),
                /** @var int|null */
                'limit' => self::limit($month, 'BANDWIDTH'),
            ],
            /** @var int */
            'ip_addresses' => $count('IP_OWNED'),
            /** @var int|null accounts counted in server or tree totals; null for one account */
            'users' => isset($month->values['U_USERS']) ? $count('U_USERS') : null,
            /** @var int */
            'web_domains' => $count('U_WEB_DOMAINS'),
            /** @var int */
            'web_ssl' => $count('U_WEB_SSL'),
            /** @var int */
            'web_aliases' => $count('U_WEB_ALIASES'),
            /** @var int */
            'dns_domains' => $count('U_DNS_DOMAINS'),
            /** @var int */
            'dns_records' => $count('U_DNS_RECORDS'),
            /** @var int */
            'mail_domains' => $count('U_MAIL_DOMAINS'),
            /** @var int */
            'mail_accounts' => $count('U_MAIL_ACCOUNTS'),
            /** @var int */
            'databases' => $count('U_DATABASES'),
            /** @var int */
            'cron_jobs' => $count('U_CRON_JOBS'),
            /** @var int */
            'backups' => $count('U_BACKUPS'),
        ];
    }

    /** The API name of a counter of StatsMonth::COUNTERS (`U_WEB_DOMAINS` => `web_domains`). */
    public static function counterName(string $key): string
    {
        return strtolower(substr($key, 2));
    }

    private static function limit(StatsMonth $month, string $key): ?int
    {
        $limit = $month->limit($key);

        return $limit === null ? null : (int) $limit;
    }
}
