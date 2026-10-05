<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Tenancy\TenancyDirectory;

/**
 * Who may "login as" whom. Administrators: anyone but themselves, as
 * web/login/index.php. Resellers (E11 spec D4): their own customers, when
 * POLICY_RESELLER_LOGIN_AS allows it.
 */
final class ImpersonationPolicy
{
    public function __construct(
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
    ) {}

    public function start(Principal $principal, string $target): bool
    {
        if ($target === '' || $target === $principal->loginUser || ! Capabilities::allows($principal->loginRole, 'impersonate')) {
            return false;
        }

        return match ($principal->loginRole) {
            Role::Admin => true,
            Role::Reseller => $this->resellerMay($principal->loginUser, $target),
            default => false,
        };
    }

    /** A reseller may act as $target: the policy is on and $target is its customer. */
    public function resellerMay(string $reseller, string $target): bool
    {
        return ! $this->config->is('POLICY_RESELLER_LOGIN_AS', 'no')
            && $this->tenancy->isCustomerOf($target, $reseller);
    }
}
