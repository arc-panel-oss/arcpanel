<?php

namespace App\Http\Resources\V2;

use App\Services\Backups\BackupJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A backup job (E48 spec §5): what it backs up, when, and to which
 * destinations with which retention.
 *
 * @property BackupJob $resource
 */
final class BackupJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $job = $this->resource;
        [$scope, $names] = str_contains($job->scope, ':') ? explode(':', $job->scope, 2) : [$job->scope, ''];
        $names = array_values(array_filter(explode(',', $names), fn ($v) => $v !== ''));
        $keep = fn (int $value): ?int => $value >= 0 ? $value : null;

        return [
            /** @var string the Unix time of its creation and two digits */
            'id' => $job->id,
            /** @var string */
            'name' => $job->name,
            /** @var 'archive'|'snapshot' tar archives or restic snapshots */
            'engine' => $job->engine,
            /** @var bool false while the job is paused */
            'enabled' => $job->enabled,
            /** @var string `hourly:MM`, `daily:HH:MM`, `weekly:D:HH:MM` (1 is Monday), `monthly:DD:HH:MM` or `cron:EXPRESSION`, in the server's time zone */
            'schedule' => $job->schedule->value(),
            /** @var string the schedule in words */
            'schedule_text' => $job->schedule->describe(),
            /** @var 'all'|'packages'|'users' */
            'scope' => $scope,
            /** @var list<string> the packages of a `packages` scope */
            'packages' => $scope === 'packages' ? $names : [],
            /** @var list<string> the users of a `users` scope */
            'users' => $scope === 'users' ? $names : [],
            /** @var list<string> users left out of an `all` or `packages` scope */
            'exclude_users' => $job->excluded,
            /** @var list<array{destination: string, keep_last: int|null, daily: int|null, weekly: int|null, monthly: int|null, yearly: int|null}> null keeps the package's number (archives) or sets no rule (snapshots) */
            'targets' => array_map(fn (array $t) => [
                'destination' => $t['destination'],
                'keep_last' => $keep($t['keepLast']),
                'daily' => $keep($t['daily']),
                'weekly' => $keep($t['weekly']),
                'monthly' => $keep($t['monthly']),
                'yearly' => $keep($t['yearly']),
            ], $job->targets),
            /** @var string|null the next scheduled run */
            'next_run_at' => BackupRunResource::stamp($job->nextRun),
            /** @var 'running'|'success'|'partial'|'failed'|null the state of the last run */
            'last_status' => $job->lastStatus !== '' ? $job->lastStatus : null,
            /** @var string|null */
            'last_finished_at' => BackupRunResource::stamp($job->lastEnd),
            /** @var bool */
            'running' => $job->running,
        ];
    }
}
