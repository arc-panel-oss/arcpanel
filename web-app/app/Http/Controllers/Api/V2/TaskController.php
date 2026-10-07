<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\TaskId;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\GetTaskRequest;
use App\Http\Resources\V2\TaskResource;
use App\Policies\TaskPolicy;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Background tasks (E34 batch 3 spec §5).
 *
 * @tags Tasks
 */
final class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * Show a task
     *
     * @operationId getTask
     */
    public function show(GetTaskRequest $request, string $id, Principal $principal, TaskPolicy $policy): TaskResource|JsonResponse
    {
        $taskId = TaskId::parse($id) ?? abort(404);
        abort_unless($policy->view($principal, $taskId), 404);
        if ($taskId->user !== null && ! $this->tasks->userExists($taskId->user)) {
            abort(404);
        }

        return new TaskResource($this->tasks->get($taskId, $request->lines()));
    }

    /**
     * List the tasks of a user
     *
     * Node.js, Quick Install, Docker apps and queue tasks of the last seven
     * days, newest first.
     *
     * @operationId listUserTasks
     */
    public function index(string $user, Principal $principal, TaskPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewUser($principal, $user) && $this->tasks->userExists($user), 404);

        return TaskResource::collection($this->tasks->listForUser($user, 100));
    }

    /**
     * List the system tasks
     *
     * The current system task and Docker engine task.
     *
     * @operationId listSystemTasks
     */
    public function system(Principal $principal, TaskPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewSystem($principal), 404);

        return TaskResource::collection($this->tasks->listSystem(100));
    }
}
