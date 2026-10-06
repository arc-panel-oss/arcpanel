<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * Statistics (E7 batch 2 spec D4, §5.2): administrators see the server
 * totals, resellers the totals of their tree, everyone its own months.
 */
final class StatsPolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'stats.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'stats.view', fn () => $this->scope->canSee($owner));
    }

    /** The totals of the server (`v-list-users-stats`). */
    public function overall(Principal $principal): bool
    {
        return $this->viewAny($principal) && $principal->actingRole() === Role::Admin;
    }

    /** The totals of the reseller's tree (`v-list-reseller-users-stats`). */
    public function tree(Principal $principal): bool
    {
        return $this->viewAny($principal) && $principal->actingRole() === Role::Reseller;
    }
}
