<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\Concerns\ReachesOwner;

/**
 * Alerts (E16 spec §7): the scope of an account with `alerts.view` and
 * `alerts.manage` and the owner scope (a reseller also its customers' scopes,
 * an administrator every user's); the server scope (`system`) needs
 * `server.view` to read and `server.settings` to change.
 */
final class AlertPolicy
{
    use ReachesOwner;

    public function __construct(private readonly OwnerScope $scope) {}

    public function view(Principal $principal, string $scope): bool
    {
        if ($scope === 'system') {
            return Capabilities::allows($principal->actingRole(), 'server.view');
        }

        return Capabilities::allows($principal->actingRole(), 'alerts.view', fn () => $this->reaches($principal, 'alerts.view', $scope));
    }

    public function manage(Principal $principal, string $scope): bool
    {
        if ($principal->isReadOnly()) {
            return false;
        }
        if ($scope === 'system') {
            return Capabilities::allows($principal->actingRole(), 'server.view')
                && Capabilities::allows($principal->actingRole(), 'server.settings');
        }

        return Capabilities::allows($principal->actingRole(), 'alerts.manage', fn () => $this->reachesForChange($principal, 'alerts.manage', $scope));
    }
}
