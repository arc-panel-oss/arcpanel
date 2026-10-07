<?php

namespace App\Http\Controllers\Node;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\NodePolicy;
use App\Services\Node\NodeAppActions;
use App\Services\Node\NodeAppService;
use App\Services\Node\NodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON routes of the Node apps island (E14 spec §6.3). Routes with {user}
 * run through `effective-user` (404 outside the scope); an unknown domain
 * answers 404 through the command's E_NOTEXIST.
 */
final class NodeAppController extends Controller
{
    public function __construct(
        private readonly NodeAppService $apps,
        private readonly NodeService $node,
        private readonly NodeAppActions $actions,
    ) {}

    public function index(string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);

        return response()->json($this->listPayload($user, $principal, $policy));
    }

    /** What the create wizard offers: free domains, runtimes, templates. */
    public function options(string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $apps = $this->apps->apps($user);
        $account = $this->node->account($user)->toArray();

        return response()->json([
            'domains' => $this->apps->freeDomains($user, $apps),
            'templates' => $this->apps->templates(),
            'runtimes' => $account['runtimes'] ?? [],
            'default' => $account['default'] ?? '',
            'hasRoom' => $apps->hasRoom(),
            'limit' => $apps->limit,
            'maxInstances' => $apps->maxInstances,
        ]);
    }

    public function detect(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $cwd = (string) ($request->validate(['cwd' => ['nullable', 'string', 'regex:'.NodeAppService::PATH]])['cwd'] ?? 'public_html');

        return response()->json($this->apps->detect($user, $domain, $cwd ?: 'public_html'));
    }

    public function store(Request $request, string $user, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate($this->actions->createRules());
        $domain = strtolower((string) $data['domain']);
        $this->apps->create($user, $domain, $this->actions->createPayload($data));

        return response()->json($this->appPayload($user, $domain, $principal, $policy), 201);
    }

    public function show(string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);

        return response()->json($this->appPayload($user, $domain, $principal, $policy));
    }

    public function destroy(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->removeApps($principal, $user), 403);
        $this->apps->delete($user, $domain, $request->boolean('keep_files'));

        return response()->json($this->listPayload($user, $principal, $policy));
    }

    public function action(string $user, string $domain, string $action, Principal $principal, NodePolicy $policy): JsonResponse
    {
        $allowed = $action === 'stop' ? $policy->removeApps($principal, $user) : $policy->manageApps($principal, $user);
        abort_unless($allowed, 403);
        $this->apps->action($user, $domain, $action);

        return response()->json($this->appPayload($user, $domain, $principal, $policy));
    }

    public function changeStart(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate([
            'start' => ['required', 'string', 'max:200', 'regex:'.NodeAppActions::startRule()],
            'cwd' => ['required', 'string', 'regex:'.NodeAppService::PATH],
        ]);
        $this->apps->changeStart($user, $domain, (string) $data['start'], (string) $data['cwd']);

        return response()->json($this->appPayload($user, $domain, $principal, $policy));
    }

    public function changeVersion(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^(default|\d{1,3}\.\d{1,3}\.\d{1,3})$/']]);
        $this->apps->changeVersion($user, $domain, (string) $data['version']);

        return response()->json($this->appPayload($user, $domain, $principal, $policy));
    }

    public function scale(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate([
            'instances' => ['required', 'integer', 'min:1', 'max:999'],
            'memory' => ['required', 'integer', 'min:64', 'max:999999'],
        ]);
        $this->apps->scale($user, $domain, (int) $data['instances'], (int) $data['memory']);

        return response()->json($this->appPayload($user, $domain, $principal, $policy));
    }

    public function env(string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user) || $policy->removeApps($principal, $user), 403);

        return response()->json(['vars' => $this->apps->env($user, $domain)]);
    }

    public function saveEnv(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate($this->actions->envSaveRules(), [
            'vars.*.key.not_in' => __(':input is set by the panel.'),
        ]);
        $vars = $this->actions->normalizeSessionEnv($data['vars']);
        $this->actions->assertEnvSize($vars);
        $this->apps->saveEnv($user, $domain, $vars);

        return response()->json(['vars' => $this->apps->env($user, $domain)]);
    }

    public function logs(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $data = $request->validate([
            'stream' => ['nullable', Rule::in(NodeAppService::STREAMS)],
            'offset' => ['nullable', 'integer', 'min:0'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $offset = isset($data['offset']) ? (int) $data['offset'] : null;

        return response()->json($this->apps->logs($user, $domain, (string) ($data['stream'] ?? 'out'), $offset, (int) ($data['lines'] ?? 200)));
    }

    public function install(string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);

        return $this->started($user, $this->apps->startInstall($user, $domain));
    }

    public function build(Request $request, string $user, string $domain, Principal $principal, NodePolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $script = (string) ($request->validate(['script' => ['nullable', 'string', 'regex:'.NodeAppService::SCRIPT]])['script'] ?? 'build');

        return $this->started($user, $this->apps->startBuild($user, $domain, $script ?: 'build'));
    }

    /** @return array<string, mixed> */
    private function listPayload(string $user, Principal $principal, NodePolicy $policy): array
    {
        return [
            'account' => $this->apps->apps($user)->toArray(),
            'task' => $this->node->task($user)->toArray(),
            'abilities' => $policy->appAbilities($principal, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function appPayload(string $user, string $domain, Principal $principal, NodePolicy $policy): array
    {
        return [
            'app' => $this->apps->show($user, $domain)->toArray(),
            'task' => $this->node->task($user)->toArray(),
            'abilities' => $policy->appAbilities($principal, $user),
        ];
    }

    private function started(string $user, string $id): JsonResponse
    {
        return response()->json(['id' => $id, 'task' => $this->node->task($user)->toArray()], 202);
    }
}
