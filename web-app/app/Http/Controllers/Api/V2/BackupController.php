<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
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
use App\Http\Resources\V2\BackupResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupArchive;
use App\Services\Backups\BackupPaths;
use App\Services\Backups\BackupService;
use App\Services\Tasks\TaskMapper;
use App\Services\Tasks\TaskService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Tar backup archives of a user (E34 batch 3 spec §6.1).
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
    ) {}

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
     * Create a backup
     *
     * Queues a backup of the user. When a backup or restore is already queued,
     * answers 409 `E_EXISTS` with `existing` set to that task.
     *
     * @operationId createBackup
     *
     * @status 202
     */
    public function store(string $user, Principal $principal, BackupPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $steps = [new Step(__('Create backup'), 'v-schedule-user-backup', [$user, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $this->backups->schedule($user, 'json'), 'backup');
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
     * @status 202
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
        $args = $this->restoreArgs($user, $backup, $selection);
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
     * @status 202
     */
    public function download(string $user, string $backup, Principal $principal, BackupPolicy $policy): Response|TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->download($principal, $user), 403);
        $archive = $this->archive($user, $backup);

        if (is_file(BackupPaths::localFile($archive->name))) {
            return response('', 200, [
                'Content-Type' => $archive->contentType(),
                'Content-Disposition' => 'attachment; filename="'.$archive->name.'"',
                'X-Accel-Redirect' => BackupPaths::accelRedirect($archive->name),
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

    private function assertOwner(string $user, Principal $principal, BackupPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->tasks->userExists($user), 404);
    }

    private function archive(string $user, string $backup): BackupArchive
    {
        abort_unless(BackupService::ownsName($user, $backup), 404);

        return $this->backups->archive($user, $backup);
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

    /**
     * @param  array<string, list<string>>|null  $selection
     * @return list<string>
     */
    private function restoreArgs(string $user, string $backup, ?array $selection): array
    {
        $args = [$user, $backup];
        if ($selection !== null) {
            foreach (BackupArchive::TYPES as $type) {
                $objects = $selection[$type] ?? [];
                $args[] = $type === 'cron'
                    ? ($objects !== [] ? 'yes' : 'no')
                    : ($objects !== [] ? implode(',', $objects) : 'no');
            }
        }
        $args[] = 'json';

        return $args;
    }

    private function queued(string $user, CommandResult $result, string $kind, string $args = ''): TaskResource|JsonResponse
    {
        if ($result->exit() === ExitCode::Exists) {
            $task = $this->tasks->queuedBackupOrRestore($user);
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
