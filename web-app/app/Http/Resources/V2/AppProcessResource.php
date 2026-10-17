<?php

namespace App\Http\Resources\V2;

use App\Services\AppProcesses\AppProcess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One app process (`v-list-app-process`, E42 spec §8). */
final class AppProcessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AppProcess $process */
        $process = $this->resource;
        $parts = explode(' ', trim($process->created), 2);

        return [
            'domain' => $process->domain,
            'name' => $process->name,
            'kind' => $process->kind,
            'preset' => $process->preset,
            'start' => $process->start,
            'cwd' => $process->cwd,
            'port' => $process->port !== '' ? (int) $process->port : null,
            'memory_mb' => $process->memory,
            'cpus' => $process->cpus,
            'template' => $process->template !== '' ? $process->template : null,
            'state' => $process->state,
            'status' => $process->status,
            'running' => $process->running(),
            'suspended' => $process->suspended,
            'pid' => $process->pid !== '' ? $process->pid : null,
            'memory_used_mb' => $process->memoryUsed,
            'cpu_seconds' => $process->cpuSeconds,
            'restarts' => $process->restarts,
            'uptime_seconds' => $process->uptime,
            'reload' => $process->reload !== '' ? $process->reload : null,
            'runtime_missing' => $process->runtimeMissing !== '' ? $process->runtimeMissing : null,
            'created_at' => UserResource::localTime($parts[0] ?? '', $parts[1] ?? ''),
        ];
    }
}
