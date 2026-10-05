<?php

namespace App\Services\WebDomains;

use App\Services\Ports\PortAccount;

/**
 * Ports a web domain may use as backend port (E6 spec §5.5, §7.2): its
 * current port, the owner's manual ports, ports of the owner's apps for this
 * domain or a container, and free ports of the pool.
 */
final class BackendPorts
{
    /** Free ports listed one by one; more are reached with "auto". */
    public const FREE_LIMIT = 50;

    /** @return array<string, string> value => label, "auto" first */
    public static function choices(PortAccount $account, string $domain, string $current = ''): array
    {
        $choices = ['auto' => __('Assign automatically from my pool')];
        if ($current !== '') {
            $choices[$current] = sprintf(__('%s (current)'), $current);
        }
        $free = 0;
        foreach ($account->entries as $entry) {
            if ($entry->app === '') {
                for ($port = $entry->start; $port <= $entry->end && $free < self::FREE_LIMIT; $port++, $free++) {
                    $choices[(string) $port] ??= (string) $port;
                }

                continue;
            }
            if ($entry->start !== $entry->end || ! self::usable($entry->app, $domain)) {
                continue;
            }
            $label = $entry->comment !== '' ? "{$entry->start} · {$entry->app} · {$entry->comment}" : "{$entry->start} · {$entry->app}";
            $choices[(string) $entry->start] ??= $label;
        }

        return $choices;
    }

    /** Whether VALUE is "auto" or one of the choices. */
    public static function accepts(PortAccount $account, string $domain, string $current, string $value): bool
    {
        if ($value === 'auto' || ($current !== '' && $value === $current)) {
            return true;
        }
        if (! ctype_digit($value)) {
            return false;
        }
        $port = (int) $value;
        foreach ($account->entries as $entry) {
            if ($port >= $entry->start && $port <= $entry->end) {
                return $entry->app === '' || ($entry->start === $entry->end && self::usable($entry->app, $domain));
            }
        }

        return false;
    }

    /** True when the owner has no free port and its package cannot give more. */
    public static function poolExhausted(PortAccount $account): bool
    {
        return $account->free === 0 && $account->limit !== 'unlimited' && $account->allocated >= (int) $account->limit;
    }

    private static function usable(string $app, string $domain): bool
    {
        return $app === 'manual' || $app === "web:{$domain}" || $app === "node:{$domain}" || str_starts_with($app, 'docker:');
    }
}
