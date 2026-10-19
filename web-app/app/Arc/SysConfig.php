<?php

namespace App\Arc;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Server configuration (arcpanel.conf) as returned by `v-list-sys-config json`,
 * cached for config('arc.sys_config_ttl') seconds.
 *
 * Commands that write arcpanel.conf write a new stamp (sys_config_changed in
 * func/main.sh, config('arc.sys_config_stamp')). The cache entry keeps the
 * stamp it was read under, so the next request after a change reads the
 * config again instead of waiting for the TTL. Writers that do not stamp are
 * still seen once the TTL ends.
 */
final class SysConfig
{
    private const CACHE_KEY = 'arc.sys-config';

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly Cache $cache,
        private readonly int $ttl,
        private readonly string $stampFile = '',
    ) {}

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    public function is(string $key, string $value = 'yes'): bool
    {
        return $this->get($key) === $value;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        // Read the stamp before the config: a change in between is read again next time
        $stamp = $this->stamp();
        $entry = $this->cache->get(self::CACHE_KEY);
        if (is_array($entry) && ($entry['stamp'] ?? null) === $stamp && is_array($entry['values'] ?? null)) {
            return $this->values = $entry['values'];
        }

        $values = array_map('strval', $this->arc->json('v-list-sys-config')['config'] ?? []);
        $this->cache->put(self::CACHE_KEY, ['stamp' => $stamp, 'values' => $values], $this->ttl);

        return $this->values = $values;
    }

    public function forget(): void
    {
        $this->values = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** Content of the stamp file, '' when there is none. */
    private function stamp(): string
    {
        if ($this->stampFile === '' || ! is_file($this->stampFile)) {
            return '';
        }

        return trim((string) @file_get_contents($this->stampFile));
    }
}
