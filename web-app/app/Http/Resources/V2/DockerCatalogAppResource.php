<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Docker catalog manifest (`v-list-docker-catalog-app`). */
final class DockerCatalogAppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $app */
        $app = $this->resource;

        return [
            'slug' => (string) ($app['slug'] ?? ''),
            'name' => (string) ($app['name'] ?? ''),
            'summary' => (string) ($app['summary'] ?? ''),
            'description' => (string) ($app['description'] ?? '') ?: null,
            'category' => (string) ($app['category'] ?? 'other'),
            'version' => (string) ($app['version'] ?? ''),
            'versions' => array_values(array_map('strval', (array) ($app['versions'] ?? []))),
            'latest_version' => (string) ($app['latest'] ?? ''),
            'homepage' => (string) ($app['homepage'] ?? '') ?: null,
            'changelog' => (string) ($app['changelog'] ?? '') ?: null,
            'license' => (string) ($app['license'] ?? '') ?: null,
            'admin_only' => (bool) ($app['adminOnly'] ?? false),
            'web' => (bool) ($app['web'] ?? false),
            'requirements' => $app['requirements'] ?? [],
            'limits' => $app['limits'] ?? [],
            'ports' => $app['ports'] ?? [],
            'variables' => $app['variables'] ?? [],
            'dumps' => (bool) ($app['dumps'] ?? false),
            'icon' => (string) ($app['icon'] ?? '') ?: null,
        ];
    }
}
