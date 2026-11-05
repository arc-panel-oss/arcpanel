<?php

namespace App\Http\Controllers\Jobs;

use App\Api\TaskId;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\TaskPolicy;
use App\Services\Jobs\JobIndex;
use App\Services\Jobs\JobIndexUnavailable;
use App\Services\Jobs\JobStore;
use App\Services\Jobs\JobStoreException;
use App\Services\Tasks\JobMapper;
use App\Services\Tasks\TaskService;
use App\Services\Tasks\TaskView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The Job Center pages (E15 spec §6.2): the Jobs list from the job index, a
 * Job with its log followed live, cancel and retry, the JSON state that the
 * task panels poll, and the jobs of the notification tray. Every job is
 * scoped by TaskPolicy: a job outside the principal's scope answers 404.
 */
final class JobController extends Controller
{
    public const PAGE_SIZE = 50;

    /** Lines of the log the Job page shows before it follows the rest. */
    public const LOG_LINES = 500;

    /** Bytes of the log a poller gets on its first call, without an offset. */
    public const TAIL_BYTES = 65536;

    /** Finished jobs in the tray. */
    public const TRAY_FINISHED = 10;

    public const STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'];

    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request, Principal $principal, TaskPolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);
        $filters = $this->filters($request);
        $page = max(1, (int) $request->query('page', '1'));
        $visible = $policy->visible($principal);
        $error = null;
        $list = ['items' => [], 'total' => 0];
        try {
            $list = $this->tasks->listVisible($visible['users'], $visible['system'], $this->query($filters), self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        } catch (JobIndexUnavailable|JobStoreException) {
            $error = __('The job list could not be read.');
        }

        return view('jobs.index', [
            'jobs' => $list['items'],
            'total' => $list['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PAGE_SIZE)),
            'filters' => $filters,
            'query' => array_filter($filters, fn ($value) => $value !== ''),
            'showUser' => $visible['users'] === null || count($visible['users']) > 1 || $visible['system'],
            'error' => $error,
            'canRebuild' => $policy->viewSystem($principal),
        ]);
    }

    public function show(string $id, Principal $principal, TaskPolicy $policy, JobStore $store): View
    {
        $record = $store->findWithTail($id, self::LOG_LINES) ?? abort(404);
        $task = JobMapper::fromRecord($record, self::LOG_LINES);
        abort_unless($policy->view($principal, (string) $task->user), 404);

        return view('jobs.show', [
            'job' => $task,
            'offset' => (int) ($record['OFFSET'] ?? 0),
            'link' => self::objectLink($task),
            'canManage' => $policy->manage($principal, (string) $task->user),
            'statusUrl' => route('jobs.status', ['id' => $id]),
        ]);
    }

    /**
     * GET /jobs/{id}/status?offset=N: the job and its log from byte N, for
     * the Job page and the task panels (E15 spec §6.2). `log` is the chunk,
     * `offset` the offset of the next call, `size` the size of the log;
     * without N, the last 64 KiB of the log.
     */
    public function status(Request $request, string $id, Principal $principal, TaskPolicy $policy, JobStore $store): JsonResponse
    {
        $offset = $request->validate(['offset' => ['nullable', 'integer', 'min:0']])['offset'] ?? null;
        $job = $store->find($id, (int) $offset) ?? abort(404);
        // Without an offset: the end of the log (a panel that starts following)
        $size = (int) ($job['SIZE'] ?? 0);
        if ($offset === null && $size > self::TAIL_BYTES) {
            $job = $store->find($id, $size - self::TAIL_BYTES) ?? $job;
        }
        $task = JobMapper::fromRecord($job);
        abort_unless($policy->view($principal, (string) $task->user), 404);

        return response()->json(['task' => self::state($task, $job)])->header('Cache-Control', 'no-store');
    }

    /** GET /jobs/tray: the running jobs and the newest finished ones, for the tray. */
    public function tray(Principal $principal, TaskPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewAny($principal), 403);
        $visible = $policy->visible($principal);
        try {
            $active = $this->tasks->listVisible($visible['users'], $visible['system'], ['status' => ['queued', 'running']], 50, 0);
            $finished = $this->tasks->listVisible($visible['users'], $visible['system'], ['status' => ['succeeded', 'failed', 'cancelled']], self::TRAY_FINISHED, 0);
        } catch (JobIndexUnavailable|JobStoreException) {
            return response()->json(['running' => 0, 'items' => [], 'error' => true])->header('Cache-Control', 'no-store');
        }
        $items = array_map(fn (TaskView $task) => self::item($task), array_merge($active['items'], $finished['items']));

        return response()->json([
            'running' => count(array_filter($active['items'], fn (TaskView $task) => $task->status === 'running')),
            'items' => $items,
        ])->header('Cache-Control', 'no-store');
    }

    public function cancel(string $id, Principal $principal, TaskPolicy $policy, JobStore $store, JobIndex $index): RedirectResponse
    {
        $task = $this->visible($id, $principal, $policy, 0);
        abort_unless($policy->manage($principal, (string) $task->user), 403);
        if ($task->finished()) {
            return redirect()->route('jobs.show', ['id' => $id])->with('error', __('The job has finished and cannot be cancelled.'));
        }
        $result = $store->cancel($id, $principal->loginUser);
        $index->touch();

        return $result->ok()
            ? redirect()->route('jobs.show', ['id' => $id])->with('success', __('The job was cancelled.'))
            : redirect()->route('jobs.show', ['id' => $id])->with('error', self::refusal($result->exit(), __('The job could not be cancelled.')));
    }

    public function retry(string $id, Principal $principal, TaskPolicy $policy, JobStore $store, JobIndex $index): RedirectResponse
    {
        $task = $this->visible($id, $principal, $policy, 0);
        abort_unless($policy->manage($principal, (string) $task->user), 403);
        if (! $task->retryable || ! in_array($task->status, ['failed', 'cancelled'], true)) {
            return redirect()->route('jobs.show', ['id' => $id])->with('error', __('This job cannot be retried.'));
        }
        $result = $store->retry($id, $principal->loginUser);
        $new = $result->ok() ? JobStore::retriedId($result) : null;
        $index->touch();
        if ($new === null) {
            return redirect()->route('jobs.show', ['id' => $id])->with('error', self::refusal($result->exit(), __('The job could not be retried.')));
        }

        return redirect()->route('jobs.show', ['id' => $new])->with('success', __('The job runs again as a new job.'));
    }

    /** POST /jobs/rebuild: build the job index again from the job files (administrators). */
    public function rebuild(Principal $principal, TaskPolicy $policy, JobIndex $index): RedirectResponse
    {
        abort_unless($policy->viewSystem($principal), 403);
        try {
            $index->sync(true);
        } catch (JobIndexUnavailable|JobStoreException) {
            return redirect()->route('jobs.index')->with('error', __('The job list could not be rebuilt.'));
        }
        $index->touch();

        return redirect()->route('jobs.index')->with('success', __('The job list was rebuilt.'));
    }

    /**
     * The state of a job for the pollers: the API fields plus the type, the
     * log chunk and the offsets.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function state(TaskView $task, array $job): array
    {
        return [
            'id' => substr($task->id, strlen(TaskId::SOURCE_JOB) + 1),
            'type' => $task->type,
            'kind' => $task->kind,
            'user' => $task->user,
            'object' => $task->object,
            'args' => $task->args,
            'status' => $task->status,
            'finished' => $task->finished(),
            'exit_code' => $task->exitCode,
            'error' => $task->error,
            'queued_at' => $task->queuedAt,
            'started_at' => $task->startedAt,
            'finished_at' => $task->finishedAt,
            'log' => is_string($job['LOG'] ?? null) ? $job['LOG'] : '',
            'offset' => (int) ($job['OFFSET'] ?? 0),
            'size' => (int) ($job['SIZE'] ?? 0),
            'url' => route('jobs.show', ['id' => substr($task->id, strlen(TaskId::SOURCE_JOB) + 1)]),
        ];
    }

    /**
     * A link to the object of a job, when the panel has a page for it.
     *
     * @return array{label: string, url: string}|null
     */
    public static function objectLink(TaskView $task): ?array
    {
        $user = (string) $task->user;
        $object = (string) $task->object;
        $parts = explode(':', $object);
        $route = function (string $name, array $params) {
            return Route::has($name) ? route($name, $params) : null;
        };
        $url = match (true) {
            str_starts_with($object, 'deploy:') => $route('web.domains.edit', ['user' => $user, 'domain' => substr($object, 7)]),
            str_starts_with($object, 'web:') => $route('web.domains.edit', ['user' => $user, 'domain' => substr($object, 4)]),
            str_starts_with($object, 'mail:') => $route('mail.edit', ['user' => $user, 'domain' => substr($object, 5)]),
            $object === 'docker:engine' => $route('server.docker', []),
            $parts[0] === 'docker' && count($parts) === 3 => $route('docker.apps.show', ['user' => $parts[1], 'name' => $parts[2]]),
            $parts[0] === 'node' => $route('node.show', ['user' => $user]),
            $parts[0] === 'app-process' => $route('app-processes', []),
            $parts[0] === 'malware' => $route('malware.index', []),
            $parts[0] === 'user' && str_starts_with((string) $task->type, 'backup.') => $route('backups.index', []),
            default => null,
        };

        return $url === null ? null : ['label' => $object, 'url' => $url];
    }

    /** @return array<string, mixed> */
    private static function item(TaskView $task): array
    {
        $id = substr($task->id, strlen(TaskId::SOURCE_JOB) + 1);

        return [
            'id' => $id,
            'type' => $task->type,
            'object' => $task->object,
            'user' => $task->user,
            'status' => $task->status,
            'started_at' => $task->startedAt,
            'finished_at' => $task->finishedAt,
            'url' => route('jobs.show', ['id' => $id]),
        ];
    }

    private function visible(string $id, Principal $principal, TaskPolicy $policy, int $lines): TaskView
    {
        $task = $this->tasks->find(TaskId::job($id), $lines) ?? abort(404);
        abort_unless($policy->view($principal, (string) $task->user), 404);

        return $task;
    }

    private static function refusal(?ExitCode $exit, string $fallback): string
    {
        return match ($exit) {
            ExitCode::InUse => __('Another job of the same object is queued or running.'),
            ExitCode::Invalid => __('The job is not in a state that allows this.'),
            default => $fallback,
        };
    }

    /** @return array{status: string, type: string, user: string, period: string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$/'],
            'user' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'period' => ['nullable', 'in:day,week,month'],
        ]);

        return [
            'status' => (string) ($data['status'] ?? ''),
            'type' => (string) ($data['type'] ?? ''),
            'user' => (string) ($data['user'] ?? ''),
            'period' => (string) ($data['period'] ?? ''),
        ];
    }

    /**
     * @param  array{status: string, type: string, user: string, period: string}  $filters
     * @return array<string, string>
     */
    private function query(array $filters): array
    {
        $query = array_filter(['status' => $filters['status'], 'type' => $filters['type'], 'user' => $filters['user']], fn ($v) => $v !== '');
        $days = ['day' => 1, 'week' => 7, 'month' => 30][$filters['period']] ?? null;
        if ($days !== null) {
            $query['since'] = date('Y-m-d H:i:s', time() - $days * 86400);
        }

        return $query;
    }
}
