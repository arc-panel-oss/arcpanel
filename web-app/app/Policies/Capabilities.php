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

        // Node.js runtimes (E13 spec §6.2); NodePolicy adds the package key
        'node.view' => self::ALL_ROLES,
        'node.manage' => self::ALL_ROLES,
        // Node.js apps (E14 spec §6.2); NodePolicy adds the package key
        'node.apps.view' => self::ALL_ROLES,
        'node.apps.manage' => self::ALL_ROLES,

        // Docker apps (E4 spec §6.2); DockerPolicy adds the engine and the package key
        'docker.apps.view' => self::ALL_ROLES,
        'docker.apps.manage' => self::ALL_ROLES,
        'docker.engine.manage' => self::ADMIN,

        // File manager (E3 spec §6.4); FilePolicy adds suspension and read-only
        'files.view' => self::ALL_ROLES,
        'files.manage' => self::ALL_ROLES,

        'web.view' => self::ALL_ROLES,
        'web.create' => self::ALL_ROLES,
        'web.update' => self::ALL_ROLES,
        'web.delete' => self::ALL_ROLES,
        // Users and resellers also need POLICY_USER_EDIT_WEB_TEMPLATES (WebDomainPolicy).
        'web.templates.edit' => self::ALL_ROLES,

        // Databases (E7 batch 1 spec §5.1)
        'db.view' => self::ALL_ROLES,
        'db.create' => self::ALL_ROLES,
        'db.update' => self::ALL_ROLES,
        'db.delete' => self::ALL_ROLES,

        // Cron jobs (E7 batch 1 spec §5.1)
        'cron.view' => self::ALL_ROLES,
        'cron.create' => self::ALL_ROLES,
        'cron.update' => self::ALL_ROLES,
        'cron.delete' => self::ALL_ROLES,

        // DNS zones and records (E7 batch 1 spec §5.1)
        'dns.view' => self::ALL_ROLES,
        'dns.create' => self::ALL_ROLES,
        'dns.update' => self::ALL_ROLES,
        'dns.delete' => self::ALL_ROLES,
        // Users and resellers also need POLICY_USER_EDIT_DNS_TEMPLATES (DnsPolicy).
        'dns.templates.edit' => self::ALL_ROLES,

        // Mail domains and accounts (E7 batch 1 spec §5.1)
        'mail.view' => self::ALL_ROLES,
        'mail.create' => self::ALL_ROLES,
        'mail.update' => self::ALL_ROLES,
        'mail.delete' => self::ALL_ROLES,
        // Rate limits of domains and accounts: administrators (web/edit/mail).
        'mail.rate.edit' => self::ADMIN,

        // Backups (E7 batch 1 spec §5.1, D7): resellers for their tree
        'backups.view' => self::ALL_ROLES,
        'backups.create' => self::ALL_ROLES,
        'backups.restore' => self::ALL_ROLES,
        'backups.delete' => self::ALL_ROLES,
        'backups.exclusions.edit' => self::ALL_ROLES,

        // Server pages of E7 batch 2 (spec §5.1)
        'server.firewall' => self::ADMIN,
        'server.ips' => self::ADMIN,
        'server.rrd' => self::ADMIN,

        // Logs and statistics (E7 batch 2 spec D4, D5); LogPolicy and
        // StatsPolicy add the arcpanel.conf policies and the reseller rules
        'logs.view' => self::ALL_ROLES,
        'logs.delete' => self::ALL_ROLES,
        'stats.view' => self::ALL_ROLES,

        // Access keys and SSH keys (E7 batch 2 spec D7, D8): own account,
        // administrators any; AccessKeyPolicy adds API_SYSTEM
        'access-keys.view' => self::OWN,
        'access-keys.manage' => self::OWN,
        'ssh-keys.view' => self::OWN,
        'ssh-keys.manage' => self::OWN,

        // Web terminal (E7 batch 2 spec D11); TerminalPolicy adds the shell
        'terminal.use' => self::ALL_ROLES,

        // Quick Install on a web domain (E7 batch 2 spec §10)
        'web.apps.install' => self::ALL_ROLES,

        // Server pages of E7 batch 3 (spec §5.1)
        'server.services' => self::ADMIN,
        'server.settings' => self::ADMIN,
        'server.config' => self::ADMIN,
        'server.updates' => self::ADMIN,

        // System packages of the catalog (E5 spec 2026-10-06-006 §7)
        'server.packages' => self::ADMIN,

        // Search page and the CSR generator of a web domain (E7 batch 3 spec §5.1)
        'search.use' => self::ALL_ROLES,
        'web.ssl.generate' => self::ALL_ROLES,
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
