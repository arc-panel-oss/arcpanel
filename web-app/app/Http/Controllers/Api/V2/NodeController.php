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
use App\Http\Requests\Api\V2\InstallNodePackageRequest;
use App\Http\Requests\Api\V2\InstallNodeVersionRequest;
use App\Http\Requests\Api\V2\SetNodeEnabledRequest;
use App\Http\Resources\V2\NodeAccountResource;
use App\Http\Resources\V2\NodePackageResource;
use App\Http\Resources\V2\NodeReleaseResource;
use App\Http\Resources\V2\NodeRuntimeResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\NodePolicy;
use App\Services\Node\NodeAccess;
use App\Services\Node\NodeService;
use App\Services\Tasks\TaskMapper;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Node.js runtimes and global packages of a user (E34 batch 4 spec §4.1).
 *
 * @tags Node.js
 */
final class NodeController extends Controller
{
    public function __construct(
        private readonly NodeService $node,
        private readonly NodeAccess $access,
        private readonly PlanGate $gate,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * Get the Node.js account of a user
     *
     * @operationId getNodeAccount
     */
    public function show(string $user, Principal $principal, NodePolicy $policy): NodeAccountResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new NodeAccountResource($this->node->account($user));
    }

    /**
     * Enable or remove Node.js for a user
     *
     * @operationId setNodeEnabled
     */
    public function setEnabled(SetNodeEnabledRequest $request, string $user, Principal $principal, NodePolicy $policy): NodeAccountResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $account = $this->node->account($user);
        $enabled = $request->boolean('enabled');
        $steps = $account->enabled === $enabled ? [] : [$this->node->planEnable($user, $enabled)];

        return $this->gate->execute($steps, $this->node->apply(...))
            ?? new NodeAccountResource($this->node->account($user));
    }

    /**
     * List installable Node.js majors
     *
     * @operationId listNodeReleases
     */
    public function releases(string $user, Principal $principal, NodePolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $account = $this->node->account($user);

        return NodeReleaseResource::collection($this->node->releases($account));
    }

    /**
     * List the installed Node.js runtimes of a user
     *
     * @operationId listNodeVersions
     */
    public function versions(string $user, Principal $principal, NodePolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);

        return NodeRuntimeResource::collection($this->node->account($user)->runtimes);
    }

    /**
     * Install a Node.js runtime
     *
     * @operationId installNodeVersion
     *
     * @status 202
     */
    public function installVersion(InstallNodeVersionRequest $request, string $user, Principal $principal, NodePolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $spec = (string) $request->input('spec');

        return $this->startTask($user, 'add-version', [$spec]);
    }

    /**
     * Set the default Node.js runtime
     *
     * @operationId setNodeDefaultVersion
     */
    public function setDefault(string $user, string $version, Principal $principal, NodePolicy $policy): NodeAccountResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->assertVersion($user, $version);
        $account = $this->node->account($user);
        $steps = $account->default === $version ? [] : [$this->node->planSetDefault($user, $version)];

        return $this->gate->execute($steps, $this->node->apply(...))
            ?? new NodeAccountResource($this->node->account($user));
    }

    /**
     * Uninstall a Node.js runtime
     *
     * @operationId deleteNodeVersion
     *
     * @status 204
     */
    public function destroyVersion(string $user, string $version, Principal $principal, NodePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->remove($principal, $user), 403);
        $this->assertVersion($user, $version);

        return $this->gate->execute([
            $this->node->planDeleteVersion($user, $version),
        ], $this->node->apply(...)) ?? response()->noContent();
    }

    /**
     * List the global npm packages of a runtime
     *
     * @operationId listNodePackages
     */
    public function packages(string $user, string $version, Principal $principal, NodePolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertVersion($user, $version);
        $data = $this->node->packages($user, $version);

        return response()->json([
            'data' => NodePackageResource::collection($data['packages']),
            'meta' => ['checked_at' => $data['checked'] !== '' ? $data['checked'] : null],
        ]);
    }

    /**
     * Install a global npm package
     *
     * @operationId installNodePackage
     *
     * @status 202
     */
    public function installPackage(InstallNodePackageRequest $request, string $user, string $version, Principal $principal, NodePolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->assertVersion($user, $version);
        $spec = (string) $request->input('spec');

        return $this->startTask($user, 'add-package', [$version, $spec]);
    }

    /**
     * Update every global npm package of a runtime
     *
     * @operationId updateNodePackages
     *
     * @status 202
     */
    public function updatePackages(string $user, string $version, Principal $principal, NodePolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->assertVersion($user, $version);

        return $this->startTask($user, 'update-packages', [$version]);
    }

    /**
     * Check global npm packages for updates
     *
     * @operationId checkNodePackages
     *
     * @status 202
     */
    public function checkPackages(string $user, string $version, Principal $principal, NodePolicy $policy): TaskResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->assertVersion($user, $version);

        return $this->startTask($user, 'check-outdated', [$version]);
    }

    /**
     * Remove a global npm package
     *
     * @operationId deleteNodePackage
     *
     * @status 204
     */
    public function destroyPackage(string $user, string $version, string $package, Principal $principal, NodePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->remove($principal, $user), 403);
        $this->assertVersion($user, $version);
        abort_unless(preg_match(NodeService::PACKAGE_NAME, $package) === 1, 404);

        return $this->gate->execute([
            $this->node->planDeletePackage($user, $version, $package),
        ], $this->node->apply(...)) ?? response()->noContent();
    }

    /**
     * @param  list<string>  $args
     */
    private function startTask(string $user, string $action, array $args): TaskResource|JsonResponse
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

    private function assertOwner(string $user, Principal $principal, NodePolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }

    private function assertManage(Principal $principal, string $user, NodePolicy $policy): void
    {
        if ($policy->manage($principal, $user)) {
            return;
        }
        if ($policy->remove($principal, $user) && ! $this->access->allowed($user)) {
            throw new ApiException(ErrorCode::Forbidden, __('Node.js is not included in your package.'), 403);
        }
        abort(403);
    }

    private function assertVersion(string $user, string $version): void
    {
        abort_unless(preg_match(NodeService::VERSION, $version) === 1, 404);
        abort_unless($this->node->account($user)->has($version), 404);
    }
}
