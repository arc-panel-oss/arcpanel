<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\Paginates;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\BackupJobRunResource;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJobRun;
use App\Services\Backups\BackupRunService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Runs of the backup jobs for administrators (E48 spec §5.3, §9): Server →
 * Backups → Activity as JSON. The result of each user of a run is in
 * `listAllBackupRuns` (its `job`).
 *
 * @tags Backups
 */
final class BackupJobRunController extends Controller
{
    public function __construct(
        private readonly BackupRunService $runs,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the runs of the backup jobs
     *
     * Newest first; `job` keeps the runs of one job. Each job keeps its
     * last 50 runs.
     *
     * @operationId listBackupJobRuns
     */
    public function index(Request $request, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewHub($principal), 403);
        $data = $request->validate([
            /** A job ID. */
            'job' => ['nullable', 'string', 'regex:/^\d{8,20}$/'],
            /** `running`, `success`, `partial` or `failed`. */
            'status' => ['nullable', 'string', 'in:running,success,partial,failed'],
        ]);
        $runs = $this->runs->runs((string) ($data['job'] ?? ''));
        if (($data['status'] ?? '') !== '') {
            $runs = array_values(array_filter($runs, fn (BackupJobRun $run) => $run->status === $data['status']));
        }
        $page = $this->pages->slice($request, $runs);

        return BackupJobRunResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Get a run of a backup job
     *
     * @operationId getBackupJobRun
     */
    public function show(string $run, Principal $principal, BackupPolicy $policy): BackupJobRunResource
    {
        abort_unless($policy->viewHub($principal), 403);
        foreach ($this->runs->runs() as $row) {
            if ($row->run === $run) {
                return new BackupJobRunResource($row);
            }
        }
        abort(404);
    }
}
