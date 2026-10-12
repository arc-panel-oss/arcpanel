<?php

namespace App\Http\Controllers\Backups;

use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Restic snapshots of an account (web/list/backup/incremental,
 * web/schedule/backup/incremental, web/schedule/restore/incremental,
 * web/delete/backup/incremental; E7 batch 1 spec §10.2). Single files are
 * restored from the file manager's snapshot mode.
 */
final class SnapshotController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SysConfig $config,
    ) {}

    public function index(string $user, Principal $principal, BackupPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $this->assertIncremental($user);

        return view('backups.snapshots', [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user),
            'incremental' => true,
            // The snapshot browser lives in the file manager (FILE_MANAGER)
            'browse' => $this->config->is('FILE_MANAGER', 'true'),
            'snapshots' => $this->backups->snapshots($user),
        ]);
    }

    public function store(string $user, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->create($principal, $user), 403);
        $this->assertIncremental($user);
        $result = $this->backups->scheduleSnapshot($user);
        $back = redirect()->route('backups.snapshots.index', ['user' => $user]);

        if ($result->ok()) {
            return $back->with('ok', __('Snapshot has been scheduled.'));
        }

        return $back->with('error', $result->exit() === ExitCode::Exists
            ? __('An existing backup task is already running, please wait for it to complete.')
            : $result->message());
    }

    public function show(string $user, string $snapshot, Principal $principal, BackupPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $this->assertIncremental($user);

        return view('backups.snapshot', [
            'user' => $user,
            'owners' => $owners->pickable(),
            'can' => $policy->abilities($principal, $user),
            'incremental' => true,
            // The snapshot browser lives in the file manager (FILE_MANAGER)
            'browse' => $this->config->is('FILE_MANAGER', 'true'),
            'snapshot' => $this->backups->snapshot($user, $snapshot),
            'content' => $this->backups->snapshotContent($user, $snapshot),
            'systems' => ['dns' => $this->config->get('DNS_SYSTEM') !== '', 'mail' => $this->config->get('MAIL_SYSTEM') !== ''],
        ]);
    }

    /** Restore everything, or one web, dns, mail or db object, or the cron jobs. */
    public function restore(Request $request, string $user, string $snapshot, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->restore($principal, $user), 403);
        $this->assertIncremental($user);
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['web', 'dns', 'mail', 'db', 'cron'])],
            'object' => ['nullable', 'required_if:type,web,dns,mail,db', 'string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
        ]);
        $this->backups->snapshot($user, $snapshot);

        $type = $data['type'] ?? null;
        if ($type !== null && $type !== 'cron') {
            $content = $this->backups->snapshotContent($user, $snapshot);
            abort_unless(in_array($data['object'], $content->items[$type], true), 404);
        }

        $result = $this->backups->restoreSnapshot($user, $snapshot, $type, (string) ($data['object'] ?? ''));
        $back = redirect()->route('backups.snapshots.show', ['user' => $user, 'snapshot' => $snapshot]);
        if ($result->ok()) {
            return $back->with('ok', __('Task has been added to the queue. You will receive an email notification when your restore has been completed.'));
        }

        return $back->with('error', $result->exit() === ExitCode::Exists
            ? __('An existing restoration task is already running. Please wait for it to finish before launching it again.')
            : $result->message());
    }

    public function destroy(string $user, string $snapshot, Principal $principal, BackupPolicy $policy): RedirectResponse
    {
        abort_unless($policy->delete($principal, $user), 403);
        $this->assertIncremental($user);
        $this->backups->snapshot($user, $snapshot);
        $result = $this->backups->deleteSnapshot($user, $snapshot);
        $back = redirect()->route('backups.snapshots.index', ['user' => $user]);

        return $result->ok()
            ? $back->with('ok', sprintf(__('Snapshot %s has been deleted.'), $snapshot))
            : $back->with('error', $result->message());
    }

    /** Snapshots exist only for packages with BACKUPS_INCREMENTAL (templates/pages/list_backup.php). */
    private function assertIncremental(string $user): void
    {
        abort_unless($this->backups->incremental($user), 404);
    }
}
