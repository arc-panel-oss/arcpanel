<?php

namespace App\Http\Controllers\Backups;

use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupArchive;
use App\Services\Backups\BackupPaths;
use App\Services\Backups\BackupService;
use App\Services\Backups\JobService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tar backups of an account (web/list/backup, web/schedule/backup,
 * web/schedule/restore, web/bulk/backup, web/bulk/restore,
 * web/delete/backup, web/download/backup; E7 batch 1 spec §10).
 */
final class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SysConfig $config,
    ) {}

    /** /backups: the effective account, or the owner picked by an administrator or reseller. */
    public function entry(Request $request, OwnerList $owners): RedirectResponse
    {
        return redirect()->route('backups.overview', ['user' => $owners->forCreate($request->query('owner'))]);
    }

    public function index(Request $request, string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners, JobService $jobs): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $archives = $this->backups->archives($user);
        $destinations = array_values(array_unique(array_merge([], ...array_map(fn (BackupArchive $a) => $a->destinations, $archives))));
        sort($destinations);
        // The filter by destination (E48 spec §8.1)
        $destination = (string) $request->query('destination', '');
        if ($destination !== '') {
            $archives = array_values(array_filter($archives, fn (BackupArchive $a) => in_array($destination, $a->destinations, true)));
        }

        return view('backups.index', [
            ...$this->page($user, $principal, $policy, $owners),
            'archives' => $archives,
            'destinations' => $destinations,
            'destination' => in_array($destination, $destinations, true) ? $destination : '',
            'jobNames' => array_map(fn ($job) => $job->name, $jobs->all()),
        ]);
    }

    /**
     * "Create backup": queued, the user gets an e-mail when it is done. JOB
     * picks one of the archive jobs that cover the account (E48 spec §8.1).
     */
    public function store(Request $request, string $user, Principal $principal, BackupPolicy $policy, JobService $jobs): RedirectResponse
    {
        abort_unless($policy->create($principal, $user), 403);
        $job = (string) $request->input('job', '');
        if ($job !== '') {
            $package = $this->backups->settings($user)['package'];
            $ids = array_map(fn ($covering) => $covering->id, array_filter($jobs->covering($user, $package, enabledOnly: false), fn ($covering) => $covering->engine === 'archive'));
            abort_unless(in_array($job, $ids, true), 404);
        }

        return $this->queued(
            $this->backups->schedule($user, 'shell', $job),
            $request->input('from') === 'overview'
                ? redirect()->route('backups.overview', ['user' => $user])
                : redirect()->route('backups.archives.index', ['user' => $user]),
            __('Task has been added to the queue. You will receive an email notification when your backup is ready for download.'),
            __('An existing backup task is already running, please wait for it to complete.'),
        );
    }

    public function show(string $user, string $backup, Principal $principal, BackupPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);

        return view('backups.show', [
            ...$this->page($user, $principal, $policy, $owners),
            'archive' => $this->backups->archive($user, $backup),
        ]);
    }

    /** Restore everything, or the objects ticked on the detail page (items[type][]). */
    public function restore(Request $request, string $user, string $backup, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->restore($principal, $user), 403);
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*' => ['array'],
            'items.*.*' => ['string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
        ]);
        $archive = $this->backups->archive($user, $backup);
        // The restore wizard (E48) restores everything unless mode is "some", and then something must be picked
        if ($request->boolean('wizard')) {
            if ($request->input('mode') !== 'some') {
                $data['items'] = [];
            } elseif (empty($data['items'])) {
                return redirect()->route('backups.restore', ['user' => $user, 'archive' => $backup])->with('error', __('No items were selected to restore.'));
            }
        }

        $selection = null;
        if (! empty($data['items'])) {
            $selection = [];
            foreach (BackupArchive::TYPES as $type) {
                $chosen = array_values(array_intersect($data['items'][$type] ?? [], $archive->items[$type]));
                if ($chosen !== []) {
                    $selection[$type] = $chosen;
                }
            }
            if ($selection === []) {
                return back()->with('error', __('No items were selected to restore.'));
            }
        }

        return $this->queued(
            $this->backups->restore($user, $backup, $selection),
            // The restore wizard (E48) comes back to itself
            $request->boolean('wizard')
                ? redirect()->route('backups.restore', ['user' => $user, 'archive' => $backup])
                : redirect()->route('backups.archives.show', ['user' => $user, 'backup' => $backup]),
            __('Task has been added to the queue. You will receive an email notification when your restore has been completed.'),
            __('An existing restoration task is already running. Please wait for it to finish before launching it again.'),
        );
    }

    public function destroy(string $user, string $backup, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->delete($principal, $user), 403);
        $this->backups->archive($user, $backup);
        $result = $this->backups->delete($user, [$backup]);
        $back = redirect()->route('backups.archives.index', ['user' => $user]);

        return $result->ok()
            ? $back->with('ok', sprintf(__('Backup %s has been deleted.'), $backup))
            : $back->with('error', $result->firstError());
    }

    /** POST /backups/{user}/bulk {action: delete, items[]}: archive names of the user. */
    public function bulk(Request $request, string $user, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['delete'])],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'string', 'max:255'],
        ]);
        abort_unless($policy->delete($principal, $user), 403);
        $names = array_values(array_unique($data['items']));
        foreach ($names as $name) {
            abort_unless(BackupService::ownsName($user, $name), 404);
        }

        return $this->backups->delete($user, $names)->flash(redirect()->route('backups.archives.index', ['user' => $user]));
    }

    /**
     * Download an archive (web/download/backup): served by NGINX from the
     * backup directory. A remote archive is fetched first: this link only
     * asks, the button posts to {@see fetch()} (E39 W5 L22).
     */
    public function download(string $user, string $backup, Principal $principal, BackupPolicy $policy, BackupPaths $paths): Response|RedirectResponse|View
    {
        abort_unless($policy->download($principal, $user), 403);
        $archive = $this->backups->archive($user, $backup);

        if (! is_file($paths->localFile($archive->name))) {
            abort_unless($policy->create($principal, $user), 404);

            return view('backups.download', ['user' => $user, 'archive' => $archive]);
        }

        $redirect = $paths->accelRedirect($archive->name);
        abort_if($redirect === null, 500, __('The backup folder is not served by the web server.'));

        return response('', 200, [
            'Content-Type' => $archive->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$archive->name.'"',
            'X-Accel-Redirect' => $redirect,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** POST /backups/{user}/archives/{backup}/download: copy a remote archive to the server. */
    public function fetch(string $user, string $backup, Principal $principal, BackupPolicy $policy, BackupPaths $paths): RedirectResponse
    {
        abort_unless($policy->download($principal, $user) && $policy->create($principal, $user), 403);
        $archive = $this->backups->archive($user, $backup);
        if (is_file($paths->localFile($archive->name))) {
            return redirect()->route('backups.archives.download', ['user' => $user, 'backup' => $archive->name]);
        }

        return $this->queued(
            $this->backups->scheduleDownload($user, $backup),
            redirect()->route('backups.archives.index', ['user' => $user]),
            __('Download of remote backup file has been scheduled.'),
            __('An existing backup task is already running, please wait for it to complete.'),
        );
    }

    /**
     * Shared data of the three tabs.
     *
     * @return array<string, mixed>
     */
    private function page(string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners): array
    {
        $settings = $this->backups->settings($user);

        return [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user) + ['history' => $policy->viewHistory($principal, $user)],
            'incremental' => $settings['incremental'],
            'limit' => $settings['backups'],
            // DNS and mail sections of a backup are shown when the server runs them or the backup holds some
            'systems' => ['dns' => $this->config->get('DNS_SYSTEM') !== '', 'mail' => $this->config->get('MAIL_SYSTEM') !== ''],
        ];
    }

    /** Flash for a queued task: exit 4 means one is already queued. */
    private function queued(CommandResult $result, RedirectResponse $back, string $ok, string $busy): RedirectResponse
    {
        if ($result->ok()) {
            return $back->with('ok', $ok);
        }

        return $back->with('error', $result->exit() === ExitCode::Exists ? $busy : ($result->message() !== '' ? $result->message() : sprintf(__('Error code: %s'), $result->exitCode)));
    }
}
