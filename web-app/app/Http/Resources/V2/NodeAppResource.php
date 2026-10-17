<?php

namespace App\Http\Resources\V2;

use App\Services\Node\NodeApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Node.js app (`v-list-node-app`), or its record in listAllNodeApps (E43 B2) without the live state of pm2. */
final class NodeAppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NodeApp $app */
        $app = $this->resource;

        $live = $app->live;

        return [
            'domain' => $app->domain,
            /** @var string the owner */
            'user' => $app->user,
            'name' => $app->name,
            'start' => $app->start,
            'script' => $app->script(),
            'cwd' => $app->cwd,
            'node_version' => $app->nodeVersion,
            /** @var string|null the installed version the app runs with; null in listAllNodeApps */
            'runtime' => $live ? $app->runtime : null,
            'port' => $app->port,
            'instances' => $app->instances,
            'max_memory_mb' => $app->maxMemory,
            'template' => $app->template,
            'state' => $app->state,
            /** @var string|null the live state of pm2; null in listAllNodeApps, which reads only the records */
            'status' => $live ? $app->status : null,
            /** @var bool|null */
            'online' => $live ? $app->online() : null,
            'reason' => $live && $app->reason !== '' ? $app->reason : null,
            'pid' => $live && $app->pid !== '' ? $app->pid : null,
            /** @var float|null */
            'cpu' => $live ? $app->cpu : null,
            /** @var int|null */
            'memory_mb' => $live ? $app->memoryMb : null,
            /** @var int|null */
            'restarts' => $live ? $app->restarts : null,
            /** @var int|null */
            'uptime_seconds' => $live ? $app->uptime : null,
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
