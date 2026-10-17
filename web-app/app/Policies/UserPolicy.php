<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Tenancy\TenancyDirectory;

/**
 * Users and customers (E11 spec §7, docs/design/03 §7.2): capability from
 * Capabilities, reach from OwnerScope, no change in read-only mode. A
 * principal edits its own account through "My account" (account.update),
 * never through the user management actions.
 */
final class UserPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
    ) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'users.view');
    }

    public function view(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'users.view', fn () => $this->scope->canSeeAccount($user));
    }

    public function create(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'users.create') && ! $principal->isReadOnly();
    }

    /** Name, contact, password, language, login IP list: managers, or the user itself. */
    public function update(Principal $principal, string $user): bool
    {
        if ($principal->isReadOnly() || $this->isProtectedAdmin($user)) {
            return false;
        }
        if ($this->isSelf($principal, $user)) {
            return Capabilities::allows($principal->actingRole(), 'account.update');
        }

        return Capabilities::allows($principal->actingRole(), 'users.update', fn () => $this->scope->canManageAccount($user));
    }

    public function changePackage(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && ! $this->isProtectedAdmin($user)
            && Capabilities::allows($principal->actingRole(), 'users.change-package', fn () => $this->scope->canManageAccount($user)
                // Administrators set their own package too (web/edit/user).
                || ($principal->actingRole() === Role::Admin && $this->isSelf($principal, $user)));
    }

    /** Nameservers: administrators (web/edit/user) and resellers for their customers. */
    public function changeNameservers(Principal $principal, string $user): bool
    {
        return $this->changePackage($principal, $user);
    }

    /** Disable the login or remove two-factor authentication of someone else. */
    public function manageSecurity(Principal $principal, string $user): bool
    {
        return ! $this->isSelf($principal, $user) && $this->update($principal, $user)
            && Capabilities::allows($principal->actingRole(), 'users.admin-fields');
    }

    /** Role, owner, shell and PHP CLI: administrators only. */
    public function administer(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && ! $this->isProtectedAdmin($user)
            && Capabilities::allows($principal->actingRole(), 'users.admin-fields', fn () => $this->scope->canManageAccount($user)
                || $this->isSelf($principal, $user));
    }

    /** Role changes never touch the system administrator or oneself. */
    public function changeRole(Principal $principal, string $user): bool
    {
        return $this->administer($principal, $user)
            && ! $this->isSelf($principal, $user)
            && $user !== $this->config->get('ROOT_USER')
            && Capabilities::allows($principal->actingRole(), 'users.change-role');
    }

    public function changeOwner(Principal $principal, string $user): bool
    {
        return $this->changeRole($principal, $user)
            && $this->tenancy->roleOf($user) === Role::User
            && Capabilities::allows($principal->actingRole(), 'users.change-owner');
    }

    public function suspend(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && $user !== $this->config->get('ROOT_USER')
            && Capabilities::allows($principal->actingRole(), 'users.suspend', fn () => $this->scope->canManageAccount($user));
    }

    public function delete(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && $user !== $this->config->get('ROOT_USER')
            && Capabilities::allows($principal->actingRole(), 'users.delete', fn () => $this->scope->canManageAccount($user));
    }

    /**
     * What the views may show; they never compare user names (03 §7.3).
     *
     * @return array<string, bool>
     */
    public function abilities(Principal $principal, string $user, ImpersonationPolicy $impersonation): array
    {
        return [
            'view' => $this->view($principal, $user),
            'update' => $this->update($principal, $user),
            'changePackage' => $this->changePackage($principal, $user),
            'changeNameservers' => $this->changeNameservers($principal, $user),
            'manageSecurity' => $this->manageSecurity($principal, $user),
            'administer' => $this->administer($principal, $user),
            'changeRole' => $this->changeRole($principal, $user),
            'changeOwner' => $this->changeOwner($principal, $user),
            'suspend' => $this->suspend($principal, $user),
            'delete' => $this->delete($principal, $user),
            'impersonate' => ! $principal->isImpersonating() && $impersonation->start($principal, $user),
        ];
    }

    private function isSelf(Principal $principal, string $user): bool
    {
        return $user === $principal->effectiveUser();
    }

    /**
     * POLICY_SYSTEM_PROTECTED_ADMIN: other administrators may not change
     * ROOT_USER, the guard of every object too (E39 W4 §5). Checked apart
     * from canManageAccount() for the paths that allow one's own account.
     */
    private function isProtectedAdmin(string $user): bool
    {
        return $this->scope->protects($user);
    }
}
