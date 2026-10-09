<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\Concerns\ReachesOwner;

/**
 * Secret store (E38 spec D11): every account its own secrets, resellers
 * those of their customers, administrators those of any user.
 */
final class UserSecretPolicy
{
    use ReachesOwner;

    public function __construct(private readonly OwnerScope $scope) {}

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'secrets.view', fn () => $this->reaches($principal, 'secrets.view', $owner));
    }

    public function manage(Principal $principal, string $owner): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'secrets.manage', fn () => $this->reaches($principal, 'secrets.manage', $owner));
    }
}
