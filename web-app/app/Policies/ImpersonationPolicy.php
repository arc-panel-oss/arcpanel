<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may "login as" whom. E1: administrators only (as web/login/index.php);
 * E11 adds resellers for users they own.
 */
final class ImpersonationPolicy
{
    public function start(Principal $principal, string $target): bool
    {
        return $principal->loginRole === Role::Admin
            && $target !== ''
            && $target !== $principal->loginUser;
    }
}
