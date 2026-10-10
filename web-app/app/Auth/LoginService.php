<?php

namespace App\Auth;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Arc\SysConfig;

/**
 * The login algorithm of web/login/index.php (authenticate_user()), without
 * HTTP or session handling. Error texts are the legacy msgids.
 */
final class LoginService
{
    public const INVALID_CREDENTIALS = 'Invalid username or password';

    public const SUSPENDED = 'Account has been suspended';

    public const UNSUPPORTED_HASH = 'Unsupported hash method';

    public const INVALID_2FA = 'Invalid or missing 2FA token';

    /** web/login/index.php accepts only names matching this. */
    public const USERNAME_PATTERN = '/^[[:alnum:]][-|\.|_[:alnum:]]{0,28}[[:alnum:]]$/';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    public function attempt(string $username, string $password, LoginContext $context): LoginResult
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            // No command can log a name it refuses, so fail2ban hears of it here (M7).
            $this->arc->run('v-log-sys-login-failure', [$context->ip, 'Invalid user name'], Options::allowFailure());

            return $this->fail(self::INVALID_CREDENTIALS);
        }
        if ($password === '') {
            return $this->fail(self::INVALID_CREDENTIALS);
        }

        $error = $this->passwordError($username, $password, $context);
        if ($error !== null) {
            return $this->fail($error);
        }

        $user = $this->userRecord($username);
        if (($user['LOGIN_DISABLED'] ?? '') === 'yes') {
            $this->logFailure($username, $context, 'Login disabled for this user');

            return $this->fail(self::INVALID_CREDENTIALS);
        }

        if (($user['LOGIN_USE_IPLIST'] ?? '') === 'yes') {
            $allowed = array_map('trim', explode(',', $user['LOGIN_ALLOW_IPS'] ?? ''));
            if (! in_array($context->ip, $allowed, true)) {
                $this->logFailure($username, $context, 'IP address not in allowed list');

                return $this->fail(self::INVALID_CREDENTIALS);
            }
        }

        if (($user['TWOFA'] ?? '') !== '') {
            return LoginResult::secondFactor($username, $user);
        }

        return LoginResult::success($username, $user);
    }

    /**
     * Check a 2FA code for a user who already passed attempt(). The caller
     * limits the attempts (LoginThrottle); v-check-user-2fa writes the
     * auth.log line of a wrong code itself.
     */
    public function verifySecondFactor(string $username, string $code, LoginContext $context): LoginResult
    {
        if ($code === '' || strlen($code) >= 10) {
            return $this->fail(self::INVALID_2FA);
        }

        $check = $this->arc->run('v-check-user-2fa', [$username, $code, $context->ip], Options::allowFailure());
        if (! $check->ok()) {
            // Login history only: the auth.log line is already written.
            $this->logFailure($username, $context);

            return $this->fail(self::INVALID_2FA);
        }

        return LoginResult::success($username, $this->userRecord($username));
    }

    /**
     * Log the successful login and return what the legacy session must hold.
     *
     * @param  array<string, string>  $user
     * @return array<string, mixed> patch for LegacySession::establish()
     */
    public function complete(string $username, array $user, LoginContext $context): array
    {
        $this->arc->run(
            'v-log-user-login',
            [$username, $context->ip, 'success', $context->fingerprint, $context->userAgent],
            Options::allowFailure(),
        );

        $languages = $this->arc->json('v-list-sys-languages', [], Options::allowFailure());
        $language = in_array($user['LANGUAGE'] ?? '', $languages, true) ? $user['LANGUAGE'] : 'en';

        return [
            'user' => $username,
            'look' => '',
            'userContext' => $user['ROLE'] ?? Role::User->value,
            'LAST_ACTIVITY' => time(),
            // Compared with SESSIONS_VALID_AFTER, which a password change sets (M8)
            'LOGIN_TIME' => self::loginTime(),
            'user_combined_ip' => $context->ip,
            'token' => $context->fingerprint,
            'language' => $language,
            'userTheme' => $this->config->is('POLICY_USER_CHANGE_THEME') ? ($user['THEME'] ?? '') : null,
        ];
    }

    private function hash(string $username, string $password, string $salt, string $method, LoginContext $context): string
    {
        return match ($method) {
            'md5' => crypt($password, '$1$'.$salt.'$'),
            'sha-512' => str_replace('$rounds=5000', '', crypt($password, '$6$rounds=5000$'.$salt.'$')),
            'des' => crypt($password, $salt),
            'yescrypt' => $this->arc->run(
                'v-check-user-password',
                [$username, Secret::file($password), $context->ip, 'yes'],
                Options::allowFailure(),
            )->lines()[0] ?? '',
            default => '',
        };
    }

    /** @return array<string, string> */
    private function userRecord(string $username): array
    {
        $data = $this->arc->json('v-list-user', [$username]);

        return array_map('strval', $data[$username] ?? []);
    }

    private function logFailure(string $username, LoginContext $context, ?string $reason = null): void
    {
        $args = [$username, $context->ip, 'failed', $context->fingerprint, $context->userAgent];
        if ($reason !== null) {
            array_push($args, 'yes', $reason);
        }
        $this->arc->run('v-log-user-login', $args, Options::allowFailure());
    }

    /**
     * The LOGIN_TIME of a session: Unix time in milliseconds, the unit of
     * SESSIONS_VALID_AFTER (E39 W2 spec D10).
     */
    public static function loginTime(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Check the password of a logged-in user for sudo mode (E39 W2 spec §7):
     * the password steps of attempt() without the account checks. A wrong
     * password is logged like a failed login.
     */
    public function verifyPassword(string $username, string $password, LoginContext $context): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1
            && $password !== ''
            && $this->passwordError($username, $password, $context) === null;
    }

    /** Null when the password is right, else the message to show. */
    private function passwordError(string $username, string $password, LoginContext $context): ?string
    {
        $salt = $this->arc->run('v-get-user-salt', [$username, $context->ip, 'json'], Options::allowFailure());
        if (! $salt->ok()) {
            // Suspension is told only after the password matched (L6)
            return $salt->exitCode === 1 ? self::UNSUPPORTED_HASH : self::INVALID_CREDENTIALS;
        }
        $pam = json_decode($salt->stdout, true)[$username] ?? [];
        $hash = $this->hash($username, $password, (string) ($pam['SALT'] ?? ''), (string) ($pam['METHOD'] ?? ''), $context);

        $check = $this->arc->run(
            'v-check-user-hash',
            [$username, Secret::file($hash), $context->ip],
            Options::allowFailure(),
        );
        if (! $check->ok()) {
            $this->logFailure($username, $context);

            return $check->exitCode === 5 ? self::SUSPENDED : self::INVALID_CREDENTIALS;
        }

        return null;
    }

    /**
     * Whether the user restricts logins to an address list that holds $ip
     * (LoginThrottle skips the hourly user limit for it, E39 W2 D2).
     */
    public function allowListed(string $username, string $ip): bool
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return false;
        }
        $data = $this->arc->json('v-list-user', [$username], Options::allowFailure());
        $user = $data[$username] ?? [];
        if (! is_array($user) || ($user['LOGIN_USE_IPLIST'] ?? '') !== 'yes') {
            return false;
        }

        return in_array($ip, array_map('trim', explode(',', (string) ($user['LOGIN_ALLOW_IPS'] ?? ''))), true);
    }

    /** Guessing is slowed down by LoginThrottle before any work, not by a sleep in the worker (M7). */
    private function fail(string $error): LoginResult
    {
        return LoginResult::failed($error);
    }
}
