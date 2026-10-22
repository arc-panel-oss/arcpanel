<?php

namespace App\Http\Resources\V2;

use App\Services\Backups\BackupJobRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run of a backup job (E48 spec §5.3): when it ran and how many users it
 * backed up. The result of each user is in `listAllBackupRuns`, by `job`.
 *
 * @property BackupJobRun $resource
 */
final class BackupJobRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            /** @var string 14 digits, the start time in seconds and four random digits */
            'id' => $run->run,
            /** @var string the job, for `getBackupJob` */
            'job' => $run->job,
            /** @var string the job's name at the time of the run */
            'job_name' => $run->jobName,
            /** @var 'schedule'|'request'|'cli' `request` for Run now in the panel or `runBackupJob`, `cli` for a run started on the server */
            'trigger' => $run->trigger,
            /** @var 'running'|'success'|'partial'|'failed' `partial` when some users or destinations failed */
            'status' => $run->status,
            /** @var string|null */
            'started_at' => BackupRunResource::stamp($run->start),
            /** @var string|null set when the run has ended */
            'finished_at' => BackupRunResource::stamp($run->end),
            /** @var int the users the run covers */
            'users' => $run->users,
            /** @var int */
            'done' => $run->done,
            /** @var int */
            'failed' => $run->failed,
            /** @var int users skipped, for example suspended ones or those over the package's backup frequency */
            'skipped' => $run->skipped,
        ];
    }
}
