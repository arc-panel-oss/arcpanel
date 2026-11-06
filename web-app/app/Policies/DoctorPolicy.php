<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\Concerns\ReachesOwner;

/**
 * The Doctor (E17 spec §5): the domains of an account with `doctor.view`
 * and `doctor.run` and the owner scope (a reseller also its customers', an
 * administrator every user's); the server checks (`system`) need
 * `server.view` to read and `server.settings` to run or fix. A fix runs as a
 * job with the rights of the person who asked, so the same rule decides it.
 */
final class DoctorPolicy
{
    use ReachesOwner;

    public function __construct(private readonly OwnerScope $scope) {}

    public function view(Principal $principal, string $user): bool
    {
        if ($user === 'system') {
            return Capabilities::allows($principal->actingRole(), 'server.view');
        }

        return Capabilities::allows($principal->actingRole(), 'doctor.view', fn () => $this->reaches($principal, 'doctor.view', $user));
    }

    public function run(Principal $principal, string $user): bool
    {
        if ($principal->isReadOnly()) {
            return false;
        }
        if ($user === 'system') {
            return Capabilities::allows($principal->actingRole(), 'server.view')
                && Capabilities::allows($principal->actingRole(), 'server.settings');
        }

        return Capabilities::allows($principal->actingRole(), 'doctor.run', fn () => $this->reachesForChange($principal, 'doctor.run', $user));
    }
}
