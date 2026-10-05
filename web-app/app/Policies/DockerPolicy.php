<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Docker\DockerAccess;

/**
 * Docker apps (E4 spec §6.2): every role on the accounts it can see.
 * Installing, starting and changing needs the engine and the account's
 * package to include Docker apps (DOCKER='yes'); stopping and deleting do
 * not, so an account whose package lost Docker apps can still free its
 * resources (as E13 D11). Apps marked admin_only need an administrator.
 * The engine itself is for administrators. Nothing in read-only mode.
 */
final class DockerPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly DockerAccess $access,
    ) {}

    /** See the apps of $user, their state and their logs. */
    public function viewApps(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'docker.apps.view', fn () => $this->scope->canSee($user));
    }

    /** Install apps, start and restart them, change their settings, update. */
    public function manageApps(Principal $principal, string $user): bool
    {
        return $this->removeApps($principal, $user) && $this->access->available($user);
    }

    /** Stop and delete apps. */
    public function removeApps(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'docker.apps.manage', fn () => $this->scope->canSee($user));
    }

    /** Install an app the catalog reserves to administrators. */
    public function installAdminOnly(Principal $principal): bool
    {
        return $principal->actingRole() === Role::Admin;
    }

    /** Install the engine, see the server-wide report. */
    public function manageEngine(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'docker.engine.manage');
    }

    /**
     * What the Docker apps pages may offer for $user.
     *
     * @return array{manage: bool, remove: bool, engine: bool, available: bool}
     */
    public function appAbilities(Principal $principal, string $user): array
    {
        return [
            'manage' => $this->manageApps($principal, $user),
            'remove' => $this->removeApps($principal, $user),
            'engine' => $this->access->engine(),
            'available' => $this->access->allowed($user),
        ];
    }
}
