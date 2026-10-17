<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Services\Node\NodeAccess;

/**
 * Node.js runtimes and global packages (E13 spec §6.2) and Node.js apps
 * (E14 spec §6.2): every role on the accounts it can see. Installing,
 * creating and changing needs the account's package to include Node.js
 * (NODE='yes'); removing and stopping do not, so an account whose package
 * lost Node.js can still free its resources (E13 D11). Nothing in
 * read-only mode.
 */
final class NodePolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly NodeAccess $access,
    ) {}

    public function view(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'node.view', fn () => $this->scope->canSee($user));
    }

    /** Enable Node.js, install runtimes and packages, change the default, update. */
    public function manage(Principal $principal, string $user): bool
    {
        return $this->remove($principal, $user) && $this->access->allowed($user);
    }

    /** Uninstall runtimes and packages, remove Node.js. */
    public function remove(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'node.manage', fn () => $this->scope->canManage($user));
    }

    /**
     * What the page may offer for $user.
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

    /** See the apps of $user, their state and their logs. */
    public function viewApps(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'node.apps.view', fn () => $this->scope->canSee($user));
    }

    /** Create apps, start, restart and reload them, change their settings, install and build. */
    public function manageApps(Principal $principal, string $user): bool
    {
        return $this->removeApps($principal, $user) && $this->access->allowed($user);
    }

    /** Stop and delete apps. */
    public function removeApps(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'node.apps.manage', fn () => $this->scope->canManage($user));
    }

    /**
     * What the Node apps pages may offer for $user.
     *
     * @return array{manage: bool, remove: bool}
     */
    public function appAbilities(Principal $principal, string $user): array
    {
        return [
            'manage' => $this->manageApps($principal, $user),
            'remove' => $this->removeApps($principal, $user),
        ];
    }
}
