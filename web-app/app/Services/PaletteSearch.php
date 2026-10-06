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
    private const LIMIT_PER_GROUP = 8;

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
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $groups = [];
        foreach ($this->objects($query) as $label => $items) {
            $groups[] = ['label' => $label, 'items' => array_slice($items, 0, self::LIMIT_PER_GROUP)];
        }
        if (($users = $this->matchingUsers($query)) !== []) {
            $groups[] = ['label' => __('Users'), 'items' => array_slice($users, 0, self::LIMIT_PER_GROUP)];
        }
        if (($pages = $this->pages($query)) !== []) {
            $groups[] = ['label' => __('Pages and actions'), 'items' => $pages];
        }

        return $groups;
    }

    /** @return array<string, list<array{label: string, meta: string, url: string, icon: string}>> */
    private function objects(string $query): array
    {
        // v-search-* accept one word (is_format_valid 'object').
        if (preg_match('/^[\w.@-]+$/u', $query) !== 1) {
            return [];
        }
        $role = $this->principal->actingRole();
        $rows = in_array($role, [Role::Admin, Role::Reseller], true)
            ? $this->arc->json('v-search-object', [$query], Options::allowFailure())
            : $this->arc->json('v-search-user-object', [$this->principal->effectiveUser(), $query], Options::allowFailure());

        $groups = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['TYPE'] ?? '');
            $owner = (string) ($row['USER'] ?? '');
            if (! isset(self::OBJECT_TYPES[$type]) || ! $this->scope->canSee($owner)) {
                continue;
            }
            [$label, $icon, $capability] = self::OBJECT_TYPES[$type];
            if (! Capabilities::allows($role, $capability) || $this->isProtectedAdmin($owner)) {
                continue;
            }
            // Resellers open their customers' objects in the panel app only
            // (E11 spec D4): legacy pages have no "login as" for them.
            $panelUrl = $this->panelUrl($type, $row);
            if ($role === Role::Reseller && $owner !== $this->principal->effectiveUser() && $panelUrl === null) {
                continue;
            }
            $groups[__($label)][] = [
                'label' => (string) ($row['RESULT'] ?? ''),
                'meta' => $owner,
                'url' => $panelUrl ?? $this->objectUrl($type, $row),
                'icon' => $icon,
            ];
        }

        return $groups;
    }

    /**
     * The panel app page of an object whose module has moved (E1-S2, E7),
     * null while the module is still served by the legacy pages.
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

    /**
     * Objects of modules that are still legacy open the legacy edit page,
     * through "log in as" for another user (templates/pages/list_search.php).
     *
     * @param  array<string, mixed>  $row
     */
    private function objectUrl(string $type, array $row): string
    {
        $owner = (string) $row['USER'];
        $result = (string) ($row['RESULT'] ?? '');
        $key = (string) ($row['KEY'] ?? '');

        $query = ['user' => $owner];
        match ($key) {
            'RECORD' => $query += ['domain' => $row['PARENT'] ?? '', 'record_id' => $row['LINK'] ?? ''],
            'ACCOUNT' => $query += ['domain' => $row['PARENT'] ?? '', 'account' => $row['LINK'] ?? ''],
            'JOB' => $query += ['job' => $row['LINK'] ?? ''],
            'DATABASE' => $query += ['database' => $result],
            default => $query[strtolower($key)] = $result,
        };
        $edit = '/edit/'.rawurlencode($type).'/?'.http_build_query($query);

        if ($owner === $this->principal->effectiveUser()) {
            return $edit.'&'.http_build_query(['token' => $this->principal->legacyToken]);
        }

        return '/login/?'.http_build_query([
            'loginas' => $owner,
            'token' => $this->principal->legacyToken,
            'edit_link' => $edit,
        ]);
    }

    /** @return list<array{label: string, meta: string, url: string, icon: string}> */
    private function matchingUsers(string $query): array
    {
        if (! Capabilities::allows($this->principal->actingRole(), 'users.view')) {
            return [];
        }
        $items = [];
        foreach ($this->users->all() as $name => $record) {
            $haystack = $name.' '.($record['NAME'] ?? '').' '.($record['CONTACT'] ?? '');
            if (mb_stripos($haystack, $query) === false || $this->isProtectedAdmin($name) || ! $this->scope->canManageAccount($name)) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'meta' => (string) ($record['NAME'] ?? ''),
                'url' => Route::has('users.show')
                    ? route('users.show', ['user' => $name])
                    : '/edit/user/?'.http_build_query(['user' => $name, 'token' => $this->principal->legacyToken]),
                'icon' => 'user',
            ];
        }

        return $items;
    }

    /** @return list<array{label: string, meta: string, url: string, icon: string}> */
    private function pages(string $query): array
    {
        $items = [];
        foreach ($this->navigation->for($this->principal, null) as $group) {
            foreach ($group['items'] as $item) {
                $items[] = ['label' => $item['label'], 'meta' => $group['label'] ?? __('Pages'), 'url' => $item['url'], 'icon' => $item['icon']];
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
