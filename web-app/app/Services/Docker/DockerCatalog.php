<?php

namespace App\Services\Docker;

use App\Arc\ArcCommand;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The Docker app catalog shipped with ArcPanel (E4 spec §5.3, §6.1):
 * `v-list-docker-catalog all json` cached for 10 minutes (it changes with a
 * panel upgrade only) and `v-list-docker-catalog-app SLUG VERSION json`.
 */
final class DockerCatalog
{
    private const CACHE_KEY = 'arc.docker-catalog';

    private const TTL = 600;

    /** Catalog slugs (func/docker.sh docker_slug_valid) */
    public const SLUG = '/^[a-z][a-z0-9-]{1,31}$/';

    /** Version folders (docker_version_valid) */
    public const VERSION = '/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/';

    /** @var list<array<string, mixed>>|null */
    private ?array $apps = null;

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly Cache $cache,
    ) {}

    /**
     * Latest version of every app, for the store.
     *
     * @return list<array<string, mixed>>
     */
    public function apps(): array
    {
        return $this->apps ??= $this->cache->remember(self::CACHE_KEY, self::TTL, function (): array {
            $apps = [];
            foreach ((array) $this->arc->json('v-list-docker-catalog', ['all']) as $row) {
                if (is_array($row) && is_string($row['SLUG'] ?? null)) {
                    $apps[] = self::summary($row);
                }
            }

            return $apps;
        });
    }

    /** @return array<string, mixed>|null the store entry of $slug */
    public function find(string $slug): ?array
    {
        foreach ($this->apps() as $app) {
            if ($app['slug'] === $slug) {
                return $app;
            }
        }

        return null;
    }

    /**
     * The manifest of $slug (latest version without $version) for the
     * detail page and its install form.
     *
     * @return array<string, mixed>
     */
    public function app(string $slug, string $version = ''): array
    {
        $data = $this->arc->json('v-list-docker-catalog-app', [$slug, $version === '' ? 'latest' : $version]);
        $variables = [];
        foreach ((array) ($data['variables'] ?? []) as $variable) {
            if (is_array($variable) && is_string($variable['name'] ?? null)) {
                $variables[] = [
                    'name' => $variable['name'],
                    'label' => (string) ($variable['label'] ?? $variable['name']),
                    'type' => (string) ($variable['type'] ?? 'string'),
                    'options' => array_values(array_map('strval', (array) ($variable['options'] ?? []))),
                    'default' => (string) ($variable['default'] ?? ''),
                    'required' => (bool) ($variable['required'] ?? false),
                    'immutable' => (bool) ($variable['immutable'] ?? false),
                    'secret' => (bool) ($variable['secret'] ?? false),
                    'random' => (bool) ($variable['random'] ?? false),
                    'pattern' => (string) ($variable['pattern'] ?? ''),
                ];
            }
        }
        $ports = [];
        foreach ((array) ($data['ports'] ?? []) as $name => $port) {
            if (is_string($name) && is_array($port)) {
                $ports[] = ['name' => $name, 'container' => (string) ($port['container'] ?? ''), 'description' => (string) ($port['description'] ?? '')];
            }
        }

        return [
            'slug' => (string) ($data['slug'] ?? $slug),
            'name' => (string) ($data['name'] ?? $slug),
            'summary' => (string) ($data['summary'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'category' => (string) ($data['category'] ?? 'other'),
            'version' => (string) ($data['version'] ?? ''),
            'versions' => array_values(array_map('strval', (array) ($data['versions'] ?? []))),
            'latest' => (string) ($data['latest'] ?? ''),
            'homepage' => (string) ($data['homepage'] ?? ''),
            'changelog' => (string) ($data['changelog'] ?? ''),
            'license' => (string) ($data['license'] ?? ''),
            'adminOnly' => (bool) ($data['admin_only'] ?? false),
            'icon' => (string) ($data['icon'] ?? ''),
            'requirements' => [
                'memory' => (string) ($data['requirements']['memory'] ?? ''),
                'disk' => (string) ($data['requirements']['disk'] ?? ''),
            ],
            'limits' => [
                'memory' => (string) ($data['limits']['memory'] ?? ''),
                'cpus' => (string) ($data['limits']['cpus'] ?? ''),
            ],
            'web' => isset($data['ports']['web']),
            'ports' => $ports,
            'variables' => $variables,
            'dumps' => count((array) ($data['dumps'] ?? [])) > 0,
        ];
    }

    public function forget(): void
    {
        $this->apps = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $row  a row of v-list-docker-catalog
     * @return array<string, mixed>
     */
    private static function summary(array $row): array
    {
        return [
            'slug' => (string) $row['SLUG'],
            'name' => (string) ($row['NAME'] ?? $row['SLUG']),
            'summary' => (string) ($row['SUMMARY'] ?? ''),
            'category' => (string) ($row['CATEGORY'] ?? 'other'),
            'version' => (string) ($row['VERSION'] ?? ''),
            'versions' => array_values(array_map('strval', (array) ($row['VERSIONS'] ?? []))),
            'adminOnly' => (bool) ($row['ADMIN_ONLY'] ?? false),
            'web' => (bool) ($row['WEB'] ?? false),
            'homepage' => (string) ($row['HOMEPAGE'] ?? ''),
            'license' => (string) ($row['LICENSE'] ?? ''),
            'requirements' => [
                'memory' => (string) ($row['REQUIREMENTS']['memory'] ?? ''),
                'disk' => (string) ($row['REQUIREMENTS']['disk'] ?? ''),
            ],
            'icon' => (string) ($row['ICON'] ?? ''),
        ];
    }
}
