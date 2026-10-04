<?php

namespace App\Auth;

/**
 * Request facts every login step logs, as web/login/index.php passes them to
 * v-log-user-login.
 */
final class LoginContext
{
    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent,
        /** 32 hex chars; becomes $_SESSION['token'] after a successful login */
        public readonly string $fingerprint,
    ) {}
}
