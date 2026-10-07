<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\KeyPermissions;
use App\Api\PlanGate;
use App\Api\TaskId;
use App\Api\TaskLink;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\BuildNodeAppRequest;
use App\Http\Requests\Api\V2\SetNodeAppEnvRequest;
use App\Http\Requests\Api\V2\StoreNodeAppRequest;
use App\Http\Requests\Api\V2\UpdateNodeAppRequest;
use App\Http\Resources\V2\NodeAppResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\NodePolicy;
use App\Services\Node\NodeAccess;
use App\Services\Node\NodeAppActions;
use App\Services\Node\NodeAppService;
use App\Services\Node\NodeService;
use App\Services\Tasks\TaskMapper;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Node.js apps of a user (E34 batch 4 spec §4.2).
 *
 * @tags Node.js apps
 */
final class NodeAppController extends Controller
{
    public function __construct(
        private readonly NodeAppService $apps,
        private readonly NodeService $node,
        private readonly NodeAppActions $actions,
        private readonly NodeAccess $access,
        private readonly PlanGate $gate,
        private readonly TenancyDirectory $directory,
        private readonly KeyPermissions $key,
    ) {}

    /**
     * List the Node.js apps of a user
     *
     * @operationId listNodeApps
     */
    public function index(string $user, Principal $principal, NodePolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);
        $account = $this->apps->apps($user);

        return NodeAppResource::collection($account->apps)->additional([
            'meta' => [
                'daemon' => $account->daemon,
                'totals' => $account->totals(),
                'limit' => $account->limit,
                'room' => $account->hasRoom(),
            ],
        ]);
    }

    /**
     * Create a Node.js app
     *
     * @operationId createNodeApp
     *
     * @status 201
     */
    public function store(StoreNodeAppRequest $request, string $user, Principal $principal, NodePolicy $policy): NodeAppResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $domain = strtolower((string) $request->input('domain'));
        $payload = $this->actions->createPayload($request->validated());
        $steps = [$this->apps->planCreate($user, $domain, $payload)];
        if ($preview = $this->gate->execute($steps, $this->apps->apply(...))) {
            return $preview;
        }

        return (new NodeAppResource($this->apps->show($user, $domain)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.node-apps.show', ['user' => $user, 'domain' => $domain]));
    }

    /**
     * Detect what package.json proposes for an app
     *
     * @operationId detectNodeApp
     */
    public function detect(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);
        $cwd = (string) ($request->validate(['cwd' => ['nullable', 'string', 'regex:'.NodeAppService::PATH]])['cwd'] ?? 'public_html');
        $proposal = $this->apps->detect($user, $domain, $cwd ?: 'public_html');

        return response()->json(['data' => [
            'package_json' => $proposal['packageJson'],
            'start' => $proposal['start'],
            'template' => $proposal['template'],
            'scripts' => $proposal['scripts'],
        ]]);
    }

    /**
     * Get a Node.js app
     *
     * @operationId getNodeApp
     */
    public function show(string $user, string $domain, Principal $principal, NodePolicy $policy): NodeAppResource
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);

        return new NodeAppResource($this->apps->show($user, $domain));
    }

    /**
     * Update a Node.js app
     *
     * @operationId updateNodeApp
     */
    public function update(UpdateNodeAppRequest $request, string $user, string $domain, Principal $principal, NodePolicy $policy): NodeAppResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $domain);
        $data = $request->validated();
        $steps = [];
        if (array_key_exists('start', $data)) {
            $steps[] = $this->apps->planChangeStart($user, $domain, (string) $data['start'], (string) $data['cwd']);
        }
        if (array_key_exists('version', $data)) {
            $steps[] = $this->apps->planChangeVersion($user, $domain, (string) $data['version']);
        }
        if (array_key_exists('instances', $data)) {
            $steps[] = $this->apps->planScale($user, $domain, (int) $data['instances'], (int) $data['memory_mb']);
        }
        if ($steps === []) {
            return new NodeAppResource($this->apps->show($user, $domain));
        }
        if ($preview = $this->gate->execute($steps, $this->apps->apply(...))) {
            return $preview;
        }

        return new NodeAppResource($this->apps->show($user, $domain));
    }

    /**
     * Delete a Node.js app
     *
     * @operationId deleteNodeApp
     *
     * @status 204
     */
    public function destroy(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->removeApps($principal, $user), 403);
        $this->apps->show($user, $domain);

        return $this->gate->execute([
            $this->apps->planDelete($user, $domain, $request->boolean('keep_files')),
        ], $this->apps->apply(...)) ?? response()->noContent();
    }

    /**
     * Start a Node.js app
     *
     * @operationId startNodeApp
     */
    public function start(string $user, string $domain, Principal $principal, NodePolicy $policy): NodeAppResource|JsonResponse
    {
        return $this->runAction('start', $user, $domain, $principal, $policy);
    }

    /**
     * Stop a Node.js app
     *
     * @operationId stopNodeApp
     */
    public function stop(string $user, string $domain, Principal $principal, NodePolicy $policy): NodeAppResource|JsonResponse
    {
        return $this->runAction('stop', $user, $domain, $principal, $policy, removeOnly: true);
    }

    /**
     * Restart a Node.js app
     *
     * @operationId restartNodeApp
     */
    public function restart(string $user, string $domain, Principal $principal, NodePolicy $policy): NodeAppResource|JsonResponse
    {
        return $this->runAction('restart', $user, $domain, $principal, $policy);
    }

    /**
     * Reload a Node.js app
     *
     * @operationId reloadNodeApp
     */
    public function reload(string $user, string $domain, Principal $principal, NodePolicy $policy): NodeAppResource|JsonResponse
    {
        return $this->runAction('reload', $user, $domain, $principal, $policy);
    }

    /**
     * Install npm dependencies of a Node.js app
     *
     * @operationId installNodeApp
     *
     * @status 202
     */
    public function install(string $user, string $domain, Principal $principal, NodePolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $domain);

        return $this->startAppTask($user, $domain, 'app-install', [$domain]);
    }

    /**
     * Run an npm script on a Node.js app
     *
     * @operationId buildNodeApp
     *
     * @status 202
     */
    public function build(BuildNodeAppRequest $request, string $user, string $domain, Principal $principal, NodePolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $domain);
        $script = (string) ($request->input('script') ?? 'build');

        return $this->startAppTask($user, $domain, 'app-build', [$domain, $script ?: 'build']);
    }

    /**
     * List the environment of a Node.js app
     *
     * @operationId getNodeAppEnv
     */
    public function env(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manageApps($principal, $user) || $policy->removeApps($principal, $user), 403);
        $this->apps->show($user, $domain);
        $reveal = $request->boolean('reveal');
        if ($reveal) {
            abort_unless($policy->manageApps($principal, $user), 403);
            abort_unless($this->mayRevealEnv(), 403);
        }
        $vars = $this->apps->env($user, $domain);

        return response()->json(['data' => $this->actions->envEntries($vars, $reveal)]);
    }

    /**
     * Replace the environment of a Node.js app
     *
     * @operationId setNodeAppEnv
     */
    public function setEnv(SetNodeAppEnvRequest $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManageApps($principal, $user, $policy);
        $this->apps->show($user, $domain);
        $current = $this->apps->env($user, $domain);
        $vars = $this->actions->mergeEnvEntries($request->input('entries', []), $current);
        $steps = [$this->apps->planSaveEnv($user, $domain, $vars)];
        if ($preview = $this->gate->execute($steps, $this->apps->apply(...))) {
            return $preview;
        }
        $reveal = $this->mayRevealEnv();

        return response()->json(['data' => $this->actions->envEntries($this->apps->env($user, $domain), $reveal)]);
    }

    /**
     * Read a snapshot of Node.js app logs
     *
     * @operationId getNodeAppLogs
     */
    public function logs(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->viewApps($principal, $user), 403);
        $this->apps->show($user, $domain);
        $data = $request->validate($this->actions->logRules());
        $offset = $this->actions->logOffset($request);
        $snapshot = $this->apps->logs(
            $user,
            $domain,
            (string) ($data['stream'] ?? 'out'),
            $offset,
            (int) ($data['lines'] ?? 200),
        );
        $body = $this->actions->logResponse($snapshot);

        return response()->json(['data' => ['lines' => $body['lines']], 'meta' => $body['meta']]);
    }

    private function runAction(string $action, string $user, string $domain, Principal $principal, NodePolicy $policy, bool $removeOnly = false): NodeAppResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($removeOnly ? $policy->removeApps($principal, $user) : $policy->manageApps($principal, $user), 403);
        if (! $removeOnly) {
            $this->assertManageApps($principal, $user, $policy);
        }
        $this->apps->show($user, $domain);
        if ($preview = $this->gate->execute([$this->apps->planAction($user, $domain, $action)], $this->apps->apply(...))) {
            return $preview;
        }

        return new NodeAppResource($this->apps->show($user, $domain));
    }

    /**
     * @param  list<string>  $args
     */
    private function startAppTask(string $user, string $domain, string $action, array $args): TaskResource|JsonResponse
    {
        $step = $this->node->planStartTask($user, $action, $args);
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $result = $this->node->apply([$step]);
        if (! $result->ok()) {
            if ($result->exitCode === ExitCode::InUse->value) {
                $running = $this->node->task($user);
                if ($running->id !== '') {
                    $taskId = new TaskId(TaskId::SOURCE_NODE, $running->id, $user);

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
        $recordId = trim($result->stdoutOf('v-start-user-node-task') ?? '');
        if ($recordId === '') {
            throw new ApiException(ErrorCode::Server, __('The task could not be started.'), 500);
        }
        $taskId = new TaskId(TaskId::SOURCE_NODE, $recordId, $user);
        $view = TaskMapper::fromSource($taskId, [
            'ID' => $recordId,
            'ACTION' => $action,
            'ARGS' => implode(' ', $args),
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

    private function mayRevealEnv(): bool
    {
        if ($this->key->unrestricted()) {
            return true;
        }

        return in_array('v2-write', $this->key->sets, true) || in_array('v2-apps', $this->key->sets, true);
    }

    private function assertOwner(string $user, Principal $principal, NodePolicy $policy): void
    {
        abort_unless($policy->viewApps($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }

    private function assertManageApps(Principal $principal, string $user, NodePolicy $policy): void
    {
        if ($policy->manageApps($principal, $user)) {
            return;
        }
        if ($policy->removeApps($principal, $user) && ! $this->access->allowed($user)) {
            throw new ApiException(ErrorCode::Forbidden, __('Node.js is not included in your package.'), 403);
        }
        abort(403);
    }
}
