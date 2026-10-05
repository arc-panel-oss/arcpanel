<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * Mail domains and accounts (E7 batch 1 spec §5.2): capability from
 * Capabilities, owner from OwnerScope, and no change in read-only mode.
 * Suspend follows update for every role (D10); rebuild and rate limits are
 * administrator actions (web/bulk/mail, web/edit/mail).
 */
final class MailPolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'mail.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'mail.view', fn () => $this->scope->canSee($owner));
    }

    public function create(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'mail.create', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function update(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'mail.update', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    /**
     * Rate limits of a domain or an account: only an administrator acting
     * as itself (legacy userContext=admin).
     */
    public function updateRateLimit(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner)
            && Capabilities::allows($principal->actingRole(), 'mail.rate.edit')
            && $principal->actingRole() === Role::Admin;
    }

    public function delete(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'mail.delete', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function suspend(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner);
    }

    /** Rebuild is an administrator action in web/bulk/mail. */
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
            'create' => $this->create($principal, $owner),
            'update' => $this->update($principal, $owner),
            'updateRateLimit' => $this->updateRateLimit($principal, $owner),
            'delete' => $this->delete($principal, $owner),
            'suspend' => $this->suspend($principal, $owner),
            'rebuild' => $this->rebuild($principal, $owner),
        ];
    }
}
