<?php

namespace App\View;

use App\Services\Dashboard\DashboardService;
use App\Services\Stats\StatsMonth;

/**
 * What the Statistics page shows (E7 batch 2 spec §9.4): the newest month in
 * detail, each figure compared with the month before, and every month as
 * one row of the history table. Labels are gettext keys; the view
 * translates them.
 */
final class StatsOverview
{
    /** Parts of U_DISK, in the order of the breakdown bar: key => label. */
    public const DISK_PARTS = [
        'U_DISK_WEB' => 'Web',
        'U_DISK_DB' => 'Databases',
        'U_DISK_MAIL' => 'Mail',
        'U_DISK_DIRS' => 'User Directory',
    ];

    /** Icons of the counters of StatsMonth::COUNTERS that get a tile. */
    private const TILES = [
        'U_WEB_DOMAINS' => 'globe',
        'U_DNS_DOMAINS' => 'dns',
        'U_MAIL_DOMAINS' => 'mail',
        'U_DATABASES' => 'db',
        'U_CRON_JOBS' => 'clock',
        'U_BACKUPS' => 'archive',
    ];

    /**
     * @param  list<StatsMonth>  $months  newest first
     */
    public function __construct(
        public readonly array $months,
        private readonly string $scope,
        private readonly bool $isAdmin,
    ) {}

    public function latest(): ?StatsMonth
    {
        return $this->months[0] ?? null;
    }

    public function previous(): ?StatsMonth
    {
        return $this->months[1] ?? null;
    }

    public static function title(StatsMonth $month): string
    {
        return $month->month() ? __($month->month()->format('M')).' '.$month->month()->format('Y') : $month->date;
    }

    /**
     * Bandwidth and disk of the newest month against their limits.
     *
     * @return list<array{key: string, label: string, icon: string, value: float, valueLabel: string, limit: float|null, limitLabel: string|null, percent: int|null, delta: string|null}>
     */
    public function quotas(): array
    {
        $month = $this->latest();
        if ($month === null) {
            return [];
        }
        $quotas = [];
        foreach (['U_BANDWIDTH' => ['BANDWIDTH', 'Bandwidth', 'activity'], 'U_DISK' => ['DISK_QUOTA', 'Disk', 'disk']] as $key => [$limitKey, $label, $icon]) {
            $value = (float) $month->value($key);
            $limit = $month->limit($limitKey);
            $quotas[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'value' => $value,
                'valueLabel' => DashboardService::size($value),
                'limit' => $limit,
                'limitLabel' => $limit !== null ? DashboardService::size($limit) : null,
                'percent' => $limit !== null ? (int) min(100, round($value / $limit * 100)) : null,
                'delta' => $this->sizeDelta($key),
            ];
        }

        return $quotas;
    }

    /**
     * KEY over the months, oldest first, scaled to the largest month: the
     * small column chart of a quota card. Empty with a single month.
     *
     * @return list<array{title: string, valueLabel: string, percent: float, latest: bool}>
     */
    public function trend(string $key): array
    {
        if (count($this->months) < 2) {
            return [];
        }
        $months = array_reverse(array_slice($this->months, 0, 12));
        $max = max(array_map(fn (StatsMonth $m) => (float) $m->value($key), $months));
        $last = count($months) - 1;

        return array_map(fn (StatsMonth $month, int $index) => [
            'title' => self::title($month),
            'valueLabel' => DashboardService::size((float) $month->value($key)),
            'percent' => $max > 0 ? round((float) $month->value($key) / $max * 100, 1) : 0.0,
            'latest' => $index === $last,
        ], $months, array_keys($months));
    }

    /**
     * Where the disk usage of the newest month goes; empty when nothing is used.
     *
     * @return list<array{key: string, label: string, valueLabel: string, percent: float}>
     */
    public function diskParts(): array
    {
        $month = $this->latest();
        if ($month === null) {
            return [];
        }
        $total = array_sum(array_map(fn (string $key) => (float) $month->value($key), array_keys(self::DISK_PARTS)));
        $parts = [];
        foreach (self::DISK_PARTS as $key => $label) {
            $value = (float) $month->value($key);
            $parts[] = [
                'key' => $key,
                'label' => $label,
                'valueLabel' => DashboardService::size($value),
                'percent' => $total > 0 ? round($value / $total * 100, 1) : 0.0,
            ];
        }

        return $total > 0 ? $parts : [];
    }

    /**
     * Counters of the newest month as tiles, with the related counters as a
     * meta line and the change since the month before.
     *
     * @return list<array{key: string, label: string, icon: string, value: string, meta: string|null, delta: int|null}>
     */
    public function tiles(): array
    {
        $month = $this->latest();
        if ($month === null) {
            return [];
        }
        $meta = [
            'U_WEB_DOMAINS' => trans_choice(':count SSL|:count SSL', (int) $month->value('U_WEB_SSL'), ['count' => $month->value('U_WEB_SSL')])
                .' · '.trans_choice(':count alias|:count aliases', (int) $month->value('U_WEB_ALIASES'), ['count' => $month->value('U_WEB_ALIASES')]),
            'U_DNS_DOMAINS' => trans_choice(':count record|:count records', (int) $month->value('U_DNS_RECORDS'), ['count' => $month->value('U_DNS_RECORDS')]),
            'U_MAIL_DOMAINS' => trans_choice(':count account|:count accounts', (int) $month->value('U_MAIL_ACCOUNTS'), ['count' => $month->value('U_MAIL_ACCOUNTS')]),
        ];
        $tiles = [];
        foreach (self::TILES as $key => $icon) {
            $tiles[] = $this->tile($key, StatsMonth::COUNTERS[$key], $icon, $meta[$key] ?? null);
        }
        if ($this->scope !== 'user' && isset($month->values['U_USERS'])) {
            $tiles[] = $this->tile('U_USERS', 'Users', 'users', null);
        }
        if ($this->showsIps($month)) {
            $tiles[] = $this->tile('IP_OWNED', 'IP Addresses', 'hash', null);
        }

        return $tiles;
    }

    /**
     * Columns of the history table: key => [label, 'size'|'count'].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function columns(): array
    {
        $columns = ['U_BANDWIDTH' => ['Bandwidth', 'size'], 'U_DISK' => ['Disk', 'size']];
        foreach (self::DISK_PARTS as $key => $label) {
            $columns[$key] = [$label, 'size'];
        }
        if ($this->scope !== 'user' && array_filter($this->months, fn (StatsMonth $m) => isset($m->values['U_USERS'])) !== []) {
            $columns['U_USERS'] = ['Users', 'count'];
        }
        if (array_filter($this->months, fn (StatsMonth $m) => $this->showsIps($m)) !== []) {
            $columns['IP_OWNED'] = ['IP Addresses', 'count'];
        }
        foreach (StatsMonth::COUNTERS as $key => $label) {
            $columns[$key] = [$label, 'count'];
        }

        return $columns;
    }

    public function cell(StatsMonth $month, string $key, string $kind): string
    {
        return $kind === 'size' ? DashboardService::size((float) $month->value($key)) : $month->value($key);
    }

    private function showsIps(StatsMonth $month): bool
    {
        return $this->isAdmin || $month->value('IP_OWNED') !== '0';
    }

    /** @return array{key: string, label: string, icon: string, value: string, meta: string|null, delta: int|null} */
    private function tile(string $key, string $label, string $icon, ?string $meta): array
    {
        $month = $this->latest();
        $previous = $this->previous();

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'value' => $month->value($key),
            'meta' => $meta,
            'delta' => $previous !== null ? (int) $month->value($key) - (int) $previous->value($key) : null,
        ];
    }

    /** "+12 MB", "-1.5 GB" or "±0 MB" against the month before; null without one. */
    private function sizeDelta(string $key): ?string
    {
        $previous = $this->previous();
        if ($previous === null) {
            return null;
        }
        $delta = (float) $this->latest()->value($key) - (float) $previous->value($key);

        return ($delta > 0 ? '+' : ($delta < 0 ? '−' : '±')).DashboardService::size(abs($delta));
    }
}
