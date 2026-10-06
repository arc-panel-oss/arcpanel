<?php

namespace App\Arc;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Server configuration (arcpanel.conf) as returned by `v-list-sys-config json`,
 * cached for config('arc.sys_config_ttl') seconds.
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
        return $this->values ??= $this->cache->remember(
            self::CACHE_KEY,
            $this->ttl,
            fn (): array => array_map('strval', $this->arc->json('v-list-sys-config')['config'] ?? []),
        );
    }

    public function forget(): void
    {
        $this->values = null;
        $this->cache->forget(self::CACHE_KEY);
    }
}
