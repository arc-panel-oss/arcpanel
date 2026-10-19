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
use App\Http\Requests\Api\V2\SetAppProcessEnvRequest;
use App\Http\Requests\Api\V2\StartAppProcessTaskRequest;
use App\Http\Requests\Api\V2\StoreAppProcessRequest;
use App\Http\Requests\Api\V2\UpdateAppProcessRequest;
use App\Http\Resources\V2\AppProcessResource;
use App\Policies\AppProcessPolicy;
use App\Services\AppProcesses\AppProcessAccess;
use App\Services\AppProcesses\AppProcessActions;
use App\Services\AppProcesses\AppProcessService;
use App\Services\AppProcesses\AppProcessTask;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * App processes of a user (E42 spec §8): long-running commands of a web
 * domain run as the user by systemd.
 *
 * @tags App processes
 */
final class AppProcessController extends Controller
{
    public function __construct(
        private readonly AppProcessService $processes,
        private readonly AppProcessActions $actions,
        private readonly AppProcessAccess $access,
        private readonly PlanGate $gate,
        private readonly TenancyDirectory $directory,
        private readonly KeyPermissions $key,
    ) {}

    /**
     * List the app processes of a user
     *
     * @operationId listAppProcesses
     */
    public function index(string $user, Principal $principal, AppProcessPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $account = $this->processes->processes($user);

        return AppProcessResource::collection($account->processes)->additional([
            'meta' => [
                'allowed' => $account->allowed,
                'totals' => $account->totals(),
                'limit' => $account->limit,
                'room' => $account->hasRoom(),
                'max_memory_mb' => $account->maxMemory,
                'default_memory_mb' => $account->defaultMemory,
                'max_cpus' => $account->maxCpus,
            ],
        ]);
    }

    /**
     * List the presets of app processes
     *
     * With `domain`, each preset says whether its files were found in `cwd`
     * of that domain and why its runtime is missing.
     *
     * @operationId getAppProcessPresets
     */
    public function presets(Request $request, string $user, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
            'cwd' => ['nullable', 'string', 'regex:'.AppProcessService::PATH],
        ]);
        $domain = isset($data['domain']) ? strtolower((string) $data['domain']) : null;
        $presets = $this->processes->presets($domain === null ? null : $user, $domain, (string) ($data['cwd'] ?? 'public_html'));

        return response()->json(['data' => array_map(fn (array $preset) => [
            'name' => $preset['name'],
            'title' => $preset['title'],
            'kind' => $preset['kind'],
            'template' => $preset['template'] !== '' ? $preset['template'] : null,
            'runtime' => $preset['runtime'],
            'start' => $preset['start'],
            'reload' => $preset['reload'],
            'detected' => $preset['detected'] === 'yes',
            'runtime_missing' => $preset['runtimeMissing'] !== '' ? $preset['runtimeMissing'] : null,
            'template_available' => $preset['templateAvailable'] !== 'no',
        ], $presets)]);
    }

    /**
     * Create an app process
     *
     * @operationId createAppProcess
     *
     * @status 201
     */
    public function store(StoreAppProcessRequest $request, string $user, Principal $principal, AppProcessPolicy $policy): AppProcessResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $domain = strtolower((string) $request->input('domain'));
        $name = (string) $request->input('name');
        $steps = [$this->processes->planCreate($user, $domain, $this->actions->createPayload($request->validated()))];
        if ($preview = $this->gate->execute($steps, $this->processes->apply(...))) {
            return $preview;
        }

        return (new AppProcessResource($this->processes->show($user, $domain, $name)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.app-processes.show', ['user' => $user, 'domain' => $domain, 'name' => $name]));
    }

    /**
     * Get an app process
     *
     * @operationId getAppProcess
     */
    public function show(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): AppProcessResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new AppProcessResource($this->processes->show($user, $domain, $name));
    }

    /**
     * Update an app process
     *
     * Changes the command (with `cwd`) and the limits (`memory_mb` with
     * `cpus`); a running process is restarted.
     *
     * @operationId updateAppProcess
     */
    public function update(UpdateAppProcessRequest $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): AppProcessResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->processes->show($user, $domain, $name);
        $data = $request->validated();
        $steps = [];
        if (array_key_exists('start', $data)) {
            $steps[] = $this->processes->planChangeStart($user, $domain, $name, (string) $data['start'], (string) $data['cwd']);
        }
        if (array_key_exists('memory_mb', $data)) {
            $steps[] = $this->processes->planChangeLimits($user, $domain, $name, (int) $data['memory_mb'], (string) $data['cpus']);
        }
        if ($steps !== [] && ($preview = $this->gate->execute($steps, $this->processes->apply(...)))) {
            return $preview;
        }

        return new AppProcessResource($this->processes->show($user, $domain, $name));
    }

    /**
     * Delete an app process
     *
     * @operationId deleteAppProcess
     *
     * @status 204
     */
    public function destroy(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->remove($principal, $user), 403);
        $this->processes->show($user, $domain, $name);

        return $this->gate->execute([$this->processes->planDelete($user, $domain, $name)], $this->processes->apply(...))
            ?? response()->noContent();
    }

    /**
     * Start an app process
     *
     * @operationId startAppProcess
     */
    public function start(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): AppProcessResource|JsonResponse
    {
        return $this->runAction('start', $user, $domain, $name, $principal, $policy);
    }

    /**
     * Stop an app process
     *
     * @operationId stopAppProcess
     */
    public function stop(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): AppProcessResource|JsonResponse
    {
        return $this->runAction('stop', $user, $domain, $name, $principal, $policy, removeOnly: true);
    }

    /**
     * Restart an app process
     *
     * @operationId restartAppProcess
     */
    public function restart(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): AppProcessResource|JsonResponse
    {
        return $this->runAction('restart', $user, $domain, $name, $principal, $policy);
    }

    /**
     * Reload an app process
     *
     * Runs the reload action of the preset: a command such as
     * `php artisan octane:reload`, a signal, or a restart.
     *
     * @operationId reloadAppProcess
     */
    public function reload(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): AppProcessResource|JsonResponse
    {
        return $this->runAction('reload', $user, $domain, $name, $principal, $policy);
    }

    /**
     * List the environment of an app process
     *
     * @operationId getAppProcessEnv
     */
    public function env(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user) || $policy->remove($principal, $user), 403);
        $this->processes->show($user, $domain, $name);
        $reveal = $request->boolean('reveal');
        if ($reveal) {
            abort_unless($policy->manage($principal, $user) && $this->mayRevealEnv(), 403);
        }

        return response()->json(['data' => $this->actions->envEntries($this->processes->env($user, $domain, $name), $reveal)]);
    }

    /**
     * Replace the environment of an app process
     *
     * An entry without `value` keeps its stored value. Values may reference
     * secrets as `{{secret:NAME}}`; a running process is restarted.
     *
     * @operationId setAppProcessEnv
     */
    public function setEnv(SetAppProcessEnvRequest $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->processes->show($user, $domain, $name);
        $vars = $this->actions->mergeEnvEntries($request->input('entries', []), $this->processes->env($user, $domain, $name));
        if ($preview = $this->gate->execute([$this->processes->planSaveEnv($user, $domain, $name, $vars)], $this->processes->apply(...))) {
            return $preview;
        }
        $reveal = $request->boolean('reveal') && $this->mayRevealEnv();

        return response()->json(['data' => $this->actions->envEntries($this->processes->env($user, $domain, $name), $reveal)]);
    }

    /**
     * Read a snapshot of an app process log
     *
     * `stream` is `process` (the output of the process and its reloads) or
     * `task` (the last one-off task of the domain's processes).
     *
     * @operationId getAppProcessLogs
     */
    public function logs(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->processes->show($user, $domain, $name);
        $data = $request->validate($this->actions->logRules());
        $snapshot = $this->processes->logs($user, $domain, $name, (string) ($data['stream'] ?? 'process'),
            $this->actions->logOffset($request), (int) ($data['lines'] ?? 200));
        $body = $this->actions->logResponse($snapshot);

        return response()->json(['data' => ['lines' => $body['lines']], 'meta' => $body['meta']]);
    }

    /**
     * Start a one-off task of an app process
     *
     * Runs `composer-install`, `migrate`, `pip-install`, `bundle-install` or a
     * `custom` command as the user in the working directory of the process.
     * One task runs at a time per user; follow it with
     * `getAppProcessTask` and the `task` log stream.
     *
     * @operationId startAppProcessTask
     *
     * @monitor
     *
     * @location
     *
     * @status 202
     */
    public function startTask(StartAppProcessTaskRequest $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->assertManage($principal, $user, $policy);
        $this->processes->show($user, $domain, $name);
        $step = $this->processes->planStartTask($user, $domain, $name, (string) $request->input('task'), (string) ($request->input('command') ?? ''));
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $result = $this->processes->apply([$step]);
        if (! $result->ok()) {
            if ($result->exitCode === ExitCode::InUse->value) {
                throw new ApiException(ErrorCode::InUse, $result->error !== '' ? $result->error : __('Another task is running.'), 409,
                    existing: route('api.v2.app-process-task.show', ['user' => $user], false));
            }
            $this->gate->assertApplied($result);
        }

        $task = $this->processes->task($user);
        $response = response()->json(['data' => $this->taskData($task)], 202)
            ->header('Location', route('api.v2.app-process-task.show', ['user' => $user]));
        if ($task !== null) {
            $response->header('Link', TaskLink::monitor((new TaskId(TaskId::SOURCE_APP_PROCESS, $task->id, $user))->apiId()));
        }

        return $response;
    }

    /**
     * Get the last one-off task of a user's app processes
     *
     * @operationId getAppProcessTask
     */
    public function task(string $user, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);

        return response()->json(['data' => $this->taskData($this->processes->task($user))]);
    }

    /** @return array<string, string|int|null>|null */
    private function taskData(?AppProcessTask $task): ?array
    {
        if ($task === null) {
            return null;
        }

        return [
            'id' => $task->id,
            'domain' => $task->domain,
            'name' => $task->name,
            'task' => $task->task,
            'status' => $task->status,
            'exit_code' => $task->exit !== '' ? (int) $task->exit : null,
            'started_at' => $task->start !== '' ? $task->start : null,
            'finished_at' => $task->end !== '' ? $task->end : null,
        ];
    }

    private function runAction(string $action, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy, bool $removeOnly = false): AppProcessResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        if ($removeOnly) {
            abort_unless($policy->remove($principal, $user), 403);
        } else {
            $this->assertManage($principal, $user, $policy);
        }
        $this->processes->show($user, $domain, $name);
        if ($preview = $this->gate->execute([$this->processes->planAction($user, $domain, $name, $action)], $this->processes->apply(...))) {
            return $preview;
        }

        return new AppProcessResource($this->processes->show($user, $domain, $name));
    }

    private function mayRevealEnv(): bool
    {
        if ($this->key->unrestricted()) {
            return true;
        }

        return in_array('v2-write', $this->key->sets, true) || in_array('v2-apps', $this->key->sets, true);
    }

    private function assertOwner(string $user, Principal $principal, AppProcessPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }

    private function assertManage(Principal $principal, string $user, AppProcessPolicy $policy): void
    {
        if ($policy->manage($principal, $user)) {
            return;
        }
        if ($policy->remove($principal, $user) && ! $this->access->allowed($user)) {
            throw new ApiException(ErrorCode::Forbidden, __('App processes are not included in your package.'), 403);
        }
        abort(403);
    }
}
