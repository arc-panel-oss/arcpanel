<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\CrossUserList;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Api\TaskId;
use App\Api\TaskLink;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RestoreBackupRequest;
use App\Http\Requests\Api\V2\RestoreSnapshotRequest;
use App\Http\Requests\Api\V2\SetBackupExclusionsRequest;
use App\Http\Resources\V2\BackupExclusionsResource;
use App\Http\Resources\V2\BackupResource;
use App\Http\Resources\V2\BackupRunResource;
use App\Http\Resources\V2\SnapshotFileEntryResource;
use App\Http\Resources\V2\SnapshotListingEntry;
use App\Http\Resources\V2\SnapshotResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupArchive;
use App\Services\Backups\BackupJob;
use App\Services\Backups\BackupPaths;
use App\Services\Backups\BackupRun;
use App\Services\Backups\BackupService;
use App\Services\Backups\JobService;
use App\Services\Files\FilePath;
use App\Services\Files\InvalidFilePath;
use App\Services\Tasks\TaskMapper;
use App\Services\Tasks\TaskService;
use App\Services\WebDomains\Step;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Backup archives, restic snapshots and exclusions of a user (E34 batch 3 spec §6.1–§6.3).
 *
 * @tags Backups
 */
final class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly TaskService $tasks,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
        private readonly BackupPaths $paths,
    ) {}

    /**
     * List the backup archives of every user
     *
     * Every user's backup archives for an administrator's key, those of the reseller
     * and its customers for a reseller's key; a user's key answers 403
     * (`listBackups` covers it). Sorted by user, then name. With
     * `updated_since`, only the users whose backup archives changed at or after that
     * time are returned, each with all its backup archives: `meta.as_of` is the next
     * `updated_since`, `meta.users` every user in scope and
     * `meta.changed_users` the users returned (E43 B2).
     *
     * @operationId listAllBackups
     */
    public function indexAll(Request $request, CrossUserList $lists): AnonymousResourceCollection
    {
        $page = $lists->page($request, 'v-list-sys-backups', BackupArchive::fromArray(...), fn (BackupArchive $archive) => $archive->name);

        return BackupResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the backup archives of a user
     *
     * Newest first. `meta.limit` is the number of archives kept; `meta.incremental`
     * is whether the package has incremental backups.
     *
     * @operationId listBackups
     */
    public function index(Request $request, string $user, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $settings = $this->backups->settings($user);
        $page = $this->pages->slice($request, $this->backups->archives($user));
        $page['meta']['limit'] = (int) $settings['backups'];
        $page['meta']['incremental'] = $settings['incremental'];

        return BackupResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the backup runs of every user
     *
     * Every user's backup runs for an administrator's key, those of the reseller
     * and its customers for a reseller's key; a user's key answers 403
     * (`listBackupRuns` covers it). Sorted by user, then newest first. With
     * `updated_since`, only the users whose runs changed at or after that time
     * are returned, each with all its runs: `meta.as_of` is the next
     * `updated_since`, `meta.users` every user in scope and
     * `meta.changed_users` the users returned (Fleet PD2, as E43 B2).
     *
     * @operationId listAllBackupRuns
     */
    public function runsAll(Request $request, CrossUserList $lists): AnonymousResourceCollection
    {
        $page = $lists->page($request, 'v-list-sys-backup-runs', BackupRun::fromArray(...), fn (BackupRun $run) => $run->sortKey());

        return BackupRunResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the backup runs of a user
     *
     * Every run of a tar or restic backup of the user, newest first: the
     * nightly backup (`trigger: schedule`), requests queued by the panel or
     * the API (`request`, with their `task`) and runs started on the server
     * (`cli`), failed ones included with their `exit_code` and `error`. The
     * server keeps the last 30 runs. A run whose process ended without
     * finishing its record is `failed` with the error `interrupted` once the
     * next run of the user starts (Fleet PD2).
     *
     * @operationId listBackupRuns
     */
    public function runs(Request $request, string $user, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, $this->backups->runs($user));

        return BackupRunResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a backup
     *
     * Queues a backup of the user. With `job`, the archive goes to the
     * destinations of that archive job, which must cover the user (E48);
     * without it, to those of the server's default archive job. When a
     * backup or restore is already queued, answers 409 `E_EXISTS` with
     * `existing` set to that task.
     *
     * @operationId createBackup
     *
     * @monitor
     *
     * @status 202
     *
     * @conflict E_EXISTS A backup of the user is queued or running: `existing` is that task when the key may read it.
     */
    public function store(Request $request, string $user, Principal $principal, BackupPolicy $policy, JobService $jobs): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $data = $request->validate([
            /** An archive job that covers the user (`listBackupJobs`, or the `job` of an archive). */
            'job' => ['nullable', 'string', 'regex:/^\d{8,20}$/'],
        ]);
        $job = (string) ($data['job'] ?? '');
        $steps = [new Step(__('Create backup'), 'v-schedule-user-backup', [$user, 'json', ...($job !== '' ? [$job] : [])])];
        $this->gate->authorize($steps, $job !== '' ? ['v-list-backup-jobs'] : []);
        if ($job !== '') {
            $covering = array_filter($jobs->covering($user, $this->backups->settings($user)['package'], enabledOnly: false), fn (BackupJob $j) => $j->id === $job && $j->engine === 'archive');
            if ($covering === []) {
                throw ValidationException::withMessages(['job' => __('This is not an archive job that backs up the user.')]);
            }
        }
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $this->backups->schedule($user, 'json', $job), 'backup');
    }

    /**
     * Get a backup archive
     *
     * @operationId getBackup
     */
    public function show(string $user, string $backup, Principal $principal, BackupPolicy $policy): BackupResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new BackupResource($this->archive($user, $backup));
    }

    /**
     * Delete a backup archive
     *
     * @operationId deleteBackup
     *
     * @status 204
     */
    public function destroy(string $user, string $backup, Principal $principal, BackupPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->archive($user, $backup);
        $steps = [new Step(sprintf(__('Delete backup %s'), $backup), 'v-delete-user-backup', [$user, $backup])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->backups->delete($user, [$backup]);
        if (! $result->ok()) {
            throw new ApiException(ErrorCode::Server, $result->firstError(), 500);
        }

        return response()->noContent();
    }

    /**
     * Restore a backup archive
     *
     * Optional `web`, `dns`, `mail`, `db` and `udir` lists and a `cron` flag
     * restore only those objects. An empty body restores everything.
     *
     * @operationId restoreBackup
     *
     * @monitor
     *
     * @status 202
     *
     * @conflict E_EXISTS A restore of the user is queued or running: `existing` is that task when the key may read it.
     */
    public function restore(RestoreBackupRequest $request, string $user, string $backup, Principal $principal, BackupPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->restore($principal, $user), 403);
        $archive = $this->archive($user, $backup);
        $selection = null;
        if ($request->selectsObjects()) {
            $selection = $this->selection($request, $archive);
        }
        $args = BackupService::restoreArgs($user, $backup, $selection, 'json');
        $steps = [new Step(sprintf(__('Restore backup %s'), $backup), 'v-schedule-user-restore', $args)];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $this->backups->restore($user, $backup, $selection, 'json'), 'restore', $backup);
    }

    /**
     * Download a backup archive
     *
     * Serves a local archive through nginx. A remote archive is fetched first
     * and answers 202 with the queue task.
     *
     * @operationId downloadBackup
     *
     * @monitor
     */
    #[ApiResponse(
        status: 200,
        description: 'The archive when it is on this server: an empty body with `X-Accel-Redirect` so nginx serves the bytes (D35).',
        mediaType: 'application/octet-stream',
    )]
    #[ApiResponse(
        status: 202,
        description: 'The fetch task when the archive is only on a remote backup host.',
        mediaType: 'application/json',
        type: TaskResource::class,
    )]
    #[Header('Content-Disposition', description: '`attachment; filename="<backup>"`', status: 200)]
    #[Header('X-Accel-Redirect', description: 'Internal nginx path to the archive file (D35).', status: 200)]
    public function download(string $user, string $backup, Principal $principal, BackupPolicy $policy): Response|TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->download($principal, $user), 403);
        $archive = $this->archive($user, $backup);

        if (is_file($this->paths->localFile($archive->name))) {
            $redirect = $this->paths->accelRedirect($archive->name)
                ?? throw new ApiException(ErrorCode::Server, __('The backup folder is not served by the web server.'), 500);

            return response('', 200, [
                'Content-Type' => $archive->contentType(),
                'Content-Disposition' => 'attachment; filename="'.$archive->name.'"',
                'X-Accel-Redirect' => $redirect,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        abort_unless($policy->create($principal, $user), 404);
        $steps = [new Step(sprintf(__('Download backup %s'), $backup), 'v-schedule-user-backup-download', [$user, $backup, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $this->backups->scheduleDownload($user, $backup, 'json'), 'backup-download', $backup);
    }

    /**
     * List restic snapshots of a user
     *
     * Newest first. Only available when the user's package has incremental backups.
     *
     * @operationId listSnapshots
     */
    public function listSnapshots(Request $request, string $user, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertIncremental($user);
        $page = $this->pages->slice($request, $this->backups->snapshots($user));

        return SnapshotResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a restic snapshot
     *
     * Queues a snapshot of the user. When a backup or restore is already queued,
     * answers 409 `E_EXISTS` with `existing` set to that task.
     *
     * @operationId createSnapshot
     *
     * @monitor
     *
     * @status 202
     */
    public function createSnapshot(string $user, Principal $principal, BackupPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertIncremental($user);
        abort_unless($policy->create($principal, $user), 403);
        $steps = [new Step(__('Create snapshot'), 'v-schedule-user-backup-restic', [$user, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $this->backups->scheduleSnapshot($user, 'json'), 'snapshot');
    }

    /**
     * Get a restic snapshot
     *
     * Includes the objects the snapshot holds per type.
     *
     * @operationId getSnapshot
     */
    public function getSnapshot(string $user, string $snapshot, Principal $principal, BackupPolicy $policy): SnapshotResource
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertIncremental($user);
        $this->assertSnapshotId($snapshot);
        $record = $this->backups->snapshot($user, $snapshot);

        return new SnapshotResource($record, $this->backups->snapshotContent($user, $snapshot));
    }

    /**
     * Delete a restic snapshot
     *
     * @operationId deleteSnapshot
     *
     * @status 204
     */
    public function destroySnapshot(string $user, string $snapshot, Principal $principal, BackupPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertIncremental($user);
        abort_unless($policy->delete($principal, $user), 403);
        $this->assertSnapshotId($snapshot);
        $this->backups->snapshot($user, $snapshot);
        $steps = [new Step(sprintf(__('Delete snapshot %s'), $snapshot), 'v-delete-user-backup-restic', [$user, $snapshot])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->backups->deleteSnapshot($user, $snapshot);
        if (! $result->ok()) {
            throw new ApiException(ErrorCode::Server, $result->firstError(), 500);
        }

        return response()->noContent();
    }

    /**
     * List files and folders in a restic snapshot
     *
     * Paths are relative to the home directory. Entries are read-only.
     *
     * @operationId listSnapshotFiles
     */
    public function listSnapshotFiles(Request $request, string $user, string $snapshot, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertIncremental($user);
        $this->assertSnapshotId($snapshot);
        $this->backups->snapshot($user, $snapshot);
        try {
            $relative = FilePath::from(is_string($request->query('path')) ? $request->query('path') : '');
        } catch (InvalidFilePath) {
            throw ValidationException::withMessages(['path' => [__('The path is invalid.')]]);
        }
        $home = self::home($user);
        $listing = $this->backups->snapshotFolder($user, $snapshot, $relative->absolute($home));
        $page = $this->pages->slice($request, $listing->entries);
        $items = array_map(
            fn (array $entry) => new SnapshotListingEntry($entry, $home, $listing->path),
            $page['items'],
        );

        return SnapshotFileEntryResource::collection($items)->additional(['meta' => $page['meta']]);
    }

    /**
     * Restore from a restic snapshot
     *
     * An empty body restores everything. Send `type` and `object` to restore one
     * web, dns, mail or database object, or all cron jobs. Send `path` to restore
     * one file or folder; the restore overwrites the current file.
     *
     * @operationId restoreSnapshot
     *
     * @monitor
     *
     * @status 202
     */
    public function restoreSnapshot(
        RestoreSnapshotRequest $request,
        string $user,
        string $snapshot,
        Principal $principal,
        BackupPolicy $policy,
    ): TaskResource|JsonResponse {
        $this->assertOwner($user, $principal, $policy);
        $this->assertIncremental($user);
        abort_unless($policy->restore($principal, $user), 403);
        $this->assertSnapshotId($snapshot);
        $this->backups->snapshot($user, $snapshot);

        if ($request->restoresPath()) {
            return $this->restoreSnapshotPath($request, $user, $snapshot);
        }

        $type = $request->restoresObject() ? $request->string('type')->toString() : null;
        $object = $request->input('object');
        if ($type !== null && $type !== 'cron') {
            $content = $this->backups->snapshotContent($user, $snapshot);
            abort_unless(in_array($object, $content->items[$type], true), 404);
        }
        $args = $type === null
            ? [$user, $snapshot, '', '', 'json']
            : array_merge([$user, $snapshot, $type, $type === 'cron' ? 'yes' : (string) $object], ['json']);
        $steps = [new Step(sprintf(__('Restore snapshot %s'), $snapshot), 'v-schedule-user-restore-restic', $args)];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued(
            $user,
            $this->backups->restoreSnapshot($user, $snapshot, $type, (string) ($object ?? ''), 'json'),
            'snapshot-restore',
            trim($snapshot.' '.($type ?? '')),
        );
    }

    /**
     * Get backup exclusions of a user
     *
     * @operationId getBackupExclusions
     */
    public function showExclusions(string $user, Principal $principal, BackupPolicy $policy): BackupExclusionsResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new BackupExclusionsResource($this->backups->exclusions($user));
    }

    /**
     * Set backup exclusions of a user
     *
     * Send the full object. A type left out of the body keeps its current list.
     *
     * @operationId setBackupExclusions
     */
    public function updateExclusions(
        SetBackupExclusionsRequest $request,
        string $user,
        Principal $principal,
        BackupPolicy $policy,
    ): BackupExclusionsResource|JsonResponse {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->editExclusions($principal, $user), 403);
        $current = $this->backups->exclusions($user);
        $changes = [];
        foreach (['web' => 'WEB', 'dns' => 'DNS', 'mail' => 'MAIL', 'db' => 'DB', 'cron' => 'CRON', 'user' => 'USER'] as $field => $key) {
            if ($request->exists($field)) {
                $changes[$key] = array_values(array_map('strval', $request->input($field, [])));
            }
        }
        $updated = $current->with($changes);
        $steps = [new Step(__('Update backup exclusions'), 'v-update-user-backup-exclusions', [$user])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->backups->saveExclusions($user, $updated);
        if (! $result->ok()) {
            throw new ApiException(ErrorCode::fromExit($result->exit()), $result->errorMessage(), ErrorCode::statusOfExit($result->exit()));
        }

        return new BackupExclusionsResource($updated);
    }

    private function restoreSnapshotPath(RestoreSnapshotRequest $request, string $user, string $snapshot): TaskResource|JsonResponse
    {
        try {
            $relative = FilePath::from($request->string('path')->toString());
        } catch (InvalidFilePath) {
            throw ValidationException::withMessages(['path' => [__('The path is invalid.')]]);
        }
        if ($relative->isRoot()) {
            throw ValidationException::withMessages(['path' => [__('The home directory cannot be restored on its own.')]]);
        }
        $absolute = $relative->absolute(self::home($user));
        $steps = [new Step(sprintf(__('Restore file from snapshot %s'), $snapshot), 'v-schedule-user-restore-file-restic', [$user, $snapshot, $absolute, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->backups->restoreFile($user, $snapshot, $absolute, 'json');
        if ($result->exit() === ExitCode::Exists) {
            $task = $this->tasks->blockingBackupTask($user, 'restore');
            if ($task !== null) {
                throw new ApiException(
                    ErrorCode::Exists,
                    $result->message() !== '' ? $result->message() : __('An existing restoration task is already running. Please wait for it to finish before launching it again.'),
                    409,
                    existing: (new TaskResource($task))->resolve(request()),
                );
            }
        }
        if ($result->exit() === ExitCode::Invalid || $result->exit() === ExitCode::Args) {
            throw ValidationException::withMessages(['path' => [$result->message() !== '' ? $result->message() : __('The path is invalid.')]]);
        }
        if (! $result->ok()) {
            throw new ApiException(
                ErrorCode::fromExit($result->exit()),
                $result->errorMessage(),
                ErrorCode::statusOfExit($result->exit()),
            );
        }

        return $this->queued($user, $result, 'file-restore', trim($snapshot.' '.$relative->relative()));
    }

    private function assertOwner(string $user, Principal $principal, BackupPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->tasks->userExists($user), 404);
    }

    private function archive(string $user, string $backup): BackupArchive
    {
        abort_unless(BackupService::ownsName($user, $backup), 404);

        return $this->backups->archive($user, $backup);
    }

    private function assertIncremental(string $user): void
    {
        abort_unless($this->backups->incremental($user), 404);
    }

    private function assertSnapshotId(string $snapshot): void
    {
        abort_unless(preg_match('/^[0-9a-f]{8,64}$/', $snapshot) === 1, 404);
    }

    private static function home(string $user): string
    {
        return '/home/'.$user;
    }

    /**
     * @return array<string, list<string>>
     */
    private function selection(RestoreBackupRequest $request, BackupArchive $archive): array
    {
        $errors = [];
        $selection = [];
        foreach (BackupArchive::TYPES as $type) {
            if ($type === 'cron') {
                if ($request->exists('cron') && $request->boolean('cron')) {
                    $selection['cron'] = ['yes'];
                }

                continue;
            }
            if (! $request->exists($type)) {
                continue;
            }
            $requested = array_values(array_unique(array_map('strval', $request->input($type, []))));
            $invalid = array_values(array_diff($requested, $archive->items[$type]));
            if ($invalid !== []) {
                $errors[$type] = [sprintf(__('The archive does not contain: %s'), implode(', ', $invalid))];
            }
            $chosen = array_values(array_intersect($requested, $archive->items[$type]));
            if ($chosen !== []) {
                $selection[$type] = $chosen;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($selection === []) {
            throw ValidationException::withMessages([
                'web' => [__('No items were selected to restore.')],
            ]);
        }

        return $selection;
    }

    private function queued(string $user, CommandResult $result, string $kind, string $args = ''): TaskResource|JsonResponse
    {
        if ($result->exit() === ExitCode::Exists) {
            $task = $this->tasks->blockingBackupTask($user, str_contains($kind, 'restore') ? 'restore' : 'backup');
            if ($task !== null) {
                throw new ApiException(
                    ErrorCode::Exists,
                    $result->message() !== '' ? $result->message() : __('An existing backup task is already running, please wait for it to complete.'),
                    409,
                    existing: (new TaskResource($task))->resolve(request()),
                );
            }
        }
        if (! $result->ok()) {
            throw new ApiException(
                ErrorCode::fromExit($result->exit()),
                $result->errorMessage(),
                ErrorCode::statusOfExit($result->exit()),
            );
        }
        $recordId = BackupService::queueTaskId($result);
        if ($recordId === '') {
            throw new ApiException(ErrorCode::Server, __('The task could not be queued.'), 500);
        }
        $task = TaskMapper::fromSource(new TaskId(TaskId::SOURCE_QUEUE, $recordId, $user), [
            'ID' => $recordId,
            'KIND' => $kind,
            'ARGS' => $args,
            'STATUS' => 'queued',
            'EXIT' => '',
            'ERROR' => '',
            'QUEUED' => now()->format('Y-m-d H:i:s'),
            'START' => '',
            'END' => '',
            'LOG' => '',
        ], 1);

        return (new TaskResource($task))
            ->response()
            ->setStatusCode(202)
            ->header('Link', TaskLink::queue($user, $recordId));
    }
}
