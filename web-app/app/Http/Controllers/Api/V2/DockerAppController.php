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
use App\Http\Requests\Api\V2\SetDockerAppDomainRequest;
use App\Http\Requests\Api\V2\SetDockerAppEnvRequest;
use App\Http\Requests\Api\V2\SetDockerAppLimitsRequest;
use App\Http\Requests\Api\V2\StartDockerAppTaskRequest;
use App\Http\Requests\Api\V2\StoreDockerAppRequest;
use App\Http\Resources\V2\DockerAppResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\DockerPolicy;
use App\Services\Docker\DockerAccess;
use App\Services\Docker\DockerAppActions;
use App\Services\Docker\DockerAppService;
use App\Services\Docker\DockerCatalog;
use App\Services\Tasks\TaskMapper;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Docker apps of a user (E34 batch 4 spec §4.3).
 *
 * @tags Docker apps
 */
final class DockerAppController extends Controller
{
    public function __construct(
        private readonly DockerAppService $apps,
        private readonly DockerCatalog $catalog,
        private readonly DockerAppActions $actions,
        private readonly DockerAccess $access,
        private readonly PlanGate $gate,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * List the Docker apps of a user
     *
     * @operationId listDockerApps
     */
    public function index(string $user, Principal $principal, DockerPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);
        $account = $this->apps->apps($user);

        return DockerAppResource::collection($account->apps)->additional([
            'meta' => [
                'engine' => $account->engine,
                'totals' => $account->totals(),
                'limit' => $account->limit,
                'room' => $account->hasRoom(),
            ],
        ]);
    }

    /**
     * Install a Docker app
     *
     * @operationId createDockerApp
     *
     * @status 202
     */
    public function store(StoreDockerAppRequest $request, string $user, Principal $principal, DockerPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $data = $request->validated();
        $entry = $this->catalog->find((string) $data['slug']);
        abort_if($entry === null, 404);
        abort_unless($this->actions->catalogAppVisible($entry, $policy->installAdminOnly($principal)), 404);
        $name = (string) $data['name'];
        $vars = $this->actions->checkedVars((array) ($data['vars'] ?? []), true);
        $step = $this->apps->planInstall(
            $user,
            (string) $data['slug'],
            $name,
            strtolower((string) ($data['domain'] ?? '')),
            $vars,
            (string) ($data['version'] ?? ''),
        );

        return $this->runTask($user, $name, $step, 'v-add-docker-app', 'install',
            route('api.v2.docker-apps.show', ['user' => $user, 'name' => $name]));
    }

    /**
     * Get a Docker app
     *
     * @operationId getDockerApp
     */
    public function show(string $user, string $name, Principal $principal, DockerPolicy $policy): DockerAppResource
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);

        return new DockerAppResource($this->apps->show($user, $name));
    }

    /**
     * Delete a Docker app
     *
     * @operationId deleteDockerApp
     *
     * @status 204
     */
    public function destroy(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->removeApps($principal, $user), 403);
        $this->apps->show($user, $name);

        return $this->gate->execute([
            $this->apps->planDelete($user, $name, $request->boolean('keep_data')),
        ], $this->apps->apply(...)) ?? response()->noContent();
    }

    /**
     * Start a long-running Docker app task
     *
     * @operationId startDockerAppTask
     *
     * @status 202
     */
    public function startTask(StartDockerAppTaskRequest $request, string $user, string $name, Principal $principal, DockerPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $name);
        $data = $request->validated();
        $step = $this->apps->planStartTask($user, $name, (string) $data['action'], (string) ($data['version'] ?? ''));

        return $this->runTask($user, $name, $step, 'v-start-docker-app-task', (string) $data['action']);
    }

    /**
     * Start a Docker app
     *
     * @operationId startDockerApp
     */
    public function start(string $user, string $name, Principal $principal, DockerPolicy $policy): DockerAppResource|JsonResponse
    {
        return $this->runAction('start', $user, $name, $principal, $policy);
    }

    /**
     * Stop a Docker app
     *
     * @operationId stopDockerApp
     */
    public function stop(string $user, string $name, Principal $principal, DockerPolicy $policy): DockerAppResource|JsonResponse
    {
        return $this->runAction('stop', $user, $name, $principal, $policy, removeOnly: true);
    }

    /**
     * Restart a Docker app
     *
     * @operationId restartDockerApp
     */
    public function restart(string $user, string $name, Principal $principal, DockerPolicy $policy): DockerAppResource|JsonResponse
    {
        return $this->runAction('restart', $user, $name, $principal, $policy);
    }

    /**
     * List the environment of a Docker app
     *
     * @operationId getDockerAppEnv
     */
    public function env(string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);
        $this->apps->show($user, $name);

        return response()->json(['data' => $this->actions->envEntries($this->apps->env($user, $name))]);
    }

    /**
     * Replace the environment of a Docker app
     *
     * @operationId setDockerAppEnv
     */
    public function setEnv(SetDockerAppEnvRequest $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $name);
        $vars = $this->actions->checkedVars((array) $request->input('vars', []), false);
        $steps = [$this->apps->planSaveEnv($user, $name, $vars)];
        if ($preview = $this->gate->execute($steps, $this->apps->apply(...))) {
            return $preview;
        }

        return response()->json(['data' => $this->actions->envEntries($this->apps->env($user, $name))]);
    }

    /**
     * Change the resource limits of a Docker app
     *
     * @operationId setDockerAppLimits
     */
    public function limits(SetDockerAppLimitsRequest $request, string $user, string $name, Principal $principal, DockerPolicy $policy): DockerAppResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $name);
        $data = $request->validated();
        if ($preview = $this->gate->execute([
            $this->apps->planLimits($user, $name, (string) $data['cpus'], (int) $data['memory_mb']),
        ], $this->apps->apply(...))) {
            return $preview;
        }

        return new DockerAppResource($this->apps->show($user, $name));
    }

    /**
     * Change the domain of a Docker app
     *
     * @operationId setDockerAppDomain
     */
    public function domain(SetDockerAppDomainRequest $request, string $user, string $name, Principal $principal, DockerPolicy $policy): DockerAppResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $name);
        $domain = strtolower((string) $request->input('domain'));
        if ($preview = $this->gate->execute([
            $this->apps->planDomain($user, $name, $domain),
        ], $this->apps->apply(...))) {
            return $preview;
        }

        return new DockerAppResource($this->apps->show($user, $name));
    }

    /**
     * Read a snapshot of Docker app logs
     *
     * @operationId getDockerAppLogs
     */
    public function logs(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);
        $this->apps->show($user, $name);
        $data = $request->validate($this->actions->logRules());
        $since = $this->actions->logSince($request);
        $snapshot = $this->apps->logs(
            $user,
            $name,
            (string) ($data['service'] ?? 'all'),
            $since,
            (int) ($data['lines'] ?? 200),
        );
        $body = $this->actions->logResponse($snapshot);

        return response()->json(['data' => ['lines' => $body['lines']], 'meta' => $body['meta']]);
    }

    private function runAction(string $action, string $user, string $name, Principal $principal, DockerPolicy $policy, bool $removeOnly = false): DockerAppResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($removeOnly ? $policy->removeApps($principal, $user) : $policy->manageApps($principal, $user), 403);
        if (! $removeOnly) {
            $this->assertManageApps($principal, $user, $policy);
        }
        $this->apps->show($user, $name);
        if ($preview = $this->gate->execute([$this->apps->planAction($user, $name, $action)], $this->apps->apply(...))) {
            return $preview;
        }

        return new DockerAppResource($this->apps->show($user, $name));
    }

    private function runTask(string $user, string $name, Step $step, string $command, string $action, ?string $location = null): TaskResource|JsonResponse
    {
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $result = $this->apps->apply([$step]);
        if (! $result->ok()) {
            if ($result->exitCode === ExitCode::InUse->value) {
                $running = $this->apps->task($user, $name);
                if ($running->id !== '') {
                    $taskId = new TaskId(TaskId::SOURCE_DOCKER, $running->id, $user, $name);

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
        $recordId = trim($result->stdoutOf($command) ?? '');
        if ($recordId === '') {
            throw new ApiException(ErrorCode::Server, __('The task could not be started.'), 500);
        }
        $taskId = new TaskId(TaskId::SOURCE_DOCKER, $recordId, $user, $name);
        $view = TaskMapper::fromSource($taskId, [
            'ID' => $recordId,
            'ACTION' => $action,
            'ARGS' => $name,
            'STATUS' => 'running',
            'EXIT' => '',
            'START' => '',
            'END' => '',
            'LOG' => '',
        ], 1);
        $response = (new TaskResource($view))
            ->response()
            ->setStatusCode(202)
            ->header('Link', TaskLink::monitor($taskId->apiId()));
        if ($location !== null) {
            $response->header('Location', $location);
        }

        return $response;
    }

    private function assertOwner(string $user, Principal $principal, DockerPolicy $policy): void
    {
        abort_unless($policy->viewApps($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }

    private function assertManageApps(Principal $principal, string $user, DockerPolicy $policy): void
    {
        if ($policy->manageApps($principal, $user)) {
            return;
        }
        if ($policy->removeApps($principal, $user) && ! $this->access->allowed($user)) {
            throw new ApiException(ErrorCode::Forbidden, __('Docker apps are not included in your package.'), 403);
        }
        abort(403);
    }
}
