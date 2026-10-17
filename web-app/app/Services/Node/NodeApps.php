<?php

namespace App\Services\Node;

/**
 * The Node.js apps of a user with the package limits
 * (`v-list-node-apps USER json`, E14 spec §5.4).
 */
final class NodeApps
{
    /** @param  list<NodeApp>  $apps */
    public function __construct(
        public readonly string $user,
        public readonly bool $allowed,
        public readonly string $limit,
        public readonly bool $daemon,
        public readonly string $pm2Runtime,
        public readonly int $maxInstances,
        public readonly string $memoryLimit,
        public readonly array $apps,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $apps = [];
        foreach ((array) ($data['APPS'] ?? []) as $row) {
            if (is_array($row)) {
                $apps[] = NodeApp::from($row, (string) ($data['USER'] ?? ''));
            }
        }

        return new self(
            user: (string) ($data['USER'] ?? ''),
            allowed: ($data['NODE'] ?? '') === 'yes',
            limit: (string) ($data['LIMIT'] ?? '0'),
            daemon: ($data['DAEMON'] ?? '') === 'yes',
            pm2Runtime: (string) ($data['PM2_RUNTIME'] ?? ''),
            maxInstances: max(1, (int) ($data['MAX_INSTANCES'] ?? 1)),
            memoryLimit: (string) ($data['MEMORY_LIMIT'] ?? ''),
            apps: $apps,
        );
    }

    /** Whether another app fits in NODE_APPS. */
    public function hasRoom(): bool
    {
        return $this->limit === 'unlimited' || count($this->apps) < (int) $this->limit;
    }

    public function find(string $domain): ?NodeApp
    {
        foreach ($this->apps as $app) {
            if ($app->domain === $domain) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Apps per state for the list header and the Dashboard card.
     *
     * @return array{online: int, stopped: int, errored: int, suspended: int}
     */
    public function totals(): array
    {
        $totals = ['online' => 0, 'stopped' => 0, 'errored' => 0, 'suspended' => 0];
        foreach ($this->apps as $app) {
            $key = match (true) {
                $app->isSuspended() => 'suspended',
                $app->online() => 'online',
                $app->status === 'errored' => 'errored',
                default => 'stopped',
            };
            $totals[$key]++;
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'allowed' => $this->allowed,
            'limit' => $this->limit,
            'count' => count($this->apps),
            'hasRoom' => $this->hasRoom(),
            'daemon' => $this->daemon,
            'pm2Runtime' => $this->pm2Runtime,
            'maxInstances' => $this->maxInstances,
            'memoryLimit' => $this->memoryLimit,
            'totals' => $this->totals(),
            'apps' => array_map(fn (NodeApp $app) => $app->toArray(), $this->apps),
        ];
    }
}
