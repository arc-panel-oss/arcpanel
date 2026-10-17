<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A write of the request journal (`GET /requests/{id}`, E43 B4).
 *
 * @property array{id: string, operation: string, method: string, path: string, state: string, status: int|null, error_code: string|null, task_id: string|null, location: string|null, started_at: string, finished_at: string|null} $resource
 */
final class ApiRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $journaled = $this->resource;

        return [
            /** @var string the request id (`X-Request-Id`) */
            'id' => $journaled['id'],
            /** @var string the operationId */
            'operation' => $journaled['operation'],
            /** @var string */
            'method' => $journaled['method'],
            /** @var string the path as called, without the query */
            'path' => $journaled['path'],
            /** @var 'running'|'finished'|'unknown' `running` less than 15 minutes after the start without an end, `unknown` after that */
            'state' => $journaled['state'],
            /** @var int|null HTTP status of the answer, null unless finished */
            'status' => $journaled['status'],
            /** @var string|null error code of a failed answer */
            'error_code' => $journaled['error_code'],
            /** @var string|null the task of a 202 answer (`GET /tasks/{id}`) */
            'task_id' => $journaled['task_id'],
            /** @var string|null the Location of a 201 or 202 answer */
            'location' => $journaled['location'],
            /** @var string ISO 8601 (UTC) */
            'started_at' => $journaled['started_at'],
            /** @var string|null ISO 8601 (UTC), null unless finished */
            'finished_at' => $journaled['finished_at'],
        ];
    }
}
