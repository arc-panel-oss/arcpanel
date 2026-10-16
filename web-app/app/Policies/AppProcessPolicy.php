<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Services\AppProcesses\AppProcessAccess;

/**
 * App processes (E42 spec §8): every role on the accounts it can see.
 * Creating, starting and changing needs the account's package to allow app
 * processes (APP_PROCESSES='yes'); stopping and deleting do not, so an
 * account whose package lost the grant can still free its resources.
 * Nothing in read-only mode.
 */
final class AppProcessPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly AppProcessAccess $access,
    ) {}

    /** See the processes of $user, their state and their logs. */
    public function view(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'apps.processes.view', fn () => $this->scope->canSee($user));
    }

    /** Create, start, restart and reload processes, change their settings, run tasks. */
    public function manage(Principal $principal, string $user): bool
    {
        return $this->remove($principal, $user) && $this->access->allowed($user);
    }

    /** Stop and delete processes. */
    public function remove(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'apps.processes.manage', fn () => $this->scope->canManage($user));
    }

    /**
     * What the pages may offer for $user.
     *
     * @return array{manage: bool, remove: bool}
     */
    public function abilities(Principal $principal, string $user): array
    {
        return [
            'manage' => $this->manage($principal, $user),
            'remove' => $this->remove($principal, $user),
        ];
    }
}
