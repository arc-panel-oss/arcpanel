<?php

namespace App\Http\Controllers\AppProcesses;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\AppProcessPolicy;
use App\Services\AppProcesses\AppProcessActions;
use App\Services\AppProcesses\AppProcessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON routes of the app processes island (E42 spec §8). Routes with {user}
 * run through `effective-user` (404 outside the scope); an unknown domain or
 * process answers 404 through the command's E_NOTEXIST.
 */
final class AppProcessController extends Controller
{
    public function __construct(
        private readonly AppProcessService $processes,
        private readonly AppProcessActions $actions,
    ) {}

    public function index(string $user, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json($this->listPayload($user, $principal, $policy));
    }

    /** What the create wizard offers: domains, presets, limits. */
    public function options(Request $request, string $user, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
            'cwd' => ['nullable', 'string', 'regex:'.AppProcessService::PATH],
        ]);
        $processes = $this->processes->processes($user);
        $domain = isset($data['domain']) ? strtolower((string) $data['domain']) : null;

        return response()->json([
            'domains' => array_map(fn (string $name) => [
                'domain' => $name,
                'hasWeb' => $processes->hasWeb($name),
            ], $this->processes->domains($user)),
            'presets' => $this->processes->presets($domain === null ? null : $user, $domain, (string) ($data['cwd'] ?? 'public_html')),
            'hasRoom' => $processes->hasRoom(),
            'limit' => $processes->limit,
            'maxMemory' => $processes->maxMemory,
            'defaultMemory' => $processes->defaultMemory,
            'maxCpus' => $processes->maxCpus,
        ]);
    }

    public function store(Request $request, string $user, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate($this->actions->createRules());
        $domain = strtolower((string) $data['domain']);
        $this->processes->create($user, $domain, $this->actions->createPayload($data));

        return response()->json($this->processPayload($user, $domain, (string) $data['name'], $principal, $policy), 201);
    }

    public function show(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json($this->processPayload($user, $domain, $name, $principal, $policy));
    }

    public function destroy(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->remove($principal, $user), 403);
        $this->processes->delete($user, $domain, $name);

        return response()->json($this->listPayload($user, $principal, $policy));
    }

    public function action(string $user, string $domain, string $name, string $action, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        $allowed = $action === 'stop' ? $policy->remove($principal, $user) : $policy->manage($principal, $user);
        abort_unless($allowed, 403);
        $this->processes->action($user, $domain, $name, $action);

        return response()->json($this->processPayload($user, $domain, $name, $principal, $policy));
    }

    public function changeStart(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'start' => ['required', 'string', 'max:2048', 'regex:'.AppProcessActions::START],
            'cwd' => ['required', 'string', 'regex:'.AppProcessService::PATH],
        ]);
        $this->processes->changeStart($user, $domain, $name, (string) $data['start'], (string) $data['cwd']);

        return response()->json($this->processPayload($user, $domain, $name, $principal, $policy));
    }

    public function changeLimits(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'memory_mb' => ['required', 'integer', 'min:32', 'max:999999'],
            'cpus' => ['required', 'string', 'regex:'.AppProcessService::CPUS],
        ]);
        $this->processes->changeLimits($user, $domain, $name, (int) $data['memory_mb'], (string) $data['cpus']);

        return response()->json($this->processPayload($user, $domain, $name, $principal, $policy));
    }

    public function env(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user) || $policy->remove($principal, $user), 403);

        return response()->json(['vars' => $this->processes->env($user, $domain, $name)]);
    }

    public function saveEnv(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate($this->actions->envSaveRules(), [
            'vars.*.key.not_in' => __(':input is set by the panel.'),
        ]);
        $this->processes->saveEnv($user, $domain, $name, $this->actions->normalizeSessionEnv($data['vars']));

        return response()->json(['vars' => $this->processes->env($user, $domain, $name)]);
    }

    public function logs(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $data = $request->validate([
            'stream' => ['nullable', Rule::in(AppProcessService::STREAMS)],
            'offset' => ['nullable', 'integer', 'min:0'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $offset = isset($data['offset']) ? (int) $data['offset'] : null;

        return response()->json($this->processes->logs($user, $domain, $name, (string) ($data['stream'] ?? 'process'), $offset, (int) ($data['lines'] ?? 200)));
    }

    public function task(string $user, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json(['task' => $this->processes->task($user)?->toArray()]);
    }

    public function startTask(Request $request, string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate($this->actions->taskRules());
        $id = $this->processes->startTask($user, $domain, $name, (string) $data['task'], (string) ($data['command'] ?? ''));

        return response()->json(['id' => $id, 'task' => $this->processes->task($user)?->toArray()], 202);
    }

    /** @return array<string, mixed> */
    private function listPayload(string $user, Principal $principal, AppProcessPolicy $policy): array
    {
        return [
            'account' => $this->processes->processes($user)->toArray(),
            'task' => $this->processes->task($user)?->toArray(),
            'abilities' => $policy->abilities($principal, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function processPayload(string $user, string $domain, string $name, Principal $principal, AppProcessPolicy $policy): array
    {
        return [
            'process' => $this->processes->show($user, $domain, $name)->toArray(),
            'task' => $this->processes->task($user)?->toArray(),
            'abilities' => $policy->abilities($principal, $user),
        ];
    }
}
