<?php

namespace App\Http\Resources\V2;

use App\Api\TaskId;
use App\Services\Backups\BackupRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run of a tar or restic backup, failed ones included (Fleet PD2).
 *
 * @property BackupRun $resource
 */
final class BackupRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        $finished = $run->status !== 'running';
        $time = self::stamp(...);

        return [
            /** @var string 14 digits, the start time in seconds and four random digits */
            'id' => $run->id,
            /** @var string the owner */
            'user' => $run->user,
            /** @var 'tar'|'restic' */
            'engine' => $run->engine,
            /** @var 'schedule'|'request'|'cli' `schedule` for the nightly backup, `request` for a queued request of the panel or the API, `cli` for anything else */
            'trigger' => $run->trigger,
            /** @var string|null the task of a requested run, for `getTask` while it is kept */
            'task' => $run->task !== '' ? (new TaskId(TaskId::SOURCE_QUEUE, $run->task, $run->user))->apiId() : null,
            /** @var 'running'|'succeeded'|'failed' */
            'status' => $run->status,
            /** @var string|null start time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'started_at' => $time($run->start),
            /** @var string|null set when the run has ended */
            'finished_at' => $finished ? $time($run->end) : null,
            /** @var int|null set when the run has ended; 0 is success */
            'exit_code' => $finished && ctype_digit($run->exit) ? (int) $run->exit : null,
            /** @var string|null the reason of a failure; `interrupted` when the process ended without finishing the record */
            'error' => $run->error !== '' ? $run->error : null,
            /** @var string|null the archive name (tar) or snapshot id (restic) of a successful run */
            'archive' => $run->archive !== '' ? $run->archive : null,
            /** @var int|null size of a tar archive in megabytes */
            'size_mb' => ctype_digit($run->size) ? (int) $run->size : null,
            /** @var string|null the backup job of the run (E48), for `getBackupJob` */
            'job' => $run->job !== '' ? $run->job : null,
            /** @var list<array{name: string, state: 'ok'|'failed'}> the destinations the run stored to and how each went (E48); empty before E48 */
            'destinations' => array_map(fn (string $name, string $state) => ['name' => $name, 'state' => $state],
                array_keys($run->destinationStates()), array_values($run->destinationStates())),
        ];
    }

    /** "YYYY-MM-DD HH:MM:SS" of a record as YYYY-MM-DDTHH:MM:SS, or null. */
    public static function stamp(string $stamp): ?string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $stamp, $m) === 1 ? UserResource::localTime($m[1], $m[2]) : null;
    }
}
