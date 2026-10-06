<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;

/**
 * Action log and login history (E7 batch 2 spec D5, §5.2). Administrators,
 * also while acting as a user, read and delete every log; everyone else
 * follows POLICY_USER_VIEW_LOGS and POLICY_USER_DELETE_LOGS, and a reseller
 * reads the logs of its customers without deleting them.
 */
final class LogPolicy
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly SysConfig $config,
    ) {}

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'logs.view', fn () => $this->scope->canSee($owner))
            && ($principal->loginRole === Role::Admin || ! $this->config->is('POLICY_USER_VIEW_LOGS', 'no'));
    }

    public function delete(Principal $principal, string $owner): bool
    {
        if ($principal->isReadOnly() || ! $this->view($principal, $owner)
            || ! Capabilities::allows($principal->actingRole(), 'logs.delete', fn () => $this->scope->canManage($owner))) {
            return false;
        }
        if ($principal->loginRole === Role::Admin) {
            return true;
        }

        return $owner === $principal->effectiveUser() && ! $this->config->is('POLICY_USER_DELETE_LOGS', 'no');
    }

    /** The login history: not in demo mode; the protected admin's only for itself. */
    public function viewAuth(Principal $principal, string $owner): bool
    {
        if (! $this->view($principal, $owner) || $this->config->is('DEMO_MODE')) {
            return false;
        }

        return ! ($owner === $this->config->get('ROOT_USER')
            && $this->config->is('POLICY_SYSTEM_PROTECTED_ADMIN')
            && $principal->loginUser !== $owner);
    }

    public function deleteAuth(Principal $principal, string $owner): bool
    {
        return $this->viewAuth($principal, $owner) && $this->delete($principal, $owner);
    }
}
