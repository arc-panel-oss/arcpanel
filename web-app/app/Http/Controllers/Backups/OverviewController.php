<?php

namespace App\Http\Controllers\Backups;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupDestination;
use App\Services\Backups\BackupJob;
use App\Services\Backups\BackupRun;
use App\Services\Backups\BackupService;
use App\Services\Backups\DestinationService;
use App\Services\Backups\JobService;
use App\Services\Support\OwnerList;
use Illuminate\View\View;

/**
 * Overview of an account's backups (E48 spec §8.1): the last run, the next
 * run of each job that covers the account, the destinations of those jobs
 * by name and driver only, the counts of archives and snapshots, and Back up
 * now. Resellers see the accounts of their tree through the owner scope.
 */
final class OverviewController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly JobService $jobs,
        private readonly DestinationService $destinations,
        private readonly SysConfig $config,
    ) {}

    public function __invoke(string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $settings = $this->backups->settings($user);
        $jobs = array_values(array_filter($this->jobs->covering($user, $settings['package']),
            fn (BackupJob $job) => $job->engine === 'archive' || $settings['incremental']));
        $names = [];
        foreach ($jobs as $job) {
            $names = [...$names, ...$job->destinations()];
        }
        $destinations = array_values(array_filter($this->destinations->all(), fn (BackupDestination $d) => in_array($d->name, $names, true)));
        $runs = $policy->viewHistory($principal, $user) ? $this->backups->runs($user) : [];
        $last = $runs[0] ?? null;
        $lastDone = null;
        foreach ($runs as $run) {
            if ($run->status !== 'running') {
                $lastDone = $run;
                break;
            }
        }

        return view('backups.overview', [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user) + ['history' => $policy->viewHistory($principal, $user)],
            'incremental' => $settings['incremental'],
            'jobs' => $jobs,
            'archiveJobs' => array_values(array_filter($jobs, fn (BackupJob $job) => $job->engine === 'archive')),
            'destinations' => $destinations,
            'running' => $last !== null && $last->status === 'running' ? $last : null,
            'last' => $lastDone,
            'archives' => count($this->backups->archives($user)),
            'snapshots' => $settings['incremental'] ? count($this->backups->snapshots($user)) : null,
            'available' => $this->config->get('BACKUP_SYSTEM') !== '',
        ]);
    }

    /** Duration of a run in words, "3 min" or "45 s". */
    public static function duration(BackupRun $run): string
    {
        $start = strtotime($run->start);
        $end = strtotime($run->end);
        if ($start === false || $end === false || $end < $start) {
            return '';
        }
        $seconds = $end - $start;

        return $seconds >= 60 ? sprintf(__('%d min'), intdiv($seconds, 60)) : sprintf(__('%d s'), $seconds);
    }
}
