<?php

namespace App\Policies\Concerns;

use App\Auth\Principal;
use App\Policies\Capabilities;
use App\Policies\Scope;

/**
 * Whether a capability's scope reaches an owner: `Self` only the effective
 * user, `Tree` and `All` what OwnerScope lets the principal see. Policies
 * using it have an OwnerScope in $this->scope.
 */
trait ReachesOwner
{
    private function reaches(Principal $principal, string $capability, string $owner): bool
    {
        return match (Capabilities::scopeOf($principal->actingRole(), $capability)) {
            Scope::Self => $owner !== '' && $owner === $principal->effectiveUser(),
            Scope::Tree, Scope::All => $this->scope->canSee($owner),
            null => false,
        };
    }

    /** reaches() for a change: never the protected administrator's objects (E39 W4 §5). */
    private function reachesForChange(Principal $principal, string $capability, string $owner): bool
    {
        return $this->reaches($principal, $capability, $owner) && ! $this->scope->protects($owner);
    }
}
