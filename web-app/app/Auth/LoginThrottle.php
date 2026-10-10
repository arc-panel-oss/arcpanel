<?php

namespace App\Auth;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Login and 2FA limits kept in the cache, outside the session, so a new login
 * does not reset them (E39 W2 spec §4.1, D1–D3). Every check and count runs
 * under a cache lock: the default store is `file`, where parallel requests
 * would otherwise all read the same count.
 */
final class LoginThrottle
{
    /** Attempts per user name and address in a minute. */
    public const PAIR_ATTEMPTS = 5;

    /** Attempts per address in a minute, whatever the user name. */
    public const IP_ATTEMPTS = 20;

    /** Failed attempts per user name in an hour. */
    public const USER_FAILURES = 20;

    /** Wrong 2FA codes per user in 15 minutes. */
    public const SECOND_FACTOR_FAILURES = 5;

    private const MINUTE = 60;

    private const HOUR = 3600;

    private const SECOND_FACTOR_DECAY = 900;

    /** Seconds a request waits for a lock before it is refused. */
    private const LOCK_WAIT = 2;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $cache,
    ) {}

    /**
     * Check the limits of a password attempt and count it on the address
     * keys. Returns the seconds to wait when a limit is reached, null when
     * the attempt may go ahead.
     *
     * @param  Closure(): bool  $allowListed  whether the client address is in
     *                                        the user's login allow list (D2); called only when the user limit is full
     */
    public function attempt(string $user, string $ip, Closure $allowListed): ?int
    {
        $pair = self::pairKey($user, $ip);
        $address = 'login:ip:'.$ip;
        $userKey = self::userKey($user);

        return $this->locked('ip:'.$ip, function () use ($pair, $address, $userKey, $allowListed): ?int {
            foreach ([$pair => self::PAIR_ATTEMPTS, $address => self::IP_ATTEMPTS] as $key => $max) {
                if ($this->limiter->tooManyAttempts($key, $max)) {
                    return max(1, $this->limiter->availableIn($key));
                }
            }
            if ($this->limiter->tooManyAttempts($userKey, self::USER_FAILURES) && ! $allowListed()) {
                return max(1, $this->limiter->availableIn($userKey));
            }
            $this->limiter->hit($pair, self::MINUTE);
            $this->limiter->hit($address, self::MINUTE);

            return null;
        });
    }

    /** Count a failed password attempt on the user name. */
    public function failed(string $user): void
    {
        $key = self::userKey($user);
        $this->locked('user:'.strtolower($user), fn () => $this->limiter->hit($key, self::HOUR));
    }

    /**
     * Check the 2FA limit and count the attempt before the code is checked,
     * so parallel guesses cannot pass it. A right code calls clearSecondFactor().
     * Returns the seconds to wait, or null.
     */
    public function secondFactor(string $user): ?int
    {
        $key = self::secondFactorKey($user);

        return $this->locked('2fa:'.strtolower($user), function () use ($key): ?int {
            if ($this->limiter->tooManyAttempts($key, self::SECOND_FACTOR_FAILURES)) {
                return max(1, $this->limiter->availableIn($key));
            }

            return null;
        });
    }

    /** Check and count one 2FA attempt in one step; null when it may go ahead. */
    public function attemptSecondFactor(string $user): ?int
    {
        $key = self::secondFactorKey($user);

        return $this->locked('2fa:'.strtolower($user), function () use ($key): ?int {
            if ($this->limiter->tooManyAttempts($key, self::SECOND_FACTOR_FAILURES)) {
                return max(1, $this->limiter->availableIn($key));
            }
            $this->limiter->hit($key, self::SECOND_FACTOR_DECAY);

            return null;
        });
    }

    public function clearSecondFactor(string $user): void
    {
        $this->limiter->clear(self::secondFactorKey($user));
    }

    /** Forget the user name's counters: after a password reset, or from v-delete-user-login-limits. */
    public function clear(string $user): void
    {
        $this->limiter->clear(self::userKey($user));
        $this->limiter->clear(self::secondFactorKey($user));
    }

    private static function pairKey(string $user, string $ip): string
    {
        return 'login:pair:'.$ip.'|'.strtolower($user);
    }

    private static function userKey(string $user): string
    {
        return 'login:user:'.strtolower($user);
    }

    private static function secondFactorKey(string $user): string
    {
        return 'login:2fa:'.strtolower($user);
    }

    /**
     * Run $work under a cache lock; a lock that cannot be taken in time
     * answers as a full limit rather than skipping the check.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T|int
     */
    private function locked(string $name, Closure $work): mixed
    {
        try {
            $store = $this->cache->getStore();
            assert($store instanceof LockProvider);

            return $store->lock('login-throttle:'.$name, 5)->block(self::LOCK_WAIT, $work);
        } catch (LockTimeoutException) {
            return self::MINUTE;
        }
    }
}
