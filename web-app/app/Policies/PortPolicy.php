<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Ports\PortEntry;
use App\Tenancy\TenancyDirectory;

/**
 * The port registry (E12 spec §7.2): administrators allocate and repair;
 * a reseller gives free ports of its pool to its customers, takes unused
 * ones back and may forbid public ports; every user reserves, releases and
 * opens its own ports when they are allowed to be public. Capability from
 * Capabilities, reach from OwnerScope, nothing in read-only mode.
 */
final class PortPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly TenancyDirectory $tenancy,
    ) {}

    /** Admin → Server → Ports */
    public function viewServer(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.ports');
    }

    /** The /ports page of a reseller or a user */
    public function viewOwn(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'ports.mine');
    }

    public function view(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'ports.view', fn () => $this->scope->canSee($user));
    }

    /** Reserve and release ports of $user's own pool. */
    public function reserve(Principal $principal, string $user): bool
    {
        return $this->writable($principal)
            && Capabilities::allows($principal->actingRole(), 'ports.reserve', fn () => $this->own($principal, $user));
    }

    /** Open or close an assigned port; only ports allowed to be public open. */
    public function setPublic(Principal $principal, string $user, PortEntry $entry, bool $public): bool
    {
        return $this->writable($principal)
            && $entry->assigned() && ! $entry->isRange()
            && (! $public || $entry->publicOk)
            && Capabilities::allows($principal->actingRole(), 'ports.public', fn () => $this->scope->canSee($user));
    }

    /**
     * Allow or forbid public ports: administrators both ways; a reseller
     * forbids them on its customers' ports (it cannot grant what the
     * administrator did not).
     */
    public function setPublicOk(Principal $principal, string $user, bool $publicOk): bool
    {
        if (! $this->writable($principal)) {
            return false;
        }

        return match ($principal->actingRole()) {
            Role::Admin => Capabilities::allows(Role::Admin, 'ports.allocate'),
            Role::Reseller => ! $publicOk && $this->isCustomer($principal, $user),
            default => false,
        };
    }

    /** Give free ports of the owner's pool to $user (a reseller's customer). */
    public function give(Principal $principal, string $user): bool
    {
        if (! $this->writable($principal) || ! Capabilities::allows($principal->actingRole(), 'ports.suballocate')) {
            return false;
        }

        return match ($principal->actingRole()) {
            Role::Admin => $this->tenancy->isReseller($this->tenancy->ownerOf($user)),
            Role::Reseller => $this->isCustomer($principal, $user),
            default => false,
        };
    }

    /** Take free ports of $user back into its reseller's pool. */
    public function reclaim(Principal $principal, string $user): bool
    {
        return $this->give($principal, $user);
    }

    /** Allocate from the server range, delete ports, change the range, repair. */
    public function allocate(Principal $principal): bool
    {
        return $this->writable($principal) && Capabilities::allows($principal->actingRole(), 'ports.allocate');
    }

    /** "Request more ports": a user or reseller asks its owner. */
    public function request(Principal $principal): bool
    {
        return $this->writable($principal) && $principal->actingRole() !== Role::Admin
            && $this->tenancy->ownerOf($principal->effectiveUser()) !== '';
    }

    /**
     * What the views may show for one user's ports.
     *
     * @return array<string, bool>
     */
    public function abilities(Principal $principal, string $user): array
    {
        return [
            'reserve' => $this->reserve($principal, $user),
            // per entry, setPublic() also needs an assigned, public-capable port
            'public' => $this->writable($principal)
                && Capabilities::allows($principal->actingRole(), 'ports.public', fn () => $this->scope->canSee($user)),
            'publicOk' => $this->setPublicOk($principal, $user, true),
            'forbidPublic' => $this->setPublicOk($principal, $user, false),
            'give' => $this->give($principal, $user),
            'reclaim' => $this->reclaim($principal, $user),
            'delete' => $this->allocate($principal),
        ];
    }

    private function writable(Principal $principal): bool
    {
        return ! $principal->isReadOnly();
    }

    /** The principal's own ports; administrators act on anyone's. */
    private function own(Principal $principal, string $user): bool
    {
        return $principal->actingRole() === Role::Admin
            ? $this->tenancy->exists($user)
            : $user === $principal->effectiveUser();
    }

    private function isCustomer(Principal $principal, string $user): bool
    {
        return $this->tenancy->isCustomerOf($user, $principal->effectiveUser());
    }
}
