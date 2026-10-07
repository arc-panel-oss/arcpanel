<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the Docker catalog (`v-list-docker-catalog`). */
final class DockerCatalogEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $app */
        $app = $this->resource;
        $requirements = (array) ($app['requirements'] ?? []);

        return [
            'slug' => (string) ($app['slug'] ?? ''),
            'name' => (string) ($app['name'] ?? ''),
            'summary' => (string) ($app['summary'] ?? ''),
            'category' => (string) ($app['category'] ?? 'other'),
            'version' => (string) ($app['version'] ?? ''),
            'versions' => array_values(array_map('strval', (array) ($app['versions'] ?? []))),
            'admin_only' => (bool) ($app['adminOnly'] ?? false),
            'web' => (bool) ($app['web'] ?? false),
            'homepage' => (string) ($app['homepage'] ?? '') ?: null,
            'license' => (string) ($app['license'] ?? '') ?: null,
            'requirements' => [
                'memory' => (string) ($requirements['memory'] ?? '') ?: null,
                'disk' => (string) ($requirements['disk'] ?? '') ?: null,
            ],
            'icon' => (string) ($app['icon'] ?? '') ?: null,
        ];
    }
}
