<?php

namespace App\Auth;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Tenancy\TenancyDirectory;

/**
 * Builds the Principal from the legacy session with the same checks, in the
 * same order, as web/inc/main.php (lines 39-151), so a session behaves the
 * same whichever app serves the page.
 */
final class PrincipalResolver
{
    /** Message of main.php when a session is ended for the user's sake. */
    public const LOGGED_OUT = 'You are logged out, please log in again.';

    /** Session key of a reseller's "login as" (E11 spec D4); the legacy app never reads it. */
    public const RESELLER_LOOK = 'arc_look';

    /** Set when the last resolve() ended a session and the user should be told. */
    public ?string $logoutMessage = null;

    /** Set when the last resolve() dropped a reseller's "login as" that is no longer allowed. */
    public bool $impersonationEnded = false;

    public function __construct(
        private readonly LegacySession $session,
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly TenancyDirectory $tenancy,
    ) {}

    /** Null when nobody is logged in (or the session was just ended). */
    public function resolve(string $clientIp, ?int $now = null): ?Principal
    {
        $now ??= time();
        $this->logoutMessage = null;
        $this->impersonationEnded = false;

        if (! $this->session->exists()) {
            return null;
        }
        // Copy the data and release the file lock at once: the checks below run
        // v-* commands, and write() re-opens the session for the final patch.
        $data = $this->session->read();
        $this->session->close();
        $user = is_string($data['user'] ?? null) ? $data['user'] : '';

        if ($user === '') {
            return null;
        }

        // 1. Session used from another IP than the one it logged in from
        $sessionIp = (string) ($data['user_combined_ip'] ?? '');
        if ($sessionIp !== '' && $sessionIp !== $clientIp && ! $this->config->is('DISABLE_IP_CHECK')) {
            return $this->end($user, $data, logLogout: true);
        }

        // 2. System configuration missing (main.php checks $_SESSION['VERSION'])
        if ($this->config->get('VERSION') === '') {
            return $this->end($user, $data);
        }

        // 3. User deleted
        $record = $this->userRecord($user);
        if ($record === null) {
            return $this->end($user, $data, message: self::LOGGED_OUT);
        }

        // 4. Admin or reseller demoted since login
        $loginRole = Role::fromValue($data['userContext'] ?? '');
        if (in_array($loginRole, [Role::Admin, Role::Reseller], true) && ($record['ROLE'] ?? '') !== $loginRole->value) {
            return $this->end($user, $data);
        }

        // 5. Suspended user, unless suspended users may still look around
        if (($record['SUSPENDED'] ?? '') === 'yes' && ! $this->config->is('POLICY_USER_VIEW_SUSPENDED')) {
            return $this->end($user, $data, message: self::LOGGED_OUT);
        }

        // 6. Inactivity timeout
        $lastActivity = (int) ($data['LAST_ACTIVITY'] ?? 0);
        $timeout = (int) $this->config->get('INACTIVE_SESSION_TIMEOUT', '0');
        if ($lastActivity === 0 || $timeout === 0) {
            return $this->end($user, $data);
        }
        if ($timeout * 60 + $lastActivity < $now) {
            return $this->end($user, $data, logLogout: true);
        }

        // 7. Refresh shell and role from the effective user. Administrators
        // impersonate through the legacy key `look`, resellers through
        // `arc_look`, checked again on every request.
        $look = null;
        $resellerLook = false;
        if ($loginRole === Role::Admin) {
            $look = self::nonEmpty($data['look'] ?? null);
        } elseif ($loginRole === Role::Reseller && ($target = self::nonEmpty($data[self::RESELLER_LOOK] ?? null)) !== null) {
            if ($this->tenancy->isCustomerOf($target, $user) && ! $this->config->is('POLICY_RESELLER_LOGIN_AS', 'no')) {
                $look = $target;
                $resellerLook = true;
            } else {
                $this->impersonationEnded = true;
            }
        }
        $effective = $record;
        if ($look !== null) {
            $effective = $this->userRecord($look) ?? $record;
        }

        $token = is_string($data['token'] ?? null) && $data['token'] !== ''
            ? $data['token']
            : bin2hex(random_bytes(16));

        // The legacy app and the web terminal never see a reseller's "login
        // as": they keep the reseller's own shell and role.
        $legacyView = $resellerLook ? $record : $effective;
        $this->session->write(array_filter([
            'user_combined_ip' => $sessionIp === '' ? $clientIp : null,
            'login_shell' => $legacyView['SHELL'] ?? '',
            'role' => $legacyView['ROLE'] ?? '',
            'token' => $token,
            'look' => $resellerLook ? '' : ($look ?? ''),
            'LAST_ACTIVITY' => $now,
        ], fn ($value) => $value !== null) + ($this->impersonationEnded ? [self::RESELLER_LOOK => null] : []));

        $effectiveRole = Role::fromValue($effective['ROLE'] ?? '');

        return new Principal(
            loginUser: $user,
            impersonated: $look,
            loginRole: $loginRole,
            effectiveRole: $effectiveRole,
            loginShell: (string) ($effective['SHELL'] ?? ''),
            language: (string) ($data['language'] ?? 'en'),
            theme: isset($data['userTheme']) ? (string) $data['userTheme'] : null,
            legacyToken: $token,
            readOnly: $this->isReadOnly($loginRole, $look, $effective),
        );
    }

    /**
     * web/inc/policies.php: a suspended user who may only look, or an admin
     * looking at the protected system administrator.
     *
     * @param  array<string, string>  $effective
     */
    private function isReadOnly(Role $loginRole, ?string $look, array $effective): bool
    {
        if ($loginRole === Role::User
            && ($effective['SUSPENDED'] ?? '') === 'yes'
            && $this->config->is('POLICY_USER_VIEW_SUSPENDED')) {
            return true;
        }

        return $loginRole === Role::Admin
            && $look !== null
            && $look === $this->config->get('ROOT_USER')
            && $this->config->is('POLICY_SYSTEM_PROTECTED_ADMIN');
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string>|null */
    private function userRecord(string $user): ?array
    {
        $data = $this->arc->json('v-list-user', [$user], Options::allowFailure());

        return isset($data[$user]) && is_array($data[$user]) ? $data[$user] : null;
    }

    /** @param  array<string, mixed>  $data */
    private function end(string $user, array $data, bool $logLogout = false, ?string $message = null): null
    {
        if ($logLogout) {
            $this->arc->run(
                'v-log-user-logout',
                [$user, (string) ($data['token'] ?? '')],
                Options::allowFailure(),
            );
        }
        $this->session->destroy();
        $this->logoutMessage = $message;

        return null;
    }
}
