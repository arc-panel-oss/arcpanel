<?php

namespace App\View;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

/**
 * The role-aware sidebar (docs/design/05 §4.1, 03 §9.1), generated from
 * Capabilities for the role the panel acts with. Items are route names; a
 * route the panel app does not have yet links to the legacy page.
 */
final class Navigation
{
    /**
     * requires: hestia.conf key that must be set ("KEY") or equal ("KEY=value").
     */
    private const GROUPS = [
        'main' => [
            'label' => null,
            'items' => [
                ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'cap' => 'dashboard.view'],
            ],
        ],
        'hosting' => [
            'label' => 'Hosting',
            'items' => [
                ['route' => 'users.index', 'label' => 'Users', 'icon' => 'users', 'cap' => 'users.view'],
                ['route' => 'web.domains.index', 'label' => 'Web', 'icon' => 'globe', 'cap' => 'web.view', 'requires' => 'WEB_SYSTEM', 'section' => 'web.'],
                ['route' => 'dns.index', 'label' => 'DNS', 'icon' => 'dns', 'cap' => 'dns.view', 'requires' => 'DNS_SYSTEM'],
                ['route' => 'mail.index', 'label' => 'Mail', 'icon' => 'mail', 'cap' => 'mail.view', 'requires' => 'MAIL_SYSTEM'],
                ['route' => 'db.index', 'label' => 'Databases', 'icon' => 'db', 'cap' => 'db.view', 'requires' => 'DB_SYSTEM'],
                ['route' => 'cron.index', 'label' => 'Cron Jobs', 'icon' => 'clock', 'cap' => 'cron.view', 'requires' => 'CRON_SYSTEM'],
                ['route' => 'backups.index', 'label' => 'Backups', 'icon' => 'archive', 'cap' => 'backups.view', 'requires' => 'BACKUP_SYSTEM'],
                ['route' => 'files.index', 'label' => 'File manager', 'icon' => 'folder', 'cap' => 'files.view', 'requires' => 'FILE_MANAGER=true', 'writable' => true],
                ['route' => 'terminal.index', 'label' => 'Web terminal', 'icon' => 'terminal', 'cap' => 'files.view', 'requires' => 'WEB_TERMINAL=true', 'writable' => true, 'shell' => true],
            ],
        ],
        'server' => [
            'label' => 'Server',
            'items' => [
                ['route' => 'server.settings', 'label' => 'Configure', 'icon' => 'sliders', 'cap' => 'server.view'],
                ['route' => 'server.services', 'label' => 'Services', 'icon' => 'activity', 'cap' => 'server.view', 'services' => true],
                ['route' => 'server.ips', 'label' => 'IP Addresses', 'icon' => 'hash', 'cap' => 'server.view'],
                ['route' => 'server.firewall', 'label' => 'Firewall', 'icon' => 'shield', 'cap' => 'server.view', 'requires' => 'FIREWALL_SYSTEM'],
                ['route' => 'packages.index', 'label' => 'Packages', 'icon' => 'package', 'cap' => 'server.view'],
                ['route' => 'server.updates', 'label' => 'Updates', 'icon' => 'refresh', 'cap' => 'server.view'],
                ['route' => 'server.rrd', 'label' => 'Task Monitor', 'icon' => 'chart', 'cap' => 'server.view'],
            ],
        ],
        'account' => [
            'label' => 'Account',
            'items' => [
                ['route' => 'logs.index', 'label' => 'Logs', 'icon' => 'file', 'cap' => 'logs.view'],
                ['route' => 'stats.index', 'label' => 'Statistics', 'icon' => 'trend', 'cap' => 'stats.view'],
                ['route' => 'account.edit', 'label' => 'Edit User', 'icon' => 'user-circle', 'cap' => 'account.view'],
            ],
        ],
    ];

    public function __construct(private readonly SysConfig $config) {}

    /**
     * @return list<array{key: string, label: ?string, items: list<array{key: string, label: string, icon: string, url: string, active: bool}>}>
     */
    public function for(Principal $principal, ?string $currentRoute): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! $this->visible($principal, $item)) {
                    continue;
                }
                $url = $this->url($item['route']);
                if ($url === null) {
                    continue;
                }
                $items[] = [
                    'key' => $item['route'],
                    'label' => __($item['label']),
                    'icon' => $item['icon'],
                    'url' => $url,
                    'active' => $this->isActive($item, $currentRoute),
                ];
            }
            if ($items !== []) {
                $groups[] = ['key' => $key, 'label' => $group['label'] === null ? null : __($group['label']), 'items' => $items];
            }
        }

        return $groups;
    }

    /** URL of a route name: the panel app route, else the legacy page. */
    public static function url(string $route): ?string
    {
        return Route::has($route) ? route($route) : LegacyRoutes::url($route);
    }

    /** @param  array<string, mixed>  $item */
    private function visible(Principal $principal, array $item): bool
    {
        if (! Capabilities::allows($principal->actingRole(), $item['cap'])) {
            return false;
        }
        if (isset($item['requires']) && ! $this->configAllows($item['requires'])) {
            return false;
        }
        if (($item['writable'] ?? false) && $principal->isReadOnly()) {
            return false;
        }
        if (($item['shell'] ?? false) && $principal->loginShell === 'nologin') {
            return false;
        }
        if (($item['services'] ?? false) && $this->config->is('POLICY_SYSTEM_HIDE_SERVICES')
            && $principal->loginUser !== $this->config->get('ROOT_USER')) {
            return false;
        }

        return true;
    }

    private function configAllows(string $requirement): bool
    {
        if (str_contains($requirement, '=')) {
            [$key, $value] = explode('=', $requirement, 2);

            return $this->config->get($key) === $value;
        }

        return $this->config->get($requirement) !== '';
    }

    /** @param  array<string, mixed>  $item */
    private function isActive(array $item, ?string $currentRoute): bool
    {
        if ($currentRoute === null) {
            return false;
        }

        return $currentRoute === $item['route']
            || (isset($item['section']) && str_starts_with($currentRoute, $item['section']));
    }
}
