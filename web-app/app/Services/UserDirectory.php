<?php

namespace App\Services;

use App\Arc\ArcCommand;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * All users of the server (`v-list-users json`), cached for a minute: the
 * user switcher, the owner filter and the admin dashboard read it.
 */
final class UserDirectory
{
    private const CACHE_KEY = 'arc.users';

    private const TTL = 60;

    /** @var array<string, array<string, string>>|null */
    private ?array $users = null;

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly Cache $cache,
    ) {}

    /** @return array<string, array<string, string>> name => record */
    public function all(): array
    {
        return $this->users ??= $this->cache->remember(
            self::CACHE_KEY,
            self::TTL,
            function (): array {
                $users = [];
                foreach ($this->arc->json('v-list-users') as $name => $record) {
                    if (is_string($name) && is_array($record)) {
                        // RKEY (password reset key) never goes into the cache.
                        unset($record['RKEY']);
                        $users[$name] = array_map('strval', array_filter($record, 'is_scalar'));
                    }
                }
                ksort($users);

                return $users;
            },
        );
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function forget(): void
    {
        $this->users = null;
        $this->cache->forget(self::CACHE_KEY);
    }
}
