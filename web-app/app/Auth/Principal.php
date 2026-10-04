<?php

namespace App\Auth;

/**
 * Who is acting in this request. Built once by PrincipalResolver and bound in
 * the container; controllers read this instead of the session.
 */
final class Principal
{
    public function __construct(
        /** $_SESSION['user'] */
        public readonly string $loginUser,
        /** $_SESSION['look'], null when not impersonating */
        public readonly ?string $impersonated,
        /** $_SESSION['userContext'] */
        public readonly Role $loginRole,
        /** $_SESSION['role']: role of the effective user */
        public readonly Role $effectiveRole,
        public readonly string $loginShell,
        public readonly string $language,
        public readonly ?string $theme,
        /** $_SESSION['token']: CSRF token of legacy pages, also the auth log fingerprint */
        public readonly string $legacyToken,
        /** web/inc/policies.php */
        public readonly bool $readOnly,
    ) {}

    public function effectiveUser(): string
    {
        return $this->impersonated ?? $this->loginUser;
    }

    public function isImpersonating(): bool
    {
        return $this->impersonated !== null;
    }

    public function isAdmin(): bool
    {
        return $this->loginRole === Role::Admin;
    }
}
