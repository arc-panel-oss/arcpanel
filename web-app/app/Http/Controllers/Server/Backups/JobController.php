<?php

namespace App\Http\Controllers\Server\Backups;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\BackupJobRequest;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJob;
use App\Services\Backups\BackupRunService;
use App\Services\Backups\BackupSchedule;
use App\Services\Backups\DestinationService;
use App\Services\Backups\JobService;
use App\Services\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Backup jobs of the Backups hub (E48 spec §5, §8.2 Schedules): the table
 * with the enabled switch and Run now, and the form with schedule presets,
 * a cron field with a preview of the next runs, the scope and the targets
 * with their retention. Administrators only.
 */
final class JobController extends Controller
{
    public function __construct(
        private readonly JobService $jobs,
        private readonly DestinationService $destinations,
        private readonly BackupRunService $runs,
        private readonly UserDirectory $users,
    ) {}

    public function index(Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewJobs($principal), 403);

        return view('server.backups.jobs.index', [
            'jobs' => $this->jobs->all(),
            'destinations' => $this->destinations->all(),
            'canManage' => $policy->manageJobs($principal),
        ]);
    }

    public function create(Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->manageJobs($principal), 403);

        return view('server.backups.jobs.form', $this->formData(null));
    }

    public function store(BackupJobRequest $request): RedirectResponse
    {
        $result = $this->jobs->add($request->values(), $request->boolean('enabled'));
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.backups.jobs')->with('ok', sprintf(__('Job %s has been added.'), $request->values()['name']));
    }

    public function edit(string $job, Principal $principal, BackupPolicy $policy): View
    {
        abort_unless($policy->viewJobs($principal), 403);
        $record = $this->jobs->find($job);

        return view('server.backups.jobs.form', $this->formData($record) + [
            'canManage' => $policy->manageJobs($principal),
            'runs' => array_slice($this->runs->runs($record->id), 0, 10),
        ]);
    }

    public function update(BackupJobRequest $request, string $job): RedirectResponse
    {
        $this->jobs->find($job);
        $result = $this->jobs->change($job, $request->values());
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.backups.jobs')->with('ok', sprintf(__('Job %s has been saved.'), $request->values()['name']));
    }

    public function destroy(string $job, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageJobs($principal), 403);
        $record = $this->jobs->find($job);
        $result = $this->jobs->delete($job);
        if (! $result->ok()) {
            return back()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.backups.jobs')->with('ok', sprintf(__('Job %s has been deleted.'), $record->name));
    }

    /** Pause or resume a job (the enabled switch of the table). */
    public function toggle(Request $request, string $job, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageJobs($principal), 403);
        $record = $this->jobs->find($job);
        $enabled = $request->boolean('enabled');
        $result = $this->jobs->setEnabled($job, $enabled);
        if (! $result->ok()) {
            return back()->with('error', $result->errorMessage());
        }

        return back()->with('ok', sprintf($enabled ? __('Job %s runs on its schedule again.') : __('Job %s is paused.'), $record->name));
    }

    /** Run now: the whole job in the background; Activity follows it. */
    public function run(string $job, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageJobs($principal), 403);
        $record = $this->jobs->find($job);
        $result = $this->jobs->start($job);
        if (! $result->ok()) {
            return back()->with('error', $result->errorMessage());
        }

        return redirect()->route('server.backups.activity', ['job' => $job])
            ->with('ok', sprintf(__('Job %s has started.'), $record->name));
    }

    /** The next runs of the schedule of the form fields, as the dispatcher computes them. */
    public function preview(Request $request, Principal $principal, BackupPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewJobs($principal), 403);
        $data = $request->validate([
            'preset' => ['required', 'in:'.implode(',', BackupSchedule::PRESETS)],
            'hour' => ['nullable', 'integer', 'between:0,23'],
            'minute' => ['nullable', 'integer', 'between:0,59'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'day' => ['nullable', 'integer', 'between:1,28'],
            'cron' => ['nullable', 'string', 'max:128', 'regex:/^[0-9*\/,\- ]+$/'],
        ]);
        $schedule = BackupSchedule::fromForm($data['preset'], (int) ($data['hour'] ?? 0), (int) ($data['minute'] ?? 0),
            (int) ($data['weekday'] ?? 1), (int) ($data['day'] ?? 1), (string) ($data['cron'] ?? ''));
        $times = $this->jobs->preview($schedule);

        return response()->json($times === null
            ? ['valid' => false, 'message' => __('This schedule is not valid or never runs.')]
            : ['valid' => true, 'describe' => BackupSchedule::parse($schedule)->describe(), 'times' => $times]);
    }

    /** @return array<string, mixed> */
    private function formData(?BackupJob $job): array
    {
        return [
            'job' => $job,
            'destinations' => $this->destinations->all(),
            'packages' => $this->jobs->packageNames(),
            'users' => $this->users->names(),
        ];
    }
}
