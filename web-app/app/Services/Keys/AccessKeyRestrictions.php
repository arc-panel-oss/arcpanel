<?php

namespace App\Services\Keys;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;

/**
 * The address list and the expiry of an access key (E43 B6, spec §9), with
 * the rules of `v-add-access-key` (`is_access_key_ips_format_valid`,
 * `is_access_key_expiry_valid` of func/main.sh), so that a refused value is
 * answered next to its field before any command runs.
 */
final class AccessKeyRestrictions
{
    /** Most addresses and networks of one key. */
    public const MAX_IPS = 20;

    /** Days before the expiry from which the pages mark a key. */
    public const EXPIRES_SOON_DAYS = 14;

    /** An ISO 8601 date and time; without an offset it is UTC (the form's datetime-local field). */
    public const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /** Whether $value is an IPv4 or IPv6 address or CIDR network. */
    public static function isAddressOrNetwork(string $value): bool
    {
        $parts = explode('/', $value);
        $ip = filter_var($parts[0], FILTER_VALIDATE_IP);
        if (count($parts) > 2 || $ip === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }

        return ctype_digit($parts[1]) && (int) $parts[1] <= (str_contains($ip, ':') ? 128 : 32);
    }

    /**
     * Whether every address and network of $inner lies inside a network of
     * $outer, so that a key limited to $outer gives no wider access (E39 W5
     * N27). IPv4 and IPv6 never contain each other.
     *
     * @param  list<string>  $inner
     * @param  list<string>  $outer
     */
    public static function within(array $inner, array $outer): bool
    {
        foreach ($inner as $entry) {
            $network = self::network($entry);
            $inside = false;
            foreach ($outer as $candidate) {
                $around = self::network($candidate);
                if ($network !== null && $around !== null && strlen($network[0]) === strlen($around[0]) && $network[1] >= $around[1]
                    && self::prefix($network[0], $around[1]) === self::prefix($around[0], $around[1])) {
                    $inside = true;
                    break;
                }
            }
            if (! $inside) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: string, 1: int}|null the packed address and the prefix length */
    private static function network(string $value): ?array
    {
        if (! self::isAddressOrNetwork($value)) {
            return null;
        }
        $parts = explode('/', $value);
        $packed = inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }

        return [$packed, isset($parts[1]) ? (int) $parts[1] : strlen($packed) * 8];
    }

    /** The first $bits bits of a packed address, the rest of the last byte cleared. */
    private static function prefix(string $packed, int $bits): string
    {
        $bytes = intdiv($bits, 8);
        $prefix = substr($packed, 0, $bytes);
        if ($bits % 8 > 0) {
            $prefix .= chr(ord($packed[$bytes]) & (0xFF << (8 - $bits % 8)) & 0xFF);
        }

        return $prefix;
    }

    /** The validation rule of one entry of the list. */
    public static function addressRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isAddressOrNetwork($value)) {
                $fail(__('Use an IPv4 or IPv6 address or a network such as 203.0.113.0/24.'));
            }
        };
    }

    /** The validation rule of the expiry: at least an hour and at most three years ahead. */
    public static function expiryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $time = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
            if ($time === null && is_string($value) && preg_match(self::ISO_8601, $value) === 1) {
                try {
                    $time = CarbonImmutable::parse($value, 'UTC');
                } catch (\Throwable) {
                    $time = null;
                }
            }
            if ($time === null) {
                $fail(__('Use a date and time.'));

                return;
            }
            if ($time->lessThan(CarbonImmutable::now()->addHour()) || $time->greaterThan(CarbonImmutable::now()->addYears(3))) {
                $fail(__('Choose a time at least an hour and at most three years ahead.'));
            }
        };
    }

    /**
     * The list as `v-add-access-key` takes it: entries trimmed, empty ones
     * dropped, in the order given.
     *
     * @param  iterable<mixed>|string  $value  a list, or text with one entry per line or comma
     * @return list<string>
     */
    public static function list(iterable|string $value): array
    {
        $entries = is_string($value) ? preg_split('/[\s,]+/', $value) : $value;
        $list = [];
        foreach ($entries ?: [] as $entry) {
            $entry = trim((string) $entry);
            if ($entry !== '') {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /** The Unix time of an ISO 8601 time (UTC without an offset); null when empty. */
    public static function timestamp(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->getTimestamp();
    }

    /** The answer form of an expiry: UTC, `YYYY-MM-DDTHH:MM:SSZ`. */
    public static function iso(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : CarbonImmutable::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
