<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJob;
use App\Services\Backups\JobService;
use Illuminate\Validation\Validator;

/**
 * PATCH /system/backup-jobs/{job} (E48 spec §9): fields left out keep their
 * value. Administrators only (403 before validation), an unknown job 404.
 */
final class UpdateBackupJobRequest extends ApiRequest
{
    private ?BackupJob $stored = null;

    public function authorize(): bool
    {
        return app(BackupPolicy::class)->manageJobs(app(Principal::class));
    }

    public function stored(): BackupJob
    {
        return $this->stored ??= app(JobService::class)->find((string) $this->route('job'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'regex:'.StoreBackupJobRequest::NAME],
            'engine' => ['sometimes', 'string', 'in:archive,snapshot'],
            'schedule' => ['sometimes', 'string', 'regex:'.StoreBackupJobRequest::SCHEDULE],
            ...StoreBackupJobRequest::scopeRules(partial: true),
            /** Pauses the job when false, resumes it when true. */
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => StoreBackupJobRequest::checkRetention($validator, (string) $this->merged()['engine'], (array) $this->merged()['targets'])];
    }

    /**
     * Arguments of v-change-backup-job: the stored job with the changes.
     *
     * @return array{name: string, engine: string, scope: string, schedule: string, targets: string, exclude: string}
     */
    public function values(): array
    {
        return StoreBackupJobRequest::valuesFrom($this->merged());
    }

    /**
     * The stored job in the shape of the body, with the fields the request sends.
     *
     * @return array<string, mixed>
     */
    private function merged(): array
    {
        $job = $this->stored();
        [$scope, $names] = str_contains($job->scope, ':') ? explode(':', $job->scope, 2) : [$job->scope, ''];
        $names = array_values(array_filter(explode(',', $names), fn ($v) => $v !== ''));
        $stored = [
            'name' => $job->name,
            'engine' => $job->engine,
            'schedule' => $job->schedule->value(),
            'scope' => $scope,
            'packages' => $scope === 'packages' ? $names : [],
            'users' => $scope === 'users' ? $names : [],
            'exclude_users' => $job->excluded,
            'targets' => array_map(fn (array $t) => ['destination' => $t['destination'], 'keep_last' => $t['keepLast'], 'daily' => $t['daily'],
                'weekly' => $t['weekly'], 'monthly' => $t['monthly'], 'yearly' => $t['yearly']], $job->targets),
        ];
        $given = array_intersect_key($this->all(), $stored);
        // A new scope replaces the names of the old one
        if (isset($given['scope'])) {
            $stored['packages'] = $stored['users'] = [];
        }

        return [...$stored, ...$given];
    }
}
