<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Tenancy\TenancyDirectory;

/**
 * File manager (E3 spec §6.4): every role on the accounts it can see.
 * Changes need write access (not read-only) and, on a suspended account,
 * an administrator (D10).
 */
final class FilePolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly TenancyDirectory $tenancy,
    ) {}

    public function view(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'files.view', fn () => $this->scope->canSee($user));
    }

    public function manage(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && $this->view($principal, $user)
            && Capabilities::allows($principal->actingRole(), 'files.manage', fn () => $this->scope->canManage($user))
            && (! $this->suspended($user) || $principal->actingRole() === Role::Admin);
    }

    /** @return array{manage: bool} */
    public function abilities(Principal $principal, string $user): array
    {
        return ['manage' => $this->manage($principal, $user)];
    }

    private function suspended(string $user): bool
    {
        return $this->tenancy->all()[$user]['suspended'] ?? false;
    }
}
