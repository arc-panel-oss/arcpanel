<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * Databases (E7 batch 1 spec §5.2): capability from Capabilities, owner from
 * OwnerScope, and no change in read-only mode.
 */
final class DatabasePolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'db.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'db.view', fn () => $this->scope->canSee($owner));
    }

    public function create(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'db.create', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function update(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'db.update', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function delete(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'db.delete', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function suspend(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner);
    }

    /** Rebuild is an administrator action in web/bulk/db. */
    public function rebuild(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner) && $principal->actingRole() === Role::Admin;
    }

    /** Whether a bulk or single action is allowed for the owner. */
    public function action(string $action, Principal $principal, string $owner): bool
    {
        return match ($action) {
            'delete' => $this->delete($principal, $owner),
            'rebuild' => $this->rebuild($principal, $owner),
            default => $this->suspend($principal, $owner),
        };
    }

    /**
     * What the views may show; they never compare user names (03 §7.3).
     *
     * @return array<string, bool>
     */
    public function abilities(Principal $principal, string $owner): array
    {
        return [
            'view' => $this->view($principal, $owner),
            'update' => $this->update($principal, $owner),
            'delete' => $this->delete($principal, $owner),
            'suspend' => $this->suspend($principal, $owner),
            'rebuild' => $this->rebuild($principal, $owner),
        ];
    }
}
