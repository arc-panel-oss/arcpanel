<?php

namespace App\Http\Controllers\Docker;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\DockerPolicy;
use App\Services\Docker\DockerAppActions;
use App\Services\Docker\DockerAppService;
use App\Services\Docker\DockerCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON routes of the docker-apps island (E4 spec §6.3). Routes with {user}
 * run through `effective-user` (404 outside the scope); an unknown app
 * answers 404 through the command's E_NOTEXIST.
 */
final class DockerAppController extends Controller
{
    public function __construct(
        private readonly DockerAppService $apps,
        private readonly DockerCatalog $catalog,
        private readonly DockerAppActions $actions,
    ) {}

    /** The store: catalog apps the principal may install. */
    public function catalog(string $user, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);

        return response()->json(['apps' => $this->visibleCatalog($principal, $policy)]);
    }

    public function catalogApp(Request $request, string $user, string $slug, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user) && preg_match(DockerCatalog::SLUG, $slug) === 1, 404);
        $version = (string) ($request->validate(['version' => ['nullable', 'string', 'regex:'.DockerCatalog::VERSION]])['version'] ?? '');
        $app = $this->catalog->app($slug, $version);
        abort_if($app['adminOnly'] && ! $policy->installAdminOnly($principal), 404);

        return response()->json(['app' => $app]);
    }

    /** What the install form offers: free domains and the package limit. */
    public function options(string $user, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $apps = $this->apps->apps($user);

        return response()->json([
            'domains' => $this->apps->freeDomains($user, $apps),
            'names' => array_map(fn ($app) => $app->name, $apps->apps),
            'hasRoom' => $apps->hasRoom(),
            'limit' => $apps->limit,
        ]);
    }

    public function index(string $user, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);

        return response()->json($this->listPayload($user, $principal, $policy));
    }

    public function show(string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);

        return response()->json($this->appPayload($user, $name, $principal, $policy));
    }

    public function store(Request $request, string $user, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:'.DockerCatalog::SLUG],
            'name' => ['required', 'string', 'regex:'.DockerAppService::NAME],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^[^\s\/]+$/'],
            'version' => ['nullable', 'string', 'regex:'.DockerCatalog::VERSION],
            'vars' => ['nullable', 'array', 'max:100'],
        ], [
            'name.regex' => __('2 to 32 lower-case letters, digits and dashes, starting with a letter.'),
        ]);
        $entry = $this->catalog->find((string) $data['slug']);
        abort_if($entry === null, 404);
        abort_if($entry['adminOnly'] && ! $policy->installAdminOnly($principal), 403);
        $vars = $this->actions->checkedVars((array) ($data['vars'] ?? []), true);
        $name = (string) $data['name'];
        $id = $this->apps->install($user, (string) $data['slug'], $name, strtolower((string) ($data['domain'] ?? '')), $vars,
            (string) ($data['version'] ?? ''));

        return response()->json(['id' => $id, ...$this->appPayload($user, $name, $principal, $policy)], 202);
    }

    public function destroy(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->removeApps($principal, $user), 403);
        $this->apps->delete($user, $name, $request->boolean('keep_data'));

        return response()->json($this->listPayload($user, $principal, $policy));
    }

    public function action(string $user, string $name, string $action, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        $allowed = $action === 'stop' ? $policy->removeApps($principal, $user) : $policy->manageApps($principal, $user);
        abort_unless($allowed, 403);
        $this->apps->action($user, $name, $action);

        return response()->json($this->appPayload($user, $name, $principal, $policy));
    }

    public function env(string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);

        return response()->json(['variables' => $this->apps->env($user, $name)]);
    }

    public function saveEnv(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate(['vars' => ['present', 'array', 'max:100']]);
        $this->apps->saveEnv($user, $name, $this->actions->checkedVars((array) $data['vars'], false));

        return response()->json(['variables' => $this->apps->env($user, $name)]);
    }

    public function limits(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate([
            'cpus' => ['required', 'string', 'regex:'.DockerAppService::CPUS],
            'memory' => ['required', 'integer', 'min:32', 'max:9999999'],
        ]);
        $this->apps->limits($user, $name, (string) $data['cpus'], (int) $data['memory']);

        return response()->json($this->appPayload($user, $name, $principal, $policy));
    }

    public function domain(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageApps($principal, $user), 403);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^[^\s\/]+$/']]);
        $this->apps->changeDomain($user, $name, strtolower((string) $data['domain']));

        return response()->json($this->appPayload($user, $name, $principal, $policy));
    }

    public function logs(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $data = $request->validate([
            'service' => ['nullable', 'string', 'regex:/^(all|[a-z0-9][a-z0-9_.-]{0,62})$/'],
            'since' => ['nullable', 'string', 'regex:'.DockerAppService::SINCE],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json($this->apps->logs($user, $name, (string) ($data['service'] ?? 'all'),
            isset($data['since']) ? (string) $data['since'] : null, (int) ($data['lines'] ?? 200)));
    }

    public function task(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewApps($principal, $user), 403);
        $offset = (int) ($request->validate(['offset' => ['nullable', 'integer', 'min:0']])['offset'] ?? 0);

        return response()->json(['task' => $this->apps->task($user, $name, $offset)->toArray()]);
    }

    public function startTask(Request $request, string $user, string $name, Principal $principal, DockerPolicy $policy): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(DockerAppService::TASKS)],
            'version' => ['nullable', 'string', 'regex:'.DockerCatalog::VERSION],
        ]);
        abort_unless($policy->manageApps($principal, $user), 403);
        $id = $this->apps->startTask($user, $name, (string) $data['action'], (string) ($data['version'] ?? ''));

        return response()->json(['id' => $id, 'task' => $this->apps->task($user, $name)->toArray()], 202);
    }

    /** @return list<array<string, mixed>> */
    private function visibleCatalog(Principal $principal, DockerPolicy $policy): array
    {
        return $this->actions->visibleCatalog($this->catalog->apps(), $policy->installAdminOnly($principal));
    }

    /** @return array<string, mixed> */
    private function listPayload(string $user, Principal $principal, DockerPolicy $policy): array
    {
        return [
            'account' => $this->apps->apps($user)->toArray(),
            'abilities' => $policy->appAbilities($principal, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function appPayload(string $user, string $name, Principal $principal, DockerPolicy $policy): array
    {
        return [
            'app' => $this->apps->show($user, $name)->toArray(),
            'abilities' => $policy->appAbilities($principal, $user),
        ];
    }
}
