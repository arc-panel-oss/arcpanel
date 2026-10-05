<?php

namespace App\Policies;

use App\Auth\Role;
use Closure;

/**
 * The permission matrix of docs/design/03 §6 as data. Policies, navigation
 * and the role tests read it; a capability missing from the table, or a role
 * missing from a row, is denied.
 *
 * Roles are the role the panel acts with (Principal::actingRole()): a
 * reseller or an administrator who logs in as a customer acts as a user.
 */
final class Capabilities
{
    private const ALL_ROLES = ['admin' => Scope::All, 'reseller' => Scope::Tree, 'user' => Scope::Self];

    private const MANAGERS = ['admin' => Scope::All, 'reseller' => Scope::Tree];

    private const ADMIN = ['admin' => Scope::All];

    /** Modules that resellers use for their own account only. */
    private const OWN = ['admin' => Scope::All, 'reseller' => Scope::Self, 'user' => Scope::Self];

    /** @var array<string, array<string, Scope>> capability => [role => scope] */
    private const MATRIX = [
        'dashboard.view' => self::ALL_ROLES,
        'server.view' => self::ADMIN,

        // Users and customers (E11 spec §7)
        'users.view' => self::MANAGERS,
        'users.create' => self::MANAGERS,
        'users.update' => self::MANAGERS,
        'users.delete' => self::MANAGERS,
        'users.suspend' => self::MANAGERS,
        'users.change-package' => self::MANAGERS,
        'users.change-owner' => self::ADMIN,
        'users.change-role' => self::ADMIN,
        // Shell, PHP CLI, login restrictions of other users
        'users.admin-fields' => self::ADMIN,
        'impersonate' => self::MANAGERS,
        'account.view' => self::OWN,
        'account.update' => self::OWN,

        // Packages (E11 spec §8); PackagePolicy adds the reseller rules
        'packages.view' => self::MANAGERS,
        'packages.create' => self::MANAGERS,
        'packages.update' => self::MANAGERS,
        'packages.delete' => self::MANAGERS,

        // Port registry (E12 spec §7.2); PortPolicy adds the pool rules
        'server.ports' => self::ADMIN,
        'ports.view' => self::ALL_ROLES,
        'ports.mine' => ['reseller' => Scope::Self, 'user' => Scope::Self],
        'ports.reserve' => self::OWN,
        'ports.public' => self::ALL_ROLES,
        'ports.suballocate' => self::MANAGERS,
        'ports.allocate' => self::ADMIN,

        'web.view' => self::ALL_ROLES,
        'web.create' => self::ALL_ROLES,
        'web.update' => self::ALL_ROLES,
        'web.delete' => self::ALL_ROLES,
        // Users and resellers also need POLICY_USER_EDIT_WEB_TEMPLATES (WebDomainPolicy).
        'web.templates.edit' => self::ALL_ROLES,

        // Modules still served by the legacy pages: navigation only. A
        // reseller reaches them for its own account.
        'dns.view' => self::OWN,
        'mail.view' => self::OWN,
        'db.view' => self::OWN,
        'cron.view' => self::OWN,
        'backups.view' => self::OWN,
        'files.view' => self::OWN,
        'logs.view' => self::OWN,
        'stats.view' => self::OWN,
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
