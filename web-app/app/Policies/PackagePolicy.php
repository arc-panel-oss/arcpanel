<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Packages\PackageRecord;

/**
 * Packages (E11 spec §8): administrators manage every package; a reseller
 * sees the packages it may assign and, with POLICY_RESELLER_CREATE_PACKAGES,
 * creates and changes its own (RESELLER_OWNER).
 */
final class PackagePolicy
{
    public function __construct(private readonly SysConfig $config) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'packages.view');
    }

    public function create(Principal $principal): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'packages.create')
            && ($principal->actingRole() === Role::Admin || $this->resellersCreate());
    }

    public function update(Principal $principal, PackageRecord $package): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'packages.update', fn () => $this->owns($principal, $package));
    }

    public function delete(Principal $principal, PackageRecord $package): bool
    {
        return ! $principal->isReadOnly()
            && $package->name !== 'default'
            && Capabilities::allows($principal->actingRole(), 'packages.delete', fn () => $this->owns($principal, $package));
    }

    /** Reseller settings (USERS, RESELLER_PACKAGES, RESELLER_VISIBLE): administrators only. */
    public function manageResellerSettings(Principal $principal): bool
    {
        return $principal->actingRole() === Role::Admin;
    }

    /** @return array<string, bool> */
    public function abilities(Principal $principal, PackageRecord $package): array
    {
        return [
            'update' => $this->update($principal, $package),
            'delete' => $this->delete($principal, $package),
            'copy' => $this->create($principal) && $principal->actingRole() === Role::Admin,
        ];
    }

    private function owns(Principal $principal, PackageRecord $package): bool
    {
        return match ($principal->actingRole()) {
            Role::Admin => true,
            Role::Reseller => $this->resellersCreate() && $package->owner() === $principal->effectiveUser(),
            default => false,
        };
    }

    private function resellersCreate(): bool
    {
        return $this->config->is('POLICY_RESELLER_CREATE_PACKAGES');
    }
}
