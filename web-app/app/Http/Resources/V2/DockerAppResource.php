<?php

namespace App\Http\Resources\V2;

use App\Services\Docker\DockerApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Docker app (`v-list-docker-app` or a row of `v-list-docker-apps`). */
final class DockerAppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DockerApp $app */
        $app = $this->resource;
        $detail = $this->includeDetail($request);

        $data = [
            'name' => $app->name,
            'slug' => $app->slug,
            'app_name' => $app->appName,
            'version' => $app->version,
            'latest_version' => $app->latest,
            'update_available' => $app->update,
            'domain' => $app->domain !== '' ? $app->domain : null,
            'url' => $app->url !== '' ? $app->url : null,
            'ssl' => $app->ssl,
            'web_port' => $app->webPort !== '' ? $app->webPort : null,
            'extra_ports' => $app->extraPorts !== '' ? $app->extraPorts : null,
            'state' => $app->state,
            'status' => $app->status,
            'reason' => $app->reason !== '' ? $app->reason : null,
            'suspended' => $app->isSuspended(),
            'busy' => $app->busy(),
            'previous_version' => $app->prevVersion !== '' ? $app->prevVersion : null,
            'cpus' => $app->cpus,
            'memory_mb' => $app->memory,
            'cpu' => $app->cpu,
            'memory_used_mb' => $app->memoryMb,
            'containers' => $app->containers,
            'running_containers' => $app->running,
            'created_at' => UserResource::localTime(...self::createdParts($app->created)),
        ];

        if ($detail) {
            $data['services'] = $app->services;
            $data['ports'] = array_map(fn (array $port) => [
                'name' => $port['name'],
                'port' => $port['port'],
                'container' => $port['container'],
                'public' => $port['public'],
                'description' => $port['description'],
            ], $app->ports);
            $data['post_install'] = $app->postInstall !== '' ? $app->postInstall : null;
            $data['rollback'] = $app->rollback;
            $data['last_dump'] = $app->lastDump !== '' ? $app->lastDump : null;
        }

        return $data;
    }

    private function includeDetail(Request $request): bool
    {
        $route = $request->route()?->getName() ?? '';

        return str_ends_with($route, 'docker-apps.show');
    }

    /** @return array{0: string, 1: string} */
    private static function createdParts(string $created): array
    {
        $parts = explode(' ', trim($created), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
