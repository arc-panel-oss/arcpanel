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
        /** $_SESSION['token']: the login fingerprint of v-log-user-login, passed to v-log-user-logout */
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

    /**
     * Role the panel acts with: the effective user's role while
     * impersonating (the legacy pages show the user's view then), else the
     * login role.
     */
    public function actingRole(): Role
    {
        return $this->isImpersonating() ? $this->effectiveRole : $this->loginRole;
    }

    /** web/inc/policies.php: suspended user who may only look, or the protected admin. */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
