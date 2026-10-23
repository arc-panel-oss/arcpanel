<?php

namespace App\Services\Packages;

/**
 * Limits of reseller packages (E39 W4 spec §8.2, §8.3): a value in one base
 * unit per key, compared with the reseller's own. func/tenancy.sh
 * (tenancy_limit_value, tenancy_pkg_tenancy_above) follows the same table;
 * tests/Fixtures/package-limits.tsv holds the cases both suites run.
 */
final class PackageLimits
{
    /** Sidecar keys that are comma lists: * allows anything. */
    public const LISTS = ['NODE_ALLOWED', 'PHP_VERSIONS', 'PHP_PROFILES'];

    /** Unit => [power of 1024, digits that keep the bytes below 2^63]. */
    private const UNITS = ['' => [0, 18], 'K' => [1, 15], 'M' => [2, 12], 'G' => [3, 9], 'T' => [4, 6]];

    /**
     * Bytes for MEMORY_LIMIT, SWAP_LIMIT, PHP_MAX_MEMORY and PHP_MAX_UPLOAD
     * (K, M, G, T are powers of 1024),
     * the same for DOCKER_MEMORY with a plain number in MB, hundredths of a
     * CPU for DOCKER_CPUS, percent for CPU_QUOTA, milliseconds for
     * CPU_QUOTA_PERIOD, 1 and 0 for yes and no, the plain integer otherwise.
     *
     * @return int|'unlimited'|null null when the value does not parse
     */
    public static function normalize(string $key, string $value): int|string|null
    {
        if ($value === 'unlimited') {
            return 'unlimited';
        }

        return match ($key) {
            'MEMORY_LIMIT', 'SWAP_LIMIT', 'DOCKER_MEMORY', 'APP_PROCESSES_MEMORY', 'PHP_MAX_MEMORY', 'PHP_MAX_UPLOAD' => self::bytes($key, $value),
            'DOCKER_CPUS', 'APP_PROCESSES_CPUS' => preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D', $value, $m) === 1
                ? (int) $m[1] * 100 + (int) substr(($m[2] ?? '').'00', 0, 2) : null,
            // MB, or GB and TB with G and T (E44), as func/tenancy.sh reads it
            'OBJECT_STORAGE_QUOTA' => preg_match('/^(\d{1,9})([MGT]?)$/D', $value, $m) === 1
                ? (int) $m[1] * ['' => 1, 'M' => 1, 'G' => 1024, 'T' => 1048576][$m[2]] : null,
            'CPU_QUOTA' => preg_match('/^(\d{1,9})%$/D', $value, $m) === 1 ? (int) $m[1] : null,
            'CPU_QUOTA_PERIOD' => preg_match('/^(\d{1,9})(ms|s)$/D', $value, $m) === 1 ? (int) $m[1] * ($m[2] === 's' ? 1000 : 1) : null,
            // More often ranks higher (func/tenancy.sh)
            'BACKUP_FREQUENCY' => ['weekly' => 1, 'daily' => 2, 'hourly' => 3][$value] ?? null,
            'BACKUPS_INCREMENTAL', 'NODE', 'DOCKER', 'APP_PROCESSES', 'OBJECT_STORAGE', 'OBJECT_STORAGE_BACKUP' => match ($value) {
                'yes' => 1,
                'no' => 0,
                default => null,
            },
            default => preg_match('/^\d{1,18}$/D', $value) === 1 ? (int) $value : null,
        };
    }

    /** Whether $value goes beyond $limit: unlimited is the largest value, and what does not parse counts as beyond. */
    public static function above(string $key, string $value, string $limit): bool
    {
        if (in_array($key, self::LISTS, true)) {
            return self::listAbove($key, $value, $limit);
        }
        $value = self::normalize($key, $value);
        $limit = self::normalize($key, $limit);
        if ($value === null || $limit === null) {
            return true;
        }
        if ($limit === 'unlimited') {
            return false;
        }

        return $value === 'unlimited' || $value > $limit;
    }

    /** A list allows something the limit does not; for NODE_ALLOWED, lts is within lts and * only. */
    private static function listAbove(string $key, string $value, string $limit): bool
    {
        if ($limit === '*') {
            return false;
        }
        if ($value === '*') {
            return true;
        }
        if ($key === 'NODE_ALLOWED' && ($value === 'lts' || $limit === 'lts')) {
            return $value !== $limit;
        }

        return array_diff(explode(',', $value), explode(',', $limit)) !== [];
    }

    private static function bytes(string $key, string $value): ?int
    {
        if (preg_match('/^(\d+)([KMGT]?)$/D', $value, $m) !== 1) {
            return null;
        }
        $unit = $m[2] === '' && ($key === 'DOCKER_MEMORY' || $key === 'APP_PROCESSES_MEMORY') ? 'M' : $m[2];
        [$power, $digits] = self::UNITS[$unit];

        return strlen($m[1]) <= $digits ? (int) $m[1] * 1024 ** $power : null;
    }
}
