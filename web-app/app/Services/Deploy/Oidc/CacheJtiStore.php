<?php

namespace App\Services\Deploy\Oidc;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * Keeps used `jti` values in the cache until the token's `exp` (at least one
 * second). `add()` is atomic on the stores that support it, so two uploads
 * with the same token cannot both pass.
 */
final class CacheJtiStore implements JtiStore
{
    private const PREFIX = 'deploy.oidc.jti.';

    /**
     * @param  (Closure(): int)|null  $clock  current Unix time, Carbon::now() by default
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly ?Closure $clock = null,
    ) {}

    public function remember(string $jti, int $exp): bool
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : Carbon::now()->getTimestamp();

        return $this->cache->add(self::PREFIX.hash('sha256', $jti), 1, max(1, $exp - $now));
    }
}
