<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreBackupJobRequest;
use App\Http\Requests\Api\V2\UpdateBackupJobRequest;
use App\Http\Resources\V2\BackupJobResource;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJob;
use App\Services\Backups\JobService;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Backup jobs of the server for administrators (E48 spec §5, §9): Server →
 * Backups → Schedules as JSON. The dispatcher runs each job on its
 * schedule; `runBackupJob` starts one now.
 *
 * @tags Backups
 */
final class BackupJobController extends Controller
{
    public function __construct(
        private readonly JobService $jobs,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the backup jobs
     *
     * Oldest first.
     *
     * @operationId listBackupJobs
     */
    public function index(Request $request, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewJobs($principal), 403);
        $page = $this->pages->slice($request, array_values($this->jobs->all()));

        return BackupJobResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add a backup job
     *
     * The destinations must exist, and the schedule must run at least once
     * a year. The job runs first at its next scheduled time.
     *
     * @operationId createBackupJob
     *
     * @status 201
     */
    public function store(StoreBackupJobRequest $request): BackupJobResource|JsonResponse
    {
        $values = $request->values();
        $enabled = $request->boolean('enabled', true);
        $step = new Step(sprintf(__('Add backup job %s'), $values['name']), 'v-add-backup-job', [$values['name'], $values['engine'], $values['scope'],
            $values['schedule'], $values['targets'], $values['exclude'], $enabled ? 'enabled' : 'paused']);
        $result = null;
        if ($preview = $this->gate->execute([$step], function (array $steps) use (&$result): ApplyResult {
            return $result = $this->runner->apply($steps);
        })) {
            return $preview;
        }
        $id = trim((string) ($result?->stdoutByCommand['v-add-backup-job'] ?? ''));

        return (new BackupJobResource($this->jobs->find($id)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.backup-jobs.show', ['job' => $id]));
    }

    /**
     * Get a backup job
     *
     * @operationId getBackupJob
     */
    public function show(string $job, Principal $principal, BackupPolicy $policy): BackupJobResource
    {
        abort_unless($policy->viewJobs($principal), 403);

        return new BackupJobResource($this->jobs->find($job));
    }

    /**
     * Update a backup job
     *
     * Fields left out keep their value; a new `scope` replaces the packages
     * or users of the old one. `enabled` pauses or resumes the job.
     *
     * @operationId updateBackupJob
     */
    public function update(UpdateBackupJobRequest $request, string $job): BackupJobResource|JsonResponse
    {
        $stored = $request->stored();
        $values = $request->values();
        $steps = [];
        $current = [$stored->name, $stored->engine, $stored->scope, $stored->schedule->value(), $stored->targets, implode(',', $stored->excluded)];
        $wanted = [$values['name'], $values['engine'], $values['scope'], $values['schedule'], BackupJob::parseTargets($values['targets']), $values['exclude']];
        if ($current !== $wanted) {
            $steps[] = new Step(sprintf(__('Change backup job %s'), $stored->name), 'v-change-backup-job', [$stored->id, $values['name'], $values['engine'],
                $values['scope'], $values['schedule'], $values['targets'], $values['exclude']]);
        }
        if ($request->has('enabled') && $request->boolean('enabled') !== $stored->enabled) {
            $steps[] = $request->boolean('enabled')
                ? new Step(sprintf(__('Resume backup job %s'), $stored->name), 'v-unsuspend-backup-job', [$stored->id])
                : new Step(sprintf(__('Pause backup job %s'), $stored->name), 'v-suspend-backup-job', [$stored->id]);
        }

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? new BackupJobResource($this->jobs->find($stored->id));
    }

    /**
     * Delete a backup job
     *
     * The backups it made are kept. A running job answers `409 E_INUSE`.
     *
     * @operationId deleteBackupJob
     *
     * @status 204
     */
    public function destroy(string $job, Principal $principal, BackupPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manageJobs($principal), 403);
        $record = $this->jobs->find($job);
        $step = new Step(sprintf(__('Delete backup job %s'), $record->name), 'v-delete-backup-job', [$record->id]);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? response()->noContent();
    }

    /**
     * Run a backup job now
     *
     * Starts a whole run in the background, paused jobs included, and
     * answers the job with `running: true`; `Content-Location` lists its runs, of
     * which the newest is this one. A running job answers `409 E_INUSE`.
     *
     * @operationId runBackupJob
     *
     * @status 202
     */
    public function run(string $job, Principal $principal, BackupPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageJobs($principal), 403);
        $record = $this->jobs->find($job);
        if ($record->running) {
            throw new ApiException(ErrorCode::InUse, sprintf(__('Backup job %s is already running.'), $record->name), 409);
        }
        $step = new Step(sprintf(__('Run backup job %s'), $record->name), 'v-start-backup-job', [$record->id]);
        if ($preview = $this->gate->execute([$step], $this->runner->apply(...))) {
            return $preview;
        }

        return (new BackupJobResource($this->jobs->find($record->id)))->response()->setStatusCode(202)
            ->header('Content-Location', route('api.v2.system.backup-job-runs.index', ['job' => $record->id]));
    }
}
