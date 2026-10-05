<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * DNS zones and records (E7 batch 1 spec §5.2): capability from
 * Capabilities, owner from OwnerScope, and no change in read-only mode.
 * Suspend follows update for every role (D10), in bulk and for single
 * zones and records.
 */
final class DnsPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly SysConfig $config,
    ) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'dns.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'dns.view', fn () => $this->scope->canSee($owner));
    }

    public function create(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'dns.create', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function update(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'dns.update', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    /**
     * The zone template: administrators (also while impersonating, as
     * web/edit/dns checks the login context), or users when
     * POLICY_USER_EDIT_DNS_TEMPLATES allows it. Checked on add as well (§11).
     */
    public function updateTemplates(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner)
            && Capabilities::allows($principal->actingRole(), 'dns.templates.edit')
            && ($principal->loginRole === Role::Admin || $this->config->is('POLICY_USER_EDIT_DNS_TEMPLATES'));
    }

    public function delete(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'dns.delete', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function suspend(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner);
    }

    /** Rebuild is an administrator action in web/bulk/dns. */
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
            'updateTemplates' => $this->updateTemplates($principal, $owner),
            'delete' => $this->delete($principal, $owner),
            'suspend' => $this->suspend($principal, $owner),
            'rebuild' => $this->rebuild($principal, $owner),
        ];
    }
}
