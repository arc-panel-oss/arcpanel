<?php

namespace App\Policies;

use App\Auth\Role;
use Closure;

/**
 * The permission matrix of docs/design/03 §6 as data. Policies, navigation
 * and the role tests read it; a capability missing from the table, or a role
 * missing from a row, is denied.
 *
 * E1-S2 fills in admin and user. The reseller row denies everything until
 * E11 adds the tree scope.
 */
final class Capabilities
{
    /** @var array<string, array<string, Scope>> capability => [role => scope] */
    private const MATRIX = [
        'dashboard.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'server.view' => ['admin' => Scope::All],
        'users.view' => ['admin' => Scope::All],

        'web.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'web.create' => ['admin' => Scope::All, 'user' => Scope::Self],
        'web.update' => ['admin' => Scope::All, 'user' => Scope::Self],
        'web.delete' => ['admin' => Scope::All, 'user' => Scope::Self],
        // Users also need POLICY_USER_EDIT_WEB_TEMPLATES (WebDomainPolicy).
        'web.templates.edit' => ['admin' => Scope::All, 'user' => Scope::Self],

        // Modules still served by the legacy pages: navigation only.
        'dns.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'mail.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'db.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'cron.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'backups.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'files.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'logs.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'stats.view' => ['admin' => Scope::All, 'user' => Scope::Self],
        'account.view' => ['admin' => Scope::All, 'user' => Scope::Self],
    ];

    /**
     * Whether $role has $capability. With $scope, the role's scope must also
     * hold for the object (OwnerScope::canSee() or canManage()).
     *
     * @param  (Closure(): bool)|null  $scope
     */
    public static function allows(Role $role, string $capability, ?Closure $scope = null): bool
    {
        $granted = self::scopeOf($role, $capability);
        if ($granted === null) {
            return false;
        }

        return $scope === null || $scope();
    }

    public static function scopeOf(Role $role, string $capability): ?Scope
    {
        return self::MATRIX[$capability][$role->value] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::MATRIX);
    }
}
