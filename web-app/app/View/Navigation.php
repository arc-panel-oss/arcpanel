<?php

namespace App\View;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\AccessKeyPolicy;
use App\Policies\Capabilities;
use App\Policies\ServerPolicy;
use App\Services\AppProcesses\AppProcessAccess;
use App\Services\Docker\DockerAccess;
use App\Services\Node\NodeAccess;
use App\Services\ObjectStorage\ObjectStorageAccess;
use Illuminate\Support\Facades\Route;

/**
 * The role-aware sidebar (docs/design/05 §4.1, 03 §9.1), generated from
 * Capabilities for the role the panel acts with. Items are route names.
 * A hub folds related pages into one item whose pages are tabs
 * (docs/features/specs/2026-10-13-001-e2-sidebar-hubs-design.md).
 */
final class Navigation
{
    /**
     * requires: arcpanel.conf key that must be set ("KEY") or equal ("KEY=value").
     * node: only when the package of the account includes Node.js (E13),
     * always for administrators.
     * docker: only when the package of the account includes Docker apps (E4),
     * always for administrators.
     * storage: only when the package of the account includes object storage
     * (E44), always for administrators.
     * labels: label per acting role, instead of label.
     * own: left out while a reseller acts as a customer, also as a panel app
     * page (the web terminal keeps the reseller's own shell).
     * policy: arcpanel.conf policy that hides the item for everyone but
     * administrators when it is 'no'.
     * api: only when the account may have access keys (API_SYSTEM).
     *
     * A hub ('hub' => key) has a label, an icon and 'children': items as
     * above, each with 'tab' (its tab label; 'label' names it alone, in the
     * sidebar and the command palette), 'tabs' (the route names of its
     * landing pages, which show the tab bar) and optionally 'userRoute' (its
     * landing page of one account, linked with the {user} of the current page)
     * and 'alone' (its label when it is the only visible page of the hub and
     * stands in for it).
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
                ['route' => 'malware.index', 'label' => 'Malware scan', 'icon' => 'shield', 'cap' => 'malware.view', 'requires' => 'MALWARE_SCAN=yes', 'section' => 'malware.'],
                ['hub' => 'files', 'label' => 'Files', 'icon' => 'folder', 'children' => [
                    ['route' => 'files.index', 'label' => 'File manager', 'tab' => 'File manager', 'icon' => 'folder', 'cap' => 'files.view', 'requires' => 'FILE_MANAGER=true',
                        'writable' => true, 'section' => 'files.', 'tabs' => ['files.show'], 'userRoute' => 'files.show'],
                    ['route' => 'terminal.index', 'label' => 'Web terminal', 'tab' => 'Terminal', 'icon' => 'terminal', 'cap' => 'terminal.use', 'requires' => 'WEB_TERMINAL=true',
                        'writable' => true, 'shell' => true, 'own' => true, 'section' => 'terminal.', 'tabs' => ['terminal.index']],
                ]],
                ['route' => 'ports.index', 'label' => 'Ports', 'icon' => 'plug', 'cap' => 'ports.mine', 'section' => 'ports.'],
                ['hub' => 'node', 'label' => 'Node apps', 'icon' => 'layers', 'children' => [
                    ['route' => 'node-apps', 'label' => 'Node apps', 'tab' => 'Apps', 'icon' => 'layers', 'cap' => 'node.apps.view', 'node' => true,
                        'section' => 'node.apps.', 'tabs' => ['node.apps.index'], 'userRoute' => 'node.apps.index'],
                    ['route' => 'node.index', 'label' => 'Node.js', 'tab' => 'Runtimes', 'icon' => 'hexagon', 'cap' => 'node.view', 'node' => true,
                        'section' => 'node.show', 'tabs' => ['node.show'], 'userRoute' => 'node.show'],
                ]],
                // App processes (E42 spec §9): only with the package grant
                ['route' => 'app-processes', 'label' => 'App processes', 'icon' => 'terminal', 'cap' => 'apps.processes.view', 'processes' => true,
                    'section' => 'app-processes.'],
                ['hub' => 'docker', 'label' => 'Docker apps', 'icon' => 'container', 'children' => [
                    ['route' => 'docker-apps', 'label' => 'Docker apps', 'tab' => 'Apps', 'icon' => 'container', 'cap' => 'docker.apps.view', 'docker' => true,
                        'requires' => 'DOCKER_SYSTEM=yes', 'section' => 'docker.', 'tabs' => ['docker.apps.index'], 'userRoute' => 'docker.apps.index'],
                    ['route' => 'server.docker', 'label' => 'Docker engine', 'tab' => 'Engine', 'icon' => 'container', 'cap' => 'docker.engine.manage',
                        'section' => 'server.docker', 'tabs' => ['server.docker']],
                ]],
                // Object storage (E44 spec §11): the account's buckets, the service of the server
                ['hub' => 'storage', 'label' => 'Object storage', 'icon' => 'disk', 'children' => [
                    ['route' => 'storage', 'label' => 'Object storage', 'tab' => 'Buckets', 'icon' => 'disk', 'cap' => 'storage.view', 'storage' => true,
                        'requires' => 'OBJECT_STORAGE_SYSTEM=silo', 'section' => 'storage.', 'tabs' => ['storage.show'], 'userRoute' => 'storage.show'],
                    ['route' => 'server.object-storage', 'label' => 'Object storage service', 'alone' => 'Object storage', 'tab' => 'Service', 'icon' => 'server', 'cap' => 'storage.server',
                        'section' => 'server.object-storage', 'tabs' => ['server.object-storage']],
                ]],
            ],
        ],
        'server' => [
            'label' => 'Server',
            'items' => [
                ['route' => 'server.settings', 'label' => 'Configure', 'icon' => 'sliders', 'cap' => 'server.settings', 'section' => 'server.settings'],
                ['hub' => 'server-backups', 'label' => 'Backups', 'icon' => 'archive', 'children' => [
                    ['route' => 'server.backups', 'label' => 'Backup overview', 'tab' => 'Overview', 'icon' => 'archive', 'cap' => 'backups.activity.view',
                        'tabs' => ['server.backups']],
                    ['route' => 'server.backups.destinations', 'label' => 'Backup destinations', 'tab' => 'Destinations', 'icon' => 'disk', 'cap' => 'backups.destinations.manage',
                        'section' => 'server.backups.destinations', 'tabs' => ['server.backups.destinations']],
                    ['route' => 'server.backups.jobs', 'label' => 'Backup schedules', 'tab' => 'Schedules', 'icon' => 'clock', 'cap' => 'backups.jobs.manage',
                        'section' => 'server.backups.jobs', 'tabs' => ['server.backups.jobs']],
                    ['route' => 'server.backups.activity', 'label' => 'Backup activity', 'tab' => 'Activity', 'icon' => 'activity', 'cap' => 'backups.activity.view',
                        'section' => 'server.backups.activity', 'tabs' => ['server.backups.activity']],
                    ['route' => 'server.backups.settings', 'label' => 'Backup settings', 'tab' => 'Settings', 'icon' => 'sliders', 'cap' => 'server.settings',
                        'section' => 'server.backups.settings', 'tabs' => ['server.backups.settings']],
                ]],
                ['hub' => 'services', 'label' => 'Services', 'icon' => 'activity', 'children' => [
                    ['route' => 'server.services', 'label' => 'Services', 'tab' => 'Services', 'icon' => 'activity', 'cap' => 'server.services', 'services' => true,
                        'section' => 'server.services', 'tabs' => ['server.services']],
                    ['route' => 'server.rrd', 'label' => 'Task Monitor', 'tab' => 'Task monitor', 'icon' => 'chart', 'cap' => 'server.rrd', 'section' => 'server.rrd', 'tabs' => ['server.rrd']],
                ]],
                ['hub' => 'network', 'label' => 'Network', 'icon' => 'hash', 'children' => [
                    ['route' => 'server.ips', 'label' => 'IP Addresses', 'tab' => 'IP addresses', 'icon' => 'hash', 'cap' => 'server.ips', 'section' => 'server.ips', 'tabs' => ['server.ips']],
                    ['route' => 'server.firewall', 'label' => 'Firewall', 'tab' => 'Firewall', 'icon' => 'shield', 'cap' => 'server.firewall', 'requires' => 'FIREWALL_SYSTEM',
                        'section' => 'server.firewall', 'tabs' => ['server.firewall', 'server.firewall.bans', 'server.firewall.ipsets']],
                    ['route' => 'server.ports', 'label' => 'Ports', 'tab' => 'Ports', 'icon' => 'plug', 'cap' => 'server.ports', 'section' => 'server.ports', 'tabs' => ['server.ports']],
                ]],
                ['route' => 'server.php.index', 'label' => 'PHP', 'icon' => 'code', 'cap' => 'server.php', 'section' => 'server.php.'],
                ['hub' => 'updates', 'label' => 'Updates', 'icon' => 'refresh', 'children' => [
                    ['route' => 'server.updates', 'label' => 'Updates', 'tab' => 'ArcPanel', 'icon' => 'refresh', 'cap' => 'server.updates', 'section' => 'server.updates',
                        'tabs' => ['server.updates']],
                    ['route' => 'server.packages', 'label' => 'System packages', 'tab' => 'System packages', 'icon' => 'package', 'cap' => 'server.packages',
                        'section' => 'server.packages', 'tabs' => ['server.packages']],
                ]],
            ],
        ],
        'account' => [
            'label' => 'Account',
            'items' => [
                ['route' => 'logs.index', 'label' => 'Logs', 'icon' => 'file', 'cap' => 'logs.view', 'policy' => 'POLICY_USER_VIEW_LOGS', 'section' => 'logs.'],
                ['route' => 'stats.index', 'label' => 'Statistics', 'icon' => 'trend', 'cap' => 'stats.view', 'section' => 'stats.'],
                ['hub' => 'keys', 'label' => 'Keys', 'icon' => 'key', 'children' => [
                    ['route' => 'access-keys.index', 'label' => 'Access keys', 'tab' => 'Access keys', 'icon' => 'key', 'cap' => 'access-keys.view', 'api' => true,
                        'section' => 'access-keys.', 'tabs' => ['access-keys.list'], 'userRoute' => 'access-keys.list'],
                    ['route' => 'ssh-keys.index', 'label' => 'SSH keys', 'tab' => 'SSH keys', 'icon' => 'lock', 'cap' => 'ssh-keys.view',
                        'section' => 'ssh-keys.', 'tabs' => ['ssh-keys.list'], 'userRoute' => 'ssh-keys.list'],
                ]],
                ['route' => 'secrets.index', 'label' => 'Secrets', 'icon' => 'shield-check', 'cap' => 'secrets.view', 'section' => 'secrets.'],
                // My account is in the user menu of the top bar
            ],
        ],
    ];

    public function __construct(
        private readonly SysConfig $config,
        private readonly NodeAccess $node,
        private readonly DockerAccess $docker,
        private readonly AppProcessAccess $processes,
        private readonly ObjectStorageAccess $storage,
    ) {}

    /**
     * Groups of sidebar items. A hub with two or more visible pages has the
     * key "hub.<name>", links to its first page and lists its pages in
     * 'children'; a hub with one visible page is that page.
     *
     * @return list<array{key: string, label: ?string, items: list<array{key: string, label: string, icon: string, url: string, active: bool, children?: list<array<string, mixed>>}>}>
     */
    public function for(Principal $principal, ?string $currentRoute): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! isset($item['hub'])) {
                    if (($entry = $this->entry($principal, $item, $currentRoute)) !== null) {
                        $items[] = $entry;
                    }

                    continue;
                }
                $children = $this->visibleChildren($principal, $item, $currentRoute);
                if (count($children) === 1) {
                    $page = array_column($item['children'], null, 'route')[$children[0]['key']];
                    $items[] = isset($page['alone']) ? ['label' => __($page['alone'])] + $children[0] : $children[0];
                } elseif ($children !== []) {
                    $items[] = [
                        'key' => 'hub.'.$item['hub'],
                        'label' => __($item['label']),
                        'icon' => $item['icon'],
                        'url' => $children[0]['url'],
                        'active' => in_array(true, array_column($children, 'active'), true),
                        'children' => $children,
                    ];
                }
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
            foreach ($item['children'] ?? [$item] as $page) {
                $items[$page['route']] = $page;
            }
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

    /**
     * The tab bar of the current page: the pages of its hub when the hub has
     * two or more visible pages and the current route is a landing page of
     * one of them; null otherwise.
     *
     * @param  array<string, mixed>  $parameters  route parameters of the current page
     * @return array{label: string, items: list<array{key: string, label: string, url: string, current: bool}>}|null
     */
    public function tabs(Principal $principal, ?string $currentRoute, array $parameters): ?array
    {
        if ($currentRoute === null) {
            return null;
        }
        $user = isset($parameters['user']) && is_string($parameters['user']) ? $parameters['user'] : null;
        foreach (self::GROUPS as $group) {
            foreach ($group['items'] as $item) {
                if (! isset($item['hub'])) {
                    continue;
                }
                $pages = array_values(array_filter($item['children'], fn (array $page) => $this->visible($principal, $page) && $this->url($page['route']) !== null));
                $landing = array_filter($pages, fn (array $page) => $currentRoute === $page['route'] || in_array($currentRoute, $page['tabs'], true));
                if (count($pages) < 2 || $landing === []) {
                    continue;
                }

                return [
                    'label' => __($item['label']),
                    'items' => array_map(fn (array $page) => [
                        'key' => $page['route'],
                        'label' => __($page['tab']),
                        'url' => $user !== null && isset($page['userRoute']) ? route($page['userRoute'], ['user' => $user]) : (string) $this->url($page['route']),
                        'current' => in_array($page, $landing, true),
                    ], $pages),
                ];
            }
        }

        return null;
    }

    /**
     * Sidebar entry of one visible item, null when it is hidden.
     *
     * @param  array<string, mixed>  $item
     * @return array{key: string, label: string, icon: string, url: string, active: bool, tab?: string}|null
     */
    private function entry(Principal $principal, array $item, ?string $currentRoute): ?array
    {
        if (! $this->visible($principal, $item)) {
            return null;
        }
        $url = $this->url($item['route']);
        if ($url === null) {
            return null;
        }
        $entry = [
            'key' => $item['route'],
            'label' => __($item['labels'][$principal->actingRole()->value] ?? $item['label']),
            'icon' => $item['icon'],
            'url' => $url,
            'active' => $this->isActive($item, $currentRoute),
        ];
        if (isset($item['tab'])) {
            $entry['tab'] = __($item['tab']);
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $hub
     * @return list<array{key: string, label: string, icon: string, url: string, active: bool, tab?: string}>
     */
    private function visibleChildren(Principal $principal, array $hub, ?string $currentRoute): array
    {
        $children = [];
        foreach ($hub['children'] as $page) {
            if (($entry = $this->entry($principal, $page, $currentRoute)) !== null) {
                $children[] = $entry;
            }
        }

        return $children;
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
        if (($item['processes'] ?? false) && $principal->actingRole() !== Role::Admin && ! $this->processes->allowed($principal->effectiveUser())) {
            return false;
        }
        if (($item['storage'] ?? false) && $principal->actingRole() !== Role::Admin && ! $this->storage->allowed($principal->effectiveUser())) {
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
