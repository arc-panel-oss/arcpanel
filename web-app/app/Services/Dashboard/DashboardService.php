<?php

namespace App\Services\Dashboard;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\Options;
use App\Services\Docker\DockerAccess;
use App\Services\Docker\DockerApps;
use App\Services\Node\NodeAccess;
use App\Services\Node\NodeApps;
use App\Services\UserDirectory;
use App\Services\Users\UserRecord;
use App\Services\Users\UserService;
use App\Services\WebDomains\WebDomainService;
use App\Tenancy\TenancyDirectory;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Route;

/**
 * Data of the dashboards (docs/design/05 §4.3–4.4, E1-S2 spec §7). Every
 * widget is loaded on its own: a failing command becomes an error in that
 * widget's card and the rest of the page still renders.
 */
final class DashboardService
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly Cache $cache,
        private readonly UserDirectory $users,
        private readonly WebDomainService $web,
        private readonly TenancyDirectory $tenancy,
        private readonly UserService $accounts,
        private readonly NodeAccess $node,
        private readonly DockerAccess $docker,
    ) {}

    /** @return array<string, Widget> */
    public function admin(string $loginUser): array
    {
        return [
            'health' => $this->widget(fn () => $this->cache->remember('arc.dash.health', 30, fn () => $this->health())),
            'services' => $this->widget(fn () => $this->cache->remember('arc.dash.services', 30, fn () => $this->services())),
            'graphs' => $this->widget(fn () => $this->graphs()),
            'accounts' => $this->widget(fn () => $this->accounts()),
            'authLog' => $this->widget(fn () => $this->authLog($loginUser)),
            'ssl' => $this->widget(fn () => $this->cache->remember('arc.dash.ssl', 300, fn () => $this->expiringCertificates())),
            'updates' => $this->widget(fn () => $this->cache->remember('arc.dash.updates', 3600, fn () => $this->updates())),
        ];
    }

    /** @return array<string, Widget> */
    public function user(string $user): array
    {
        return [
            'usage' => $this->widget(fn () => $this->usage($user)),
            'domains' => $this->widget(fn () => $this->domains($user)),
            'backups' => $this->widget(fn () => $this->lastBackup($user)),
            'notifications' => $this->widget(fn () => $this->notifications($user)),
            // Node.js apps (E14 spec §7): only for packages with Node.js
            'nodeApps' => $this->node->allowed($user)
                ? $this->widget(fn () => NodeApps::from($this->arc->json('v-list-node-apps', [$user])))
                : null,
            // Docker apps (E4 spec §7): only with the engine and a package with Docker apps
            'dockerApps' => $this->docker->available($user)
                ? $this->widget(fn () => DockerApps::from($this->arc->json('v-list-docker-apps', [$user])))
                : null,
        ];
    }

    /**
     * Reseller dashboard (E11 spec §9): its plan, its customers and their
     * certificates, then the widgets of its own hosting.
     *
     * @return array<string, Widget>
     */
    public function reseller(string $reseller): array
    {
        return [
            'plan' => $this->widget(fn () => $this->plan($reseller)),
            'customers' => $this->widget(fn () => $this->customers($reseller)),
            'ssl' => $this->widget(fn () => $this->cache->remember('arc.dash.ssl.'.$reseller, 300,
                fn () => $this->expiringCertificates(14, [$reseller, ...$this->tenancy->customersOf($reseller)]))),
            'usage' => $this->widget(fn () => $this->usage($reseller)),
        ];
    }

    /**
     * "Your provider" of a reseller's customer: the reseller's name and
     * contact e-mail only; null for users of the administrator.
     *
     * @return array{name: string, email: string}|null
     */
    public function provider(string $user): ?array
    {
        try {
            $owner = $this->tenancy->ownerOf($user);
        } catch (ArcCommandException) {
            return null;
        }
        if ($owner === '' || ! $this->tenancy->isReseller($owner)) {
            return null;
        }
        $record = $this->arc->json('v-list-user', [$owner], Options::allowFailure())[$owner] ?? [];

        return ['name' => (string) ($record['NAME'] ?? $owner), 'email' => (string) ($record['CONTACT'] ?? '')];
    }

    /**
     * Allocation of the plan (spec D7) and usage of the tree (R_* = own
     * U_* plus the customers').
     *
     * @return array{package: string, rows: array<string, array{allocated: int|null, limit: string, used: int}>}
     */
    public function plan(string $reseller): array
    {
        $record = $this->accounts->find($reseller);
        $tree = [$record, ...array_values($this->accounts->only($this->tenancy->customersOf($reseller)))];
        $rows = [];
        foreach ($this->accounts->allocation($record) as $key => $row) {
            $used = $key === 'USERS'
                ? count($tree) - 1
                : array_sum(array_map(fn (UserRecord $u) => (int) $u->get(UserRecord::USAGE[$key], '0'), $tree));
            $rows[$key] = $row + ['used' => $used];
        }

        return ['package' => $record->package(), 'rows' => $rows];
    }

    /** @return array{total: int, suspended: int, recent: list<array{name: string, fullName: string, package: string, date: string, suspended: bool}>} */
    public function customers(string $reseller): array
    {
        $customers = array_values($this->accounts->only($this->tenancy->customersOf($reseller)));
        usort($customers, fn (UserRecord $a, UserRecord $b) => ($b->get('DATE').$b->get('TIME')) <=> ($a->get('DATE').$a->get('TIME')));

        return [
            'total' => count($customers),
            'suspended' => count(array_filter($customers, fn (UserRecord $u) => $u->suspended())),
            'recent' => array_map(fn (UserRecord $u) => [
                'name' => $u->name,
                'fullName' => $u->fullName(),
                'package' => $u->package(),
                'date' => $u->get('DATE'),
                'suspended' => $u->suspended(),
            ], array_slice($customers, 0, 5)),
        ];
    }

    private function widget(Closure $load): Widget
    {
        try {
            return Widget::loaded($load());
        } catch (ArcCommandException $e) {
            return Widget::failed($e->userMessage());
        }
    }

    /**
     * CPU, memory, disk and load from v-list-sys-info and the plain output
     * of v-list-sys-{cpu,memory,disk}-status (top, free, df).
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $info = $this->arc->json('v-list-sys-info')['sysinfo'] ?? [];
        // These print their report even when a sub-tool (vmstat, iostat) is
        // missing and the exit code is not 0; the report is still usable.
        $memory = SystemStatus::memory($this->arc->run('v-list-sys-memory-status', [], Options::allowFailure())->stdout);
        $disk = SystemStatus::disk($this->arc->run('v-list-sys-disk-status', [], Options::allowFailure())->stdout);
        $cpu = SystemStatus::cpu($this->arc->run('v-list-sys-cpu-status', [], Options::allowFailure())->stdout);

        return [
            'hostname' => (string) ($info['HOSTNAME'] ?? ''),
            'os' => trim(($info['OS'] ?? '').' '.($info['VERSION'] ?? '')),
            'version' => (string) ($info['ARCPANEL'] ?? ($info['HESTIA'] ?? '')),
            'uptimeMinutes' => (int) ($info['UPTIME'] ?? 0),
            'load' => array_map('trim', explode('/', (string) ($info['LOADAVERAGE'] ?? ''))),
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
        ];
    }

    /** @return list<array{name: string, system: string, running: bool}> */
    public function services(): array
    {
        $services = [];
        foreach ($this->arc->json('v-list-sys-services') as $name => $row) {
            if (is_array($row)) {
                $services[] = [
                    'name' => (string) $name,
                    'system' => (string) ($row['SYSTEM'] ?? ''),
                    'running' => ($row['STATE'] ?? '') === 'running',
                ];
            }
        }

        return $services;
    }

    /** @return list<array{service: string, title: string}> graphs of v-list-sys-rrd, load and memory first */
    public function graphs(): array
    {
        $graphs = [];
        foreach ($this->arc->json('v-list-sys-rrd', [], Options::allowFailure()) as $row) {
            if (is_array($row) && isset($row['RRD'])) {
                $graphs[] = ['service' => (string) $row['RRD'], 'title' => (string) ($row['TITLE'] ?? $row['RRD'])];
            }
        }

        return array_values(array_filter($graphs, fn ($g) => in_array($g['service'], ['la', 'mem'], true)));
    }

    /** @return array{total: int, suspended: int, admins: int, resellers: int, webDomains: int} */
    public function accounts(): array
    {
        $users = $this->users->all();

        return [
            'total' => count($users),
            'suspended' => count(array_filter($users, fn ($u) => ($u['SUSPENDED'] ?? '') === 'yes')),
            'admins' => count(array_filter($users, fn ($u) => ($u['ROLE'] ?? '') === 'admin')),
            'resellers' => count(array_filter($users, fn ($u) => ($u['ROLE'] ?? '') === 'reseller')),
            'webDomains' => array_sum(array_map(fn ($u) => (int) ($u['U_WEB_DOMAINS'] ?? 0), $users)),
        ];
    }

    /** @return list<array<string, string>> last 10 entries, newest first */
    public function authLog(string $user): array
    {
        $rows = array_values(array_filter($this->arc->json('v-list-user-auth-log', [$user]), 'is_array'));
        $rows = array_slice(array_reverse($rows), 0, 10);

        return array_map(fn (array $row) => [
            'date' => (string) ($row['DATE'] ?? ''),
            'time' => (string) ($row['TIME'] ?? ''),
            'ip' => (string) ($row['IP'] ?? ''),
            'action' => (string) ($row['ACTION'] ?? ''),
            'success' => ($row['STATUS'] ?? '') === 'success',
        ], $rows);
    }

    /**
     * Certificates of every user (or of $users) expiring within 14 days: SSL
     * domains only.
     *
     * @param  list<string>|null  $users
     * @return list<array{user: string, domain: string, expires: string, days: int}>
     */
    public function expiringCertificates(int $withinDays = 14, ?array $users = null): array
    {
        $expiring = [];
        foreach ($users ?? $this->users->names() as $user) {
            foreach ($this->web->forUser($user) as $record) {
                if (! $record->ssl) {
                    continue;
                }
                $certificate = $this->web->ssl($user, $record->domain);
                $days = $certificate?->daysLeft();
                if ($days !== null && $days <= $withinDays) {
                    $expiring[] = [
                        'user' => $user,
                        'domain' => $record->domain,
                        'expires' => (string) $certificate->expiresAt()?->format('Y-m-d'),
                        'days' => $days,
                    ];
                }
            }
        }
        usort($expiring, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $expiring;
    }

    /** @return list<string> packages with an update available */
    public function updates(): array
    {
        $pending = [];
        foreach ($this->arc->json('v-list-sys-hestia-updates') as $name => $row) {
            if (is_array($row) && ($row['UPDATED'] ?? 'yes') !== 'yes') {
                $pending[] = (string) $name;
            }
        }

        return $pending;
    }

    /**
     * U_* usage against the package limits of `v-list-user USER json`.
     *
     * @return array{package: string, items: list<array{label: string, value: float, limit: string, valueLabel: string, limitLabel: string}>}
     */
    public function usage(string $user): array
    {
        $record = $this->arc->json('v-list-user', [$user])[$user] ?? [];
        $mb = fn (string $key) => (float) ($record[$key] ?? 0);
        $size = fn (float $value) => self::size($value);
        $limitSize = fn (string $key) => ($record[$key] ?? 'unlimited') === 'unlimited' ? 'unlimited' : (string) (float) $record[$key];

        $items = [
            ['label' => __('Disk'), 'value' => $mb('U_DISK'), 'limit' => $limitSize('DISK_QUOTA'), 'valueLabel' => $size($mb('U_DISK')), 'limitLabel' => $limitSize('DISK_QUOTA') === 'unlimited' ? '' : $size((float) $record['DISK_QUOTA'])],
            ['label' => __('Bandwidth'), 'value' => $mb('U_BANDWIDTH'), 'limit' => $limitSize('BANDWIDTH'), 'valueLabel' => $size($mb('U_BANDWIDTH')), 'limitLabel' => $limitSize('BANDWIDTH') === 'unlimited' ? '' : $size((float) $record['BANDWIDTH'])],
        ];
        // Each count links to its list page once the module runs in the panel app (E7).
        $pages = ['WEB_DOMAINS' => 'web.domains.index', 'DNS_DOMAINS' => 'dns.index', 'MAIL_DOMAINS' => 'mail.index', 'MAIL_ACCOUNTS' => 'mail.index', 'DATABASES' => 'db.index', 'CRON_JOBS' => 'cron.index'];
        foreach (['WEB_DOMAINS' => 'Web Domains', 'DNS_DOMAINS' => 'DNS Domains', 'MAIL_DOMAINS' => 'Mail Domains', 'MAIL_ACCOUNTS' => 'Mail Accounts', 'DATABASES' => 'Databases', 'CRON_JOBS' => 'Cron Jobs'] as $key => $label) {
            $limit = (string) ($record[$key] ?? 'unlimited');
            $used = (string) (int) ($record['U_'.$key] ?? 0);
            $url = Route::has($pages[$key]) ? route($pages[$key]) : null;
            $items[] = ['label' => __($label), 'value' => (float) $used, 'limit' => $limit, 'valueLabel' => $used, 'limitLabel' => $limit, 'url' => $url];
        }

        return ['package' => (string) ($record['PACKAGE'] ?? ''), 'items' => $items];
    }

    /** @return list<array{domain: string, ssl: bool, letsEncrypt: bool, suspended: bool, diskMb: int}> */
    public function domains(string $user): array
    {
        return array_values(array_map(fn ($record) => [
            'domain' => $record->domain,
            'ssl' => $record->ssl,
            'letsEncrypt' => $record->letsEncrypt,
            'suspended' => $record->suspended,
            'diskMb' => $record->diskMb,
        ], $this->web->forUser($user)));
    }

    /** @return array{name: string, date: string, time: string, size: string}|null */
    public function lastBackup(string $user): ?array
    {
        $latest = null;
        foreach ($this->arc->json('v-list-user-backups', [$user]) as $name => $row) {
            if (! is_array($row)) {
                continue;
            }
            $stamp = ($row['DATE'] ?? '').' '.($row['TIME'] ?? '');
            if ($latest === null || strcmp($stamp, $latest['stamp']) > 0) {
                $latest = ['name' => (string) $name, 'date' => (string) ($row['DATE'] ?? ''), 'time' => (string) ($row['TIME'] ?? ''), 'size' => self::size((float) ($row['SIZE'] ?? 0)), 'stamp' => $stamp];
            }
        }
        if ($latest !== null) {
            unset($latest['stamp']);
        }

        return $latest;
    }

    /** @return list<array{title: string, body: string, date: string, acknowledged: bool}> newest first */
    public function notifications(string $user): array
    {
        $items = [];
        foreach ($this->arc->json('v-list-user-notifications', [$user]) as $row) {
            if (is_array($row)) {
                $items[] = [
                    'title' => trim(html_entity_decode(strip_tags((string) ($row['TOPIC'] ?? '')), ENT_QUOTES | ENT_HTML5)),
                    'body' => trim(html_entity_decode(strip_tags((string) ($row['NOTICE'] ?? '')), ENT_QUOTES | ENT_HTML5)),
                    'date' => (string) ($row['DATE'] ?? ''),
                    'acknowledged' => ($row['ACK'] ?? '') === 'yes',
                ];
            }
        }

        return array_slice(array_reverse($items), 0, 5);
    }

    /** Size in megabytes, as the legacy humanize_usage_size() shows it. */
    public static function size(float $mb): string
    {
        return match (true) {
            $mb >= 1048576 => round($mb / 1048576, 2).' TB',
            $mb >= 1024 => round($mb / 1024, 2).' GB',
            default => round($mb, 1).' MB',
        };
    }
}
