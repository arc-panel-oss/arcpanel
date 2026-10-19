<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Api\TaskId;
use App\Api\TaskLink;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\DockerEngineResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\DockerPolicy;
use App\Services\Docker\DockerEngineService;
use App\Services\Tasks\TaskMapper;
use Illuminate\Http\JsonResponse;

/**
 * Docker engine of the server (E34 batch 4 spec §4.4).
 *
 * @tags Server
 */
final class DockerEngineController extends Controller
{
    public function __construct(
        private readonly DockerEngineService $engine,
        private readonly PlanGate $gate,
    ) {}

    /**
     * Get the Docker engine
     *
     * @operationId getDockerEngine
     */
    public function show(Principal $principal, DockerPolicy $policy): DockerEngineResource
    {
        abort_unless($policy->manageEngine($principal), 403);
        $this->gate->authorize([], ['v-list-sys-docker']);

        return new DockerEngineResource($this->engine->status());
    }

    /**
     * Install the Docker engine
     *
     * @operationId installDockerEngine
     *
     * @monitor
     *
     * @status 202
     */
    public function install(Principal $principal, DockerPolicy $policy): TaskResource|JsonResponse
    {
        abort_unless($policy->manageEngine($principal), 403);
        $step = $this->engine->planInstall();
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $result = $this->engine->apply([$step]);
        if (! $result->ok()) {
            if ($result->exitCode === ExitCode::InUse->value) {
                $running = $this->engine->task();
                if ($running->id !== '') {
                    $taskId = new TaskId(TaskId::SOURCE_DOCKER_ENGINE, $running->id);

                    throw new ApiException(
                        ErrorCode::InUse,
                        $result->error !== '' ? $result->error : __('Another task is running.'),
                        409,
                        existing: '/api/v2/tasks/'.$taskId->apiId(),
                    );
                }
            }
            $this->gate->assertApplied($result);
        }
        $recordId = trim($result->stdoutOf('v-start-sys-docker-task') ?? '');
        if ($recordId === '') {
            throw new ApiException(ErrorCode::Server, __('The task could not be started.'), 500);
        }
        $this->engine->forgetConfigCache();
        $taskId = new TaskId(TaskId::SOURCE_DOCKER_ENGINE, $recordId);
        $view = TaskMapper::fromSource($taskId, [
            'ID' => $recordId,
            'ACTION' => 'install',
            'ARGS' => '',
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
}
