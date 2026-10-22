<?php

namespace App\Http\Controllers\Backups;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupService;
use App\Services\Backups\JobService;
use App\Services\Support\OwnerList;
use Illuminate\View\View;

/**
 * History of an account's backups (E48 spec §8.1): every recorded run with
 * its trigger, job, state, duration, size and the state on each destination.
 */
final class HistoryController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly JobService $jobs,
    ) {}

    public function __invoke(string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->viewHistory($principal, $user), 403);

        return view('backups.history', [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user),
            'incremental' => $this->backups->incremental($user),
            'runs' => $this->backups->runs($user),
            'jobNames' => array_map(fn ($job) => $job->name, $this->jobs->all()),
        ]);
    }
}
