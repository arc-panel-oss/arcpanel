<?php

namespace App\Http\Resources\V2;

use App\Services\Node\NodeApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Node.js app (`v-list-node-app`). */
final class NodeAppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NodeApp $app */
        $app = $this->resource;

        return [
            'domain' => $app->domain,
            'name' => $app->name,
            'start' => $app->start,
            'script' => $app->script(),
            'cwd' => $app->cwd,
            'node_version' => $app->nodeVersion,
            'runtime' => $app->runtime,
            'port' => $app->port,
            'instances' => $app->instances,
            'max_memory_mb' => $app->maxMemory,
            'template' => $app->template,
            'state' => $app->state,
            'status' => $app->status,
            'online' => $app->online(),
            'reason' => $app->reason !== '' ? $app->reason : null,
            'pid' => $app->pid !== '' ? $app->pid : null,
            'cpu' => $app->cpu,
            'memory_mb' => $app->memoryMb,
            'restarts' => $app->restarts,
            'uptime_seconds' => $app->uptime,
            'url' => $app->url,
            'ssl' => $app->ssl,
            'suspended' => $app->isSuspended(),
            'created_at' => UserResource::localTime(...self::createdParts($app->created)),
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function createdParts(string $created): array
    {
        $parts = explode(' ', trim($created), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
