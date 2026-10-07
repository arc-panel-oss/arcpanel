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
            /** @var string */
            'id' => $task->id,
            /** @var string */
            'kind' => $task->kind,
            /** @var string */
            'status' => $task->status,
            /** @var int|null set when the task has finished */
            'exit_code' => $task->exitCode,
            /** @var string|null */
            'error' => $task->error,
            /** @var string|null */
            'started_at' => $task->startedAt,
            /** @var string|null */
            'finished_at' => $task->finishedAt,
            /** @var string|null */
            'queued_at' => $task->queuedAt,
            /** @var string */
            'log' => $task->log,
            /** @var string|null */
            'args' => $task->args,
            /** @var string|null path under /api/v2 for the related resource */
            'resource' => $task->resource,
        ];
    }
}
