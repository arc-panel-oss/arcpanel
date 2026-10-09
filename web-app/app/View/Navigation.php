<?php

namespace App\View;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\AccessKeyPolicy;
use App\Policies\Capabilities;
use App\Policies\ServerPolicy;
use App\Services\Docker\DockerAccess;
use App\Services\Node\NodeAccess;
use Illuminate\Support\Facades\Route;

/**
 * The role-aware sidebar (docs/design/05 §4.1, 03 §9.1), generated from
 * Capabilities for the role the panel acts with. Items are route names.
 */
final class Navigation
{
    /**
     * requires: arcpanel.conf key that must be set ("KEY") or equal ("KEY=value").
     * node: only when the package of the account includes Node.js (E13),
     * always for administrators.
     * docker: only when the package of the account includes Docker apps (E4),
     * always for administrators.
     * labels: label per acting role, instead of label.
     * own: left out while a reseller acts as a customer, also as a panel app
     * page (the web terminal keeps the reseller's own shell).
     * policy: arcpanel.conf policy that hides the item for everyone but
     * administrators when it is 'no'.
     * api: only when the account may have access keys (API_SYSTEM).
     */
    private const GROUPS = [
        'main' => [
            'label' => null,
            'items' => [
                ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'cap' => 'dashboard.view'],
            ],
        ],
        'accounts' => [
            'label' => 'Accounts',
            'items' => [
                ['route' => 'users.index', 'label' => 'Users', 'labels' => ['reseller' => 'Customers'], 'icon' => 'users', 'cap' => 'users.view', 'section' => 'users.'],
                ['route' => 'packages.index', 'label' => 'Packages', 'icon' => 'package', 'cap' => 'packages.view', 'section' => 'packages.'],
            ],
        ],
        'hosting' => [
            'label' => 'Hosting',
            'items' => [
                ['route' => 'web.domains.index', 'label' => 'Web', 'icon' => 'globe', 'cap' => 'web.view', 'requires' => 'WEB_SYSTEM', 'section' => 'web.'],
                ['route' => 'dns.index', 'label' => 'DNS', 'icon' => 'dns', 'cap' => 'dns.view', 'requires' => 'DNS_SYSTEM', 'section' => 'dns.'],
                ['route' => 'mail.index', 'label' => 'Mail', 'icon' => 'mail', 'cap' => 'mail.view', 'requires' => 'MAIL_SYSTEM', 'section' => 'mail.'],
                ['route' => 'db.index', 'label' => 'Databases', 'icon' => 'db', 'cap' => 'db.view', 'requires' => 'DB_SYSTEM', 'section' => 'db.'],
                ['route' => 'cron.index', 'label' => 'Cron Jobs', 'icon' => 'clock', 'cap' => 'cron.view', 'requires' => 'CRON_SYSTEM', 'section' => 'cron.'],
                ['route' => 'backups.index', 'label' => 'Backups', 'icon' => 'archive', 'cap' => 'backups.view', 'requires' => 'BACKUP_SYSTEM', 'section' => 'backups.'],
                ['route' => 'files.index', 'label' => 'File manager', 'icon' => 'folder', 'cap' => 'files.view', 'requires' => 'FILE_MANAGER=true', 'writable' => true],
                ['route' => 'terminal.index', 'label' => 'Web terminal', 'icon' => 'terminal', 'cap' => 'terminal.use', 'requires' => 'WEB_TERMINAL=true', 'writable' => true, 'shell' => true,
                    'own' => true, 'section' => 'terminal.'],
                ['route' => 'ports.index', 'label' => 'Ports', 'icon' => 'plug', 'cap' => 'ports.mine', 'section' => 'ports.'],
                ['route' => 'node.index', 'label' => 'Node.js', 'icon' => 'hexagon', 'cap' => 'node.view', 'node' => true, 'section' => 'node.show'],
                ['route' => 'node-apps', 'label' => 'Node apps', 'icon' => 'layers', 'cap' => 'node.apps.view', 'node' => true, 'section' => 'node.apps.'],
                ['route' => 'docker-apps', 'label' => 'Docker apps', 'icon' => 'container', 'cap' => 'docker.apps.view', 'docker' => true,
                    'requires' => 'DOCKER_SYSTEM=yes', 'section' => 'docker.'],
            ],
        ],
        'server' => [
            'label' => 'Server',
            'items' => [
                ['route' => 'server.settings', 'label' => 'Configure', 'icon' => 'sliders', 'cap' => 'server.settings', 'section' => 'server.settings'],
                ['route' => 'server.services', 'label' => 'Services', 'icon' => 'activity', 'cap' => 'server.services', 'services' => true, 'section' => 'server.services'],
                ['route' => 'server.ips', 'label' => 'IP Addresses', 'icon' => 'hash', 'cap' => 'server.ips', 'section' => 'server.ips'],
                ['route' => 'server.firewall', 'label' => 'Firewall', 'icon' => 'shield', 'cap' => 'server.firewall', 'requires' => 'FIREWALL_SYSTEM', 'section' => 'server.firewall'],
                ['route' => 'server.ports', 'label' => 'Ports', 'icon' => 'plug', 'cap' => 'server.ports', 'section' => 'server.ports'],
                ['route' => 'server.docker', 'label' => 'Docker', 'icon' => 'container', 'cap' => 'docker.engine.manage', 'section' => 'server.docker'],
                ['route' => 'server.packages', 'label' => 'System packages', 'icon' => 'package', 'cap' => 'server.packages', 'section' => 'server.packages'],
                ['route' => 'server.updates', 'label' => 'Updates', 'icon' => 'refresh', 'cap' => 'server.updates', 'section' => 'server.updates'],
                ['route' => 'server.rrd', 'label' => 'Task Monitor', 'icon' => 'chart', 'cap' => 'server.rrd', 'section' => 'server.rrd'],
            ],
        ],
        'account' => [
            'label' => 'Account',
            'items' => [
                ['route' => 'logs.index', 'label' => 'Logs', 'icon' => 'file', 'cap' => 'logs.view', 'policy' => 'POLICY_USER_VIEW_LOGS', 'section' => 'logs.'],
                ['route' => 'stats.index', 'label' => 'Statistics', 'icon' => 'trend', 'cap' => 'stats.view', 'section' => 'stats.'],
                ['route' => 'access-keys.index', 'label' => 'Access keys', 'icon' => 'key', 'cap' => 'access-keys.view', 'api' => true, 'section' => 'access-keys.'],
                ['route' => 'ssh-keys.index', 'label' => 'SSH keys', 'icon' => 'lock', 'cap' => 'ssh-keys.view', 'section' => 'ssh-keys.'],
                ['route' => 'secrets.index', 'label' => 'Secrets', 'icon' => 'shield-check', 'cap' => 'secrets.view', 'section' => 'secrets.'],
                ['route' => 'account.edit', 'label' => 'My account', 'icon' => 'user-circle', 'cap' => 'account.view', 'section' => 'account.'],
            ],
        ],
    ];

    public function __construct(
        private readonly SysConfig $config,
        private readonly NodeAccess $node,
        private readonly DockerAccess $docker,
    ) {}

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
                    'label' => __($item['labels'][$principal->actingRole()->value] ?? $item['label']),
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

    /**
     * Shortcuts of the user dashboard: the create page of each hosting section
     * the sidebar shows, then the file manager. Nothing in read-only mode.
     */
    private const QUICK_ACTIONS = [
        ['section' => 'web.domains.index', 'route' => 'web.domains.create', 'cap' => 'web.create', 'label' => 'Add Web Domain', 'icon' => 'globe'],
        ['section' => 'mail.index', 'route' => 'mail.create', 'cap' => 'mail.create', 'label' => 'Add Mail Domain', 'icon' => 'mail'],
        ['section' => 'db.index', 'route' => 'db.create', 'cap' => 'db.create', 'label' => 'Add Database', 'icon' => 'db'],
        ['section' => 'dns.index', 'route' => 'dns.create', 'cap' => 'dns.create', 'label' => 'Add DNS Domain', 'icon' => 'dns'],
        ['section' => 'cron.index', 'route' => 'cron.create', 'cap' => 'cron.create', 'label' => 'Add Cron Job', 'icon' => 'clock'],
        ['section' => 'files.index', 'route' => 'files.index', 'cap' => 'files.view', 'label' => 'File manager', 'icon' => 'folder'],
    ];

    /**
     * @return list<array{key: string, label: string, icon: string, url: string}>
     */
    public function quickActions(Principal $principal): array
    {
        if ($principal->isReadOnly()) {
            return [];
        }
        $items = [];
        foreach (self::GROUPS['hosting']['items'] as $item) {
            $items[$item['route']] = $item;
        }
        $actions = [];
        foreach (self::QUICK_ACTIONS as $action) {
            $section = $items[$action['section']] ?? null;
            if ($section === null || ! $this->visible($principal, $section) || ! Capabilities::allows($principal->actingRole(), $action['cap'])) {
                continue;
            }
            $url = $this->url($action['route']);
            if ($url !== null) {
                $actions[] = ['key' => $action['route'], 'label' => __($action['label']), 'icon' => $action['icon'], 'url' => $url];
            }
        }

        return $actions;
    }

    /** URL of a route name; null when the route does not exist. */
    public static function url(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
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
        if (($item['node'] ?? false) && $principal->actingRole() !== Role::Admin && ! $this->node->allowed($principal->effectiveUser())) {
            return false;
        }
        if (($item['docker'] ?? false) && $principal->actingRole() !== Role::Admin && ! $this->docker->allowed($principal->effectiveUser())) {
            return false;
        }
        if (($item['writable'] ?? false) && $principal->isReadOnly()) {
            return false;
        }
        if (($item['shell'] ?? false) && $principal->loginShell === 'nologin') {
            return false;
        }
        if (($item['own'] ?? false) && $this->resellerActingAsCustomer($principal)) {
            return false;
        }
        if (isset($item['policy']) && $principal->loginRole !== Role::Admin && $this->config->is($item['policy'], 'no')) {
            return false;
        }
        if (($item['api'] ?? false) && ! AccessKeyPolicy::apiAvailable($this->config, $principal->effectiveUser())) {
            return false;
        }
        if (($item['services'] ?? false) && ! (new ServerPolicy($this->config))->viewServices($principal)) {
            return false;
        }

        return true;
    }

    private function resellerActingAsCustomer(Principal $principal): bool
    {
        return $principal->loginRole === Role::Reseller && $principal->isImpersonating();
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
