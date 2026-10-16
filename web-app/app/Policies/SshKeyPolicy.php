<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\Concerns\ReachesOwner;

/**
 * SSH keys (E7 batch 2 spec D8, §5.2): every account its own keys,
 * administrators those of any user.
 */
final class SshKeyPolicy
{
    use ReachesOwner;

    public function __construct(private readonly OwnerScope $scope) {}

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'ssh-keys.view', fn () => $this->reaches($principal, 'ssh-keys.view', $owner));
    }

    public function manage(Principal $principal, string $owner): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'ssh-keys.manage', fn () => $this->reachesForChange($principal, 'ssh-keys.manage', $owner));
    }
}
