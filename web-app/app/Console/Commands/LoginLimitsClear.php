<?php

namespace App\Console\Commands;

use App\Auth\LoginService;
use App\Auth\LoginThrottle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Clears the hourly login limit and the 2FA limit of a user name, so an
 * administrator can let a locked-out user in over SSH (E39 W2 spec D2).
 * Called by v-delete-user-login-limits as the panel user.
 */
#[Signature('arcpanel:login-limits:clear {user}')]
#[Description('Clear the login and 2FA limits of a user name')]
final class LoginLimitsClear extends Command
{
    public function handle(LoginThrottle $throttle): int
    {
        $user = (string) $this->argument('user');
        if (preg_match(LoginService::USERNAME_PATTERN, $user) !== 1) {
            $this->error('Invalid user name');

            return self::INVALID;
        }
        $throttle->clear($user);
        $this->info("Login limits of {$user} cleared");

        return self::SUCCESS;
    }
}
