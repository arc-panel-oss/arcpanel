<?php

namespace App\Auth;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Services\Mail\PasswordResetMailer;
use Illuminate\Support\Str;

/**
 * Password reset by e-mail and 2FA reset by recovery code (web/reset,
 * web/reset2fa), E7 batch 3 spec §11. The answers never tell whether a user
 * exists; the reset code is single use and lives 15 minutes.
 */
final class PasswordReset
{
    /** Seconds a code is valid, and the minimum time between two requests (legacy 900). */
    public const LIFETIME = 900;

    private const USER_FORMAT = '/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly PasswordResetMailer $mailer,
    ) {}

    /**
     * Send a reset code when the user exists, is active, the e-mail is its
     * contact address and no code was sent in the last 15 minutes. Does
     * nothing otherwise; the caller shows the same answer in every case.
     */
    public function request(string $username, string $email, string $host): void
    {
        $user = $this->user($username);
        if ($user === null || ($user['SUSPENDED'] ?? '') === 'yes'
            || strcasecmp(trim($user['CONTACT'] ?? ''), trim($email)) !== 0
            || $this->issuedAt($username) > time() - self::LIFETIME) {
            return;
        }

        $code = Str::random(12);
        $result = $this->arc->run('v-change-user-rkey', [$username, Secret::file(password_hash($code, PASSWORD_DEFAULT))], Options::allowFailure());
        if ($result->ok()) {
            $this->mailer->sendPasswordReset($user['CONTACT'], $user['NAME'] ?? '', $username, $code, $user['LANGUAGE'] ?? '', $host);
        }
    }

    /** 'ok', 'expired' or 'invalid' (unknown user or wrong code). */
    public function check(string $username, string $code): string
    {
        $user = $this->user($username);
        $hash = $user['RKEY'] ?? '';
        if ($user === null || $hash === '' || $code === '' || ! password_verify($code, $hash)) {
            return 'invalid';
        }

        return $this->issuedAt($username) > time() - self::LIFETIME ? 'ok' : 'expired';
    }

    /**
     * Set the new password, then replace the code so it cannot be used again
     * (legacy kept it until it expired).
     */
    public function reset(string $username, string $password): bool
    {
        $result = $this->arc->run('v-change-user-password', [$username, Secret::file($password)], Options::allowFailure());
        if (! $result->ok()) {
            return false;
        }
        $this->arc->run('v-change-user-rkey', [$username], Options::allowFailure());

        return true;
    }

    /** Turn 2FA off when the code is the user's 2FA reset code. */
    public function resetTwoFactor(string $username, string $code): bool
    {
        $user = $this->user($username);
        $secret = $user['TWOFA'] ?? '';
        if ($user === null || $secret === '' || $code === '' || ! hash_equals($secret, $code)) {
            return false;
        }

        return $this->arc->run('v-delete-user-2fa', [$username], Options::allowFailure())->ok();
    }

    /** `v-log-user-login USER IP failed FINGERPRINT AGENT yes REASON`, as legacy logs failed resets. */
    public function logFailure(string $username, LoginContext $context, string $reason): void
    {
        if (preg_match(self::USER_FORMAT, $username) === 1) {
            $this->arc->run('v-log-user-login', [$username, $context->ip, 'failed', $context->fingerprint, $context->userAgent, 'yes', $reason], Options::allowFailure());
        }
    }

    /** @return array<string, string>|null */
    private function user(string $username): ?array
    {
        if (preg_match(self::USER_FORMAT, $username) !== 1) {
            return null;
        }
        $data = $this->arc->json('v-list-user', [$username], Options::allowFailure());

        return isset($data[$username]) && is_array($data[$username]) ? array_map('strval', $data[$username]) : null;
    }

    /** RKEYEXP: when the last code was issued (Unix time), 0 without one. */
    private function issuedAt(string $username): int
    {
        $value = trim($this->arc->run('v-get-user-value', [$username, 'RKEYEXP'], Options::allowFailure())->stdout);

        return ctype_digit($value) ? (int) $value : 0;
    }
}
