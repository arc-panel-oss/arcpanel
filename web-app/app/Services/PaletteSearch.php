<?php

namespace App\Services;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\View\Navigation;
use Illuminate\Support\Facades\Route;

/**
 * Results of the command palette (docs/design/05 §4.2): objects from
 * `v-search-object` (admins and resellers) or `v-search-user-object`
 * (everyone else),
 * filtered by OwnerScope, plus pages and actions from the navigation and a
 * static action list, filtered by Capabilities.
 */
final class PaletteSearch
{
    /** Results per group in the palette; the search page asks for more (E7 batch 3 spec §12.1). */
    public const LIMIT_PER_GROUP = 8;

    public const PAGE_LIMIT = 50;

    /** v-search-* TYPE => [group label, icon, capability] */
    private const OBJECT_TYPES = [
        'web' => ['Web domains', 'globe', 'web.view'],
        'dns' => ['DNS', 'dns', 'dns.view'],
        'mail' => ['Mail', 'mail', 'mail.view'],
        'db' => ['Databases', 'db', 'db.view'],
        'cron' => ['Cron Jobs', 'clock', 'cron.view'],
    ];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly Principal $principal,
        private readonly OwnerScope $scope,
        private readonly Navigation $navigation,
        private readonly UserDirectory $users,
        private readonly SysConfig $config,
    ) {}

    /** @return list<array{label: string, items: list<array{label: string, meta: string, url: string, icon: string}>}> */
    public function search(string $query, int $limit = self::LIMIT_PER_GROUP): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $groups = [];
        foreach ($this->objects($query) as $label => $items) {
            $groups[] = ['label' => $label, 'items' => array_slice($items, 0, $limit)];
        }
        if (($users = $this->matchingUsers($query)) !== []) {
            $groups[] = ['label' => __('Users'), 'items' => array_slice($users, 0, $limit)];
        }
        if (($pages = $this->pages($query)) !== []) {
            $groups[] = ['label' => __('Pages and actions'), 'items' => $pages];
        }

        return $groups;
    }

    /**
     * The objects of `v-search-object` (admins and resellers) or
     * `v-search-user-object` (everyone else) that the principal may see,
     * in the order of the command. The search page and API v2 (E34 batch 6
     * §4.3) share it.
     *
     * @return list<array{type: string, key: string, result: string, owner: string, parent: string, link: string}>
     */
    public function objectRows(string $query): array
    {
        // v-search-* accept one word (is_format_valid 'object').
        $query = trim($query);
        if (mb_strlen($query) < 2 || preg_match('/^[\w.@-]+$/u', $query) !== 1) {
            return [];
        }
        $role = $this->principal->actingRole();
        $rows = in_array($role, [Role::Admin, Role::Reseller], true)
            ? $this->arc->json('v-search-object', [$query], Options::allowFailure())
            : $this->arc->json('v-search-user-object', [$this->principal->effectiveUser(), $query], Options::allowFailure());

        $objects = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['TYPE'] ?? '');
            $owner = (string) ($row['USER'] ?? '');
            if (! isset(self::OBJECT_TYPES[$type]) || ! $this->scope->canSee($owner)) {
                continue;
            }
            if (! Capabilities::allows($role, self::OBJECT_TYPES[$type][2]) || $this->isProtectedAdmin($owner)) {
                continue;
            }
            $objects[] = [
                'type' => $type,
                'key' => (string) ($row['KEY'] ?? ''),
                'result' => (string) ($row['RESULT'] ?? ''),
                'owner' => $owner,
                'parent' => (string) ($row['PARENT'] ?? ''),
                'link' => (string) ($row['LINK'] ?? ''),
            ];
        }

        return $objects;
    }

    /**
     * Users whose name, full name or contact contains the query, among those
     * the principal manages.
     *
     * @return list<array{name: string, full_name: string}>
     */
    public function userRows(string $query): array
    {
        if (! Capabilities::allows($this->principal->actingRole(), 'users.view')) {
            return [];
        }
        $users = [];
        foreach ($this->users->all() as $name => $record) {
            $name = (string) $name;
            $haystack = $name.' '.($record['NAME'] ?? '').' '.($record['CONTACT'] ?? '');
            if (mb_stripos($haystack, $query) === false || $this->isProtectedAdmin($name) || ! $this->scope->canManageAccount($name)) {
                continue;
            }
            $users[] = ['name' => $name, 'full_name' => (string) ($record['NAME'] ?? '')];
        }

        return $users;
    }

    /** @return array<string, list<array{label: string, meta: string, url: string, icon: string}>> */
    private function objects(string $query): array
    {
        $groups = [];
        foreach ($this->objectRows($query) as $object) {
            [$label, $icon] = self::OBJECT_TYPES[$object['type']];
            $url = $this->panelUrl($object['type'], [
                'USER' => $object['owner'], 'KEY' => $object['key'], 'RESULT' => $object['result'],
                'PARENT' => $object['parent'], 'LINK' => $object['link'],
            ]);
            if ($url === null) {
                continue;
            }
            $groups[__($label)][] = [
                'label' => $object['result'],
                'meta' => $object['owner'],
                'url' => $url,
                'icon' => $icon,
            ];
        }

        return $groups;
    }

    /**
     * The panel app page of an object; null for object kinds without one.
     *
     * @param  array<string, mixed>  $row
     */
    private function panelUrl(string $type, array $row): ?string
    {
        $owner = (string) $row['USER'];
        $result = (string) ($row['RESULT'] ?? '');
        $parent = (string) ($row['PARENT'] ?? '');
        $link = (string) ($row['LINK'] ?? '');

        [$route, $params] = match ($type.'/'.($row['KEY'] ?? '')) {
            'web/DOMAIN' => ['web.domains.edit', ['domain' => $result]],
            'db/DATABASE' => ['db.edit', ['database' => $result]],
            'cron/JOB' => ['cron.edit', ['job' => $link]],
            'dns/DOMAIN' => ['dns.records.index', ['domain' => $result]],
            'dns/RECORD' => ['dns.records.index', ['domain' => $parent]],
            'mail/DOMAIN' => ['mail.accounts.index', ['domain' => $result]],
            'mail/ACCOUNT' => ['mail.accounts.edit', ['domain' => $parent, 'account' => $link]],
            default => [null, []],
        };
        if ($route === null || ! Route::has($route) || in_array('', $params, true)) {
            return null;
        }

        return route($route, ['user' => $owner, ...$params]);
    }

    /** @return list<array{label: string, meta: string, url: string, icon: string}> */
    private function matchingUsers(string $query): array
    {
        return array_map(fn (array $user) => [
            'label' => $user['name'],
            'meta' => $user['full_name'],
            'url' => route('users.show', ['user' => $user['name']]),
            'icon' => 'user',
        ], $this->userRows($query));
    }

    /** @return list<array{label: string, meta: string, url: string, icon: string}> */
    private function pages(string $query): array
    {
        $items = [];
        foreach ($this->navigation->for($this->principal, null) as $group) {
            foreach ($group['items'] as $item) {
                // The pages of a hub, each under its own name (sidebar hubs spec H8)
                foreach ($item['children'] ?? [] as $page) {
                    $items[] = ['label' => $page['label'], 'meta' => $item['label'], 'url' => $page['url'], 'icon' => $page['icon']];
                }
                if (! isset($item['children'])) {
                    $items[] = ['label' => $item['label'], 'meta' => $group['label'] ?? __('Pages'), 'url' => $item['url'], 'icon' => $item['icon']];
                }
            }
        }

        $role = $this->principal->actingRole();
        $creates = [
            ['web.create', 'web.domains.create', 'Add Web Domain', 'Web'],
            ['db.create', 'db.create', 'Add Database', 'Databases'],
            ['cron.create', 'cron.create', 'Add Cron Job', 'Cron Jobs'],
            ['dns.create', 'dns.create', 'Add DNS Domain', 'DNS'],
            ['mail.create', 'mail.create', 'Add Mail Domain', 'Mail'],
            ['server.firewall', 'server.firewall.create', 'Add Rule', 'Firewall'],
            ['server.ips', 'server.ips.create', 'Add IP Address', 'IP Addresses'],
        ];
        foreach ($creates as [$capability, $route, $label, $meta]) {
            if (Capabilities::allows($role, $capability) && ! $this->principal->isReadOnly() && Route::has($route)) {
                $items[] = ['label' => __($label), 'meta' => __($meta), 'url' => route($route), 'icon' => 'plus'];
            }
        }
        if (Capabilities::allows($role, 'users.create') && ! $this->principal->isReadOnly()) {
            $items[] = ['label' => $role === Role::Reseller ? __('Add Customer') : __('Add User'), 'meta' => __('Users'), 'url' => (string) Navigation::url('users.create'), 'icon' => 'user-plus'];
        }

        return array_values(array_filter($items, fn (array $item) => mb_stripos($item['label'], $query) !== false));
    }

    /** POLICY_SYSTEM_PROTECTED_ADMIN hides the system administrator from other admins. */
    private function isProtectedAdmin(string $owner): bool
    {
        return $this->config->is('POLICY_SYSTEM_PROTECTED_ADMIN')
            && $owner === $this->config->get('ROOT_USER')
            && $this->principal->loginUser !== $owner;
    }
}
