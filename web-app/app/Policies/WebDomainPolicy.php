<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * Web domains (docs/design/03 §7.2): capability from Capabilities, owner
 * from OwnerScope, and no change in read-only mode.
 */
final class WebDomainPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly SysConfig $config,
    ) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'web.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'web.view', fn () => $this->scope->canSee($owner));
    }

    public function create(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'web.create', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function update(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'web.update', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    /**
     * Templates, proxy and cache settings: administrators (also while
     * impersonating, as web/edit/web checks the login context), or users
     * when POLICY_USER_EDIT_WEB_TEMPLATES allows it.
     */
    public function updateTemplates(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner)
            && Capabilities::allows($principal->actingRole(), 'web.templates.edit')
            && ($principal->loginRole === Role::Admin || $this->config->is('POLICY_USER_EDIT_WEB_TEMPLATES'));
    }

    public function delete(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'web.delete', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function suspend(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner);
    }

    /** Rebuild is an administrator action in web/bulk/web. */
    public function rebuild(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner) && $principal->actingRole() === Role::Admin;
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
