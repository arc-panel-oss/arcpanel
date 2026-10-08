<?php

namespace App\Api;

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Http\Resources\V2\TaskResource;
use App\Services\Server\SystemTaskService;
use App\Services\Support\PlanRunner;
use App\Services\Tasks\TaskMapper;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;

/**
 * Starts the system task of the server from API v2 (E34 batch 6 §5.4, §5.5,
 * §5.7): the step is checked against the key like any plan, a dry run lists
 * it, and a started task answers `202` with the task `sys:ID` and its
 * monitor `Link`. One system task runs at a time: a refusal because another
 * runs answers `409 E_INUSE` with `existing`, the path of the running task.
 */
final class SystemTaskStarter
{
    public function __construct(
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly SystemTaskService $tasks,
    ) {}

    /** @param  Step  $step  a step of SystemTaskService::step() */
    public function start(Step $step): JsonResponse
    {
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $result = $this->runner->apply([$step]);
        if (! $result->ok()) {
            if ($result->exitCode === ExitCode::InUse->value) {
                $running = $this->runningTask();

                throw new ApiException(
                    ErrorCode::InUse,
                    __('Another system task is running. Wait until it has finished.'),
                    409,
                    existing: $running !== null ? '/api/v2/tasks/'.$running->apiId() : null,
                );
            }
            $this->gate->assertApplied($result);
        }
        $recordId = trim($result->stdoutOf('v-start-sys-task') ?? '');
        if (preg_match('/^\d{14}$/', $recordId) !== 1) {
            throw new ApiException(ErrorCode::Server, __('The task could not be started.'), 500);
        }
        $taskId = new TaskId(TaskId::SOURCE_SYS, $recordId);
        $args = array_map('strval', $step->args);
        $view = TaskMapper::fromSource($taskId, [
            'ID' => $recordId,
            'KIND' => $args[0] ?? '',
            'ARGS' => implode(' ', array_slice($args, 1)),
            'STATUS' => 'running',
            'EXIT' => '',
            'START' => '',
            'END' => '',
            'LOG' => '',
        ], 1);

        return (new TaskResource($view))
            ->response()
            ->setStatusCode(202)
            ->header('Link', TaskLink::monitor($taskId->apiId()));
    }

    /** The task that blocks a start, when the key may read it. */
    private function runningTask(): ?TaskId
    {
        try {
            $id = $this->tasks->task()['id'];
        } catch (ApiException|ArcCommandException) {
            return null;
        }

        return preg_match('/^\d{14}$/', $id) === 1 ? new TaskId(TaskId::SOURCE_SYS, $id) : null;
    }
}
