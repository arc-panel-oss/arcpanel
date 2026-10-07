<?php

namespace App\Http\Resources\V2;

use App\Services\Tasks\TaskView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Background task of API v2 (E34 batch 3 spec §5). */
final class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaskView $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'kind' => $task->kind,
            'status' => $task->status,
            'exit_code' => $task->exitCode,
            'error' => $task->error,
            'started_at' => $task->startedAt,
            'finished_at' => $task->finishedAt,
            'queued_at' => $task->queuedAt,
            'log' => $task->log,
            'args' => $task->args,
            /** @var string|null path under /api/v2 for the related resource */
            'resource' => $task->resource,
        ];
    }
}
