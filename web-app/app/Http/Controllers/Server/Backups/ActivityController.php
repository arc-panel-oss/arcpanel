<?php

namespace App\Http\Controllers\Server\Backups;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJobRun;
use App\Services\Backups\BackupRun;
use App\Services\Backups\BackupRunService;
use App\Services\Backups\JobService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Activity of the Backups hub (E48 spec §8.2): the job runs of the server,
 * filtered by job, state and user; a run opens its results per user, which
 * link to the user's backups. Administrators only.
 */
final class ActivityController extends Controller
{
    public const STATES = ['running', 'success', 'partial', 'failed'];

    public function __construct(
        private readonly BackupRunService $runs,
        private readonly JobService $jobs,
    ) {}

    public function index(Request $request, Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewHub($principal), 403);
        $filters = $request->validate([
            'job' => ['nullable', 'string', 'regex:/^\d{10,16}$/'],
            'status' => ['nullable', 'in:'.implode(',', self::STATES)],
            'user' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{0,31}$/i'],
        ]);
        $job = (string) ($filters['job'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $user = (string) ($filters['user'] ?? '');

        $runs = $this->runs->runs($job);
        if ($status !== '') {
            $runs = array_values(array_filter($runs, fn (BackupJobRun $run) => $run->status === $status));
        }
        $userRuns = [];
        if ($user !== '') {
            // The job runs that backed up the user, and the user's own result in each
            foreach ($this->runs->userRuns() as $row) {
                if ($row->user === $user && $row->job !== '') {
                    $userRuns[] = $row;
                }
            }
            $runs = array_values(array_filter($runs, fn (BackupJobRun $run) => $this->touches($run, $userRuns)));
        }

        return view('server.backups.activity.index', [
            'runs' => array_slice($runs, 0, 200),
            'jobs' => $this->jobs->all(),
            'filters' => ['job' => $job, 'status' => $status, 'user' => $user],
        ]);
    }

    public function show(string $job, string $run, Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewHub($principal), 403);
        abort_unless(preg_match('/^\d{10,16}$/', $job) === 1 && preg_match('/^\d{10,20}$/', $run) === 1, 404);
        $record = $this->runs->find($job, $run);
        abort_if($record === null, 404);

        return view('server.backups.activity.show', [
            'run' => $record,
            'results' => $this->runs->userResults($job, $record),
        ]);
    }

    /** @param  list<BackupRun>  $userRuns */
    private function touches(BackupJobRun $run, array $userRuns): bool
    {
        $from = strtotime($run->start) ?: 0;
        $to = $run->end !== '' ? (strtotime($run->end) ?: PHP_INT_MAX) : PHP_INT_MAX;
        foreach ($userRuns as $row) {
            $start = strtotime($row->start) ?: 0;
            if ($row->job === $run->job && $start >= $from && $start <= $to) {
                return true;
            }
        }

        return false;
    }
}
