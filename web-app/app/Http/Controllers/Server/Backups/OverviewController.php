<?php

namespace App\Http\Controllers\Server\Backups;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\BackupJob;
use App\Services\Backups\BackupJobRun;
use App\Services\Backups\BackupRunService;
use App\Services\Backups\DestinationService;
use App\Services\Backups\JobService;
use Illuminate\View\View;

/**
 * Overview of the Backups hub (E48 spec §8.2): the job runs of the last 24
 * hours, the next runs, destinations that fail, and encrypted destinations
 * whose recovery kit was never downloaded. Administrators only.
 */
final class OverviewController extends Controller
{
    public function __construct(
        private readonly DestinationService $destinations,
        private readonly JobService $jobs,
        private readonly BackupRunService $runs,
    ) {}

    public function __invoke(Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewHub($principal), 403);
        $destinations = $this->destinations->all();
        $jobs = $this->jobs->all();
        $since = now()->subDay()->getTimestamp();
        $recent = array_values(array_filter($this->runs->runs(), fn (BackupJobRun $run) => (strtotime($run->start) ?: 0) >= $since));
        $counts = ['success' => 0, 'partial' => 0, 'failed' => 0, 'running' => 0];
        foreach ($recent as $run) {
            if (isset($counts[$run->status])) {
                $counts[$run->status]++;
            }
        }
        $next = array_filter($jobs, fn (BackupJob $job) => $job->enabled && $job->nextRun !== '');
        usort($next, fn (BackupJob $a, BackupJob $b) => $a->nextRun <=> $b->nextRun);

        return view('server.backups.overview', [
            'destinations' => $destinations,
            'jobs' => $jobs,
            'recent' => array_slice($recent, 0, 10),
            'counts' => $counts,
            'next' => array_slice($next, 0, 5),
            'failing' => array_filter($destinations, fn (BackupDestination $d) => $d->state === 'failing'),
            'noKit' => array_filter($destinations, fn (BackupDestination $d) => $d->needsKit()),
            'canManage' => $policy->manageJobs($principal),
        ]);
    }
}
