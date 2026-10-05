<?php

namespace App\Services\Docker;

/**
 * The Docker apps of a user with the package limit (`v-list-docker-apps USER
 * json`, E4 spec §5.5).
 */
final class DockerApps
{
    /** @param  list<DockerApp>  $apps */
    public function __construct(
        public readonly string $user,
        public readonly bool $engine,
        public readonly bool $allowed,
        public readonly string $limit,
        public readonly array $apps,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $apps = [];
        foreach ((array) ($data['APPS'] ?? []) as $row) {
            if (is_array($row)) {
                $apps[] = DockerApp::from($row);
            }
        }

        return new self(
            user: (string) ($data['USER'] ?? ''),
            engine: ($data['SYSTEM'] ?? '') === 'yes',
            allowed: ($data['DOCKER'] ?? '') === 'yes',
            limit: (string) ($data['LIMIT'] ?? '0'),
            apps: $apps,
        );
    }

    /** Whether another app fits in DOCKER_APPS. */
    public function hasRoom(): bool
    {
        return $this->limit === 'unlimited' || count($this->apps) < (int) $this->limit;
    }

    public function find(string $name): ?DockerApp
    {
        foreach ($this->apps as $app) {
            if ($app->name === $name) {
                return $app;
            }
        }

        return null;
    }

    /** Domains used by an app. @return list<string> */
    public function domains(): array
    {
        return array_values(array_filter(array_map(fn (DockerApp $app) => $app->domain, $this->apps)));
    }

    /**
     * Apps per state for the list header and the Dashboard card.
     *
     * @return array{running: int, stopped: int, failed: int, busy: int, suspended: int}
     */
    public function totals(): array
    {
        $totals = ['running' => 0, 'stopped' => 0, 'failed' => 0, 'busy' => 0, 'suspended' => 0];
        foreach ($this->apps as $app) {
            $key = match (true) {
                $app->isSuspended() => 'suspended',
                $app->busy() => 'busy',
                $app->running() => 'running',
                in_array($app->status, ['failed', 'degraded', 'restarting', 'exited', 'missing'], true) => 'failed',
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
            'engine' => $this->engine,
            'allowed' => $this->allowed,
            'limit' => $this->limit,
            'count' => count($this->apps),
            'hasRoom' => $this->hasRoom(),
            'totals' => $this->totals(),
            'apps' => array_map(fn (DockerApp $app) => $app->toArray(), $this->apps),
        ];
    }
}
