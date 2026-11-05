<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\TaskId;
use App\Api\TaskLink;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\GetTaskRequest;
use App\Http\Requests\Api\V2\ListTasksRequest;
use App\Http\Resources\V2\TaskResource;
use App\Policies\TaskPolicy;
use App\Services\Jobs\JobIndex;
use App\Services\Jobs\JobStore;
use App\Services\Tasks\TaskService;
use App\Services\Tasks\TaskView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Background tasks (E34 batch 3 spec §5), since E15 the jobs of the Job
 * Center (docs/features/specs/2026-11-05-001-e15-job-center-design.md §7).
 *
 * @tags Tasks
 */
final class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly Paginates $pages,
    ) {}

    /**
     * Show a task
     *
     * `id` is `job:ID` or an id of the task sources before E15 (`sys:ID`,
     * `queue:USER:ID`, `docker:USER:APP:ID`, …), which resolves to the job
     * with the same 14 digits. A job older than the retention answers 404.
     *
     * @operationId getTask
     */
    public function show(GetTaskRequest $request, string $id, Principal $principal, TaskPolicy $policy): TaskResource|JsonResponse
    {
        $task = $this->visibleTask($id, $principal, $policy, $request->lines());

        return new TaskResource($task);
    }

    /**
     * List tasks
     *
     * The jobs the key can see, newest first: the jobs of the users in its
     * owner scope and, for an administrator, the jobs of the server. Filters
     * `status`, `type` (a type or `prefix.*`), `user` and `since` (a time,
     * against `queued_at`). Paged with `limit` and `offset` (D20). The list
     * may lag the jobs by a few seconds; `GET /tasks/{id}` is always current.
     *
     * @operationId listTasks
     */
    public function all(ListTasksRequest $request, Principal $principal, TaskPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 404);
        ['limit' => $limit, 'offset' => $offset] = $this->pages->window($request);
        $visible = $policy->visible($principal);
        $page = $this->tasks->listVisible($visible['users'], $visible['system'], $request->filters(), $limit, $offset);
        $meta = ['total' => $page['total']];
        if ($limit !== null) {
            $meta += ['limit' => $limit, 'offset' => $offset];
        }

        return TaskResource::collection($page['items'])->additional(['meta' => $meta]);
    }

    /**
     * List the tasks of a user
     *
     * The jobs of the user, newest first. Paged with `limit` and `offset`
     * (D20).
     *
     * @operationId listUserTasks
     */
    public function index(Request $request, string $user, Principal $principal, TaskPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewUser($principal, $user) && $this->tasks->userExists($user), 404);
        $page = $this->pages->slice($request, $this->tasks->listForUser($user));

        return TaskResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the system tasks
     *
     * The jobs of the server (system tasks, the Docker engine, backup job
     * runs), newest first.
     *
     * @operationId listSystemTasks
     */
    public function system(Principal $principal, TaskPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewSystem($principal), 404);

        return TaskResource::collection($this->tasks->listSystem());
    }

    /**
     * Cancel a task
     *
     * A queued job is cancelled at once; a running one is stopped (its unit,
     * KILL after 10 seconds). 409 `E_INVALID` when it has finished.
     *
     * @operationId cancelTask
     *
     * @monitor
     */
    public function cancel(string $id, Principal $principal, TaskPolicy $policy, JobStore $store, JobIndex $index): JsonResponse
    {
        $task = $this->visibleTask($id, $principal, $policy, 0);
        abort_unless($policy->manage($principal, (string) $task->user), 403);
        if ($task->finished()) {
            throw new ApiException(ErrorCode::Invalid, sprintf('The task is %s and cannot be cancelled.', $task->status), 409);
        }
        $result = $store->cancel(self::jobId($task), self::actor($principal));
        if (! $result->ok()) {
            throw self::refusal($result->exit(), $result->errorMessage());
        }
        $index->touch();
        $view = $this->tasks->get(TaskId::job(self::jobId($task)), 0);

        return (new TaskResource($view))->response()->setStatusCode(202)->header('Link', TaskLink::monitor($view->id));
    }

    /**
     * Retry a task
     *
     * A failed or cancelled job of a retryable type runs again as a new job
     * with the same command (`retry_of` names this one). 409 when the type
     * cannot be retried, the job has not finished, or a job of the same
     * object is queued or running.
     *
     * @operationId retryTask
     *
     * @monitor
     */
    public function retry(string $id, Principal $principal, TaskPolicy $policy, JobStore $store, JobIndex $index): JsonResponse
    {
        $task = $this->visibleTask($id, $principal, $policy, 0);
        abort_unless($policy->manage($principal, (string) $task->user), 403);
        if (! $task->retryable) {
            throw new ApiException(ErrorCode::Invalid, 'Tasks of this kind cannot be retried.', 409);
        }
        if (! in_array($task->status, ['failed', 'cancelled'], true)) {
            throw new ApiException(ErrorCode::Invalid, sprintf('The task is %s and cannot be retried.', $task->status), 409);
        }
        $result = $store->retry(self::jobId($task), self::actor($principal));
        $new = $result->ok() ? JobStore::retriedId($result) : null;
        if ($new === null) {
            throw self::refusal($result->exit(), $result->errorMessage());
        }
        $index->touch();
        $view = $this->tasks->get(TaskId::job($new), 0);

        return (new TaskResource($view))->response()->setStatusCode(202)->header('Link', TaskLink::monitor($view->id));
    }

    /** The task of ID in the principal's scope; 404 otherwise. */
    private function visibleTask(string $id, Principal $principal, TaskPolicy $policy, int $lines): TaskView
    {
        $taskId = TaskId::parse($id) ?? abort(404);
        $task = $this->tasks->find($taskId, $lines) ?? abort(404);
        abort_unless($policy->view($principal, (string) $task->user), 404);

        return $task;
    }

    private static function jobId(TaskView $task): string
    {
        return substr($task->id, strlen(TaskId::SOURCE_JOB) + 1);
    }

    /** ACTOR of the job commands: the login, or `api:<key id>` */
    private static function actor(Principal $principal): string
    {
        return JobStore::actor($principal->accessKey !== null ? 'api:'.$principal->accessKey : $principal->loginUser);
    }

    private static function refusal(?ExitCode $exit, string $message): ApiException
    {
        return match ($exit) {
            ExitCode::Invalid, ExitCode::InUse => new ApiException($exit === ExitCode::InUse ? ErrorCode::InUse : ErrorCode::Invalid, $message, 409),
            ExitCode::NotExist => new ApiException(ErrorCode::NotExist, 'Not found.', 404),
            default => new ApiException(ErrorCode::Server, 'The task could not be changed.', 500),
        };
    }
}
