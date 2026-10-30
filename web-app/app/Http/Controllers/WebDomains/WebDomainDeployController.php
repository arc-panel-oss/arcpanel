<?php

namespace App\Http\Controllers\WebDomains;

use App\Api\TaskId;
use App\Arc\ArcCommandException;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\WebDomainPolicy;
use App\Services\Deploy\DeployRules;
use App\Services\Deploy\DeployService;
use App\Services\Tasks\TaskService;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The Deploy tab of a web domain (E20 spec §7,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md): setup of
 * pull or push mode, deploy now, rollback, settings, credentials, trusted
 * publishers, and the logs of the deploy tasks. The webhook secret is shown
 * once, in the answer of the request that created it, never in a session.
 */
final class WebDomainDeployController extends Controller
{
    /** Lines of a task log the drawer and the live view show. */
    private const LOG_LINES = 400;

    public function __construct(
        private readonly DeployService $deploy,
        private readonly WebDomainService $web,
    ) {}

    public function store(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): View|RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $config = $this->deploy->config($user, $domain);
        if (($config['STATE'] ?? 'off') !== 'off') {
            return $this->back($user, $domain)->with('error', __('Git Deploy is already on for this domain.'));
        }

        $mode = $request->input('mode') === 'push' ? 'push' : 'pull';
        $data = $this->validated($request, $user, $domain, $mode === 'pull' ? DeployRules::pull((string) $request->input('auth', 'key')) : DeployRules::push($this->deploy->issuers()));

        try {
            if ($mode === 'pull') {
                $once = $this->deploy->enablePull($user, $domain, $data['url'], $data['branch'], $data['preset'], $data['auth'],
                    $data['auth'] === 'token' ? (string) $data['token'] : null, $data['webroot']);

                return $this->editWith($user, $domain, ['deployOnce' => ['webhook_secret' => $once['webhook_secret']]]);
            }
            $this->deploy->enablePush($user, $domain, $data['webroot'], [
                'issuer' => $data['issuer'], 'repo' => $data['repo'], 'ref' => $data['ref'] ?? '', 'environment' => $data['environment'] ?? '', 'workflow' => $data['workflow'] ?? '',
            ]);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->withInput($request->except('token'))
                ->with('error', __('Git Deploy was not turned on.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', __('Git Deploy is on. Add the workflow to your CI to deploy.'));
    }

    public function update(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $config = $this->activeConfig($user, $domain);
        $pull = ($config['MODE'] ?? '') === 'pull';
        $data = $this->validated($request, $user, $domain, DeployRules::settings($pull));

        $wanted = [
            'KEEP' => (string) $data['keep'],
            'SHARED' => DeployRules::normalizeShared((string) ($data['shared'] ?? '')),
            'HEALTH_PATH' => (string) ($data['health_path'] ?? ''),
            'WEBROOT' => (string) $data['webroot'],
        ];
        if ($pull) {
            $wanted += ['BRANCH' => (string) $data['branch'], 'PRESET' => (string) $data['preset'], 'MIGRATE' => $request->boolean('migrate') ? 'yes' : 'no'];
        }

        try {
            $saved = $this->deploy->change($user, $domain, array_map('strval', array_filter($config, 'is_scalar')), $wanted);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->withInput()->with('error', __('The settings were not saved.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', $saved === [] ? __('Nothing has changed.') : __('Changes have been saved.'));
    }

    public function run(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $this->activeConfig($user, $domain);

        try {
            $this->deploy->deploy($user, $domain);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->with('error', __('The deploy did not start.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', __('The deploy has started.'));
    }

    public function rollback(string $user, string $domain, string $release, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $this->activeConfig($user, $domain);
        $data = ['release' => $release];

        try {
            $this->deploy->rollback($user, $domain, $data['release']);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->with('error', __('The rollback did not start.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', __('The rollback has started.'));
    }

    public function credential(Request $request, string $user, string $domain, string $kind, Principal $principal, WebDomainPolicy $policy): View|RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $config = $this->activeConfig($user, $domain);
        $token = null;
        if ($kind === 'token') {
            $token = (string) $this->validated($request, $user, $domain, DeployRules::token((string) ($config['URL'] ?? '')))['token'];
        }

        try {
            $values = $this->deploy->renew($user, $domain, $kind, $token);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->with('error', __('The credential was not renewed.'))->with('error_detail', $e->userMessage());
        }

        return match ($kind) {
            'webhook' => $this->editWith($user, $domain, ['deployOnce' => ['webhook_secret' => $values['WEBHOOK_SECRET'] ?? '']]),
            'key' => $this->back($user, $domain)->with('ok', __('A new deploy key has been created. Add its public key to the repository before the next deploy.')),
            'id' => $this->back($user, $domain)->with('ok', __('The deploy URLs have changed. Update them in the Git host and the CI.')),
            default => $this->back($user, $domain)->with('ok', __('The token has been saved.')),
        };
    }

    public function addPublisher(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $this->activeConfig($user, $domain);
        $data = $this->validated($request, $user, $domain, DeployRules::publisher($this->deploy->issuers()));

        try {
            $this->deploy->addPublisher($user, $domain, $data);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->withInput()->with('error', __('The publisher was not added.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', __('The trusted publisher has been added.'));
    }

    public function deletePublisher(string $user, string $domain, string $pub, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);
        $this->activeConfig($user, $domain);

        try {
            $this->deploy->deletePublisher($user, $domain, $pub);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->with('error', __('The publisher was not removed.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', __('The trusted publisher has been removed.'));
    }

    public function destroy(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): RedirectResponse
    {
        $this->authorizeChange($principal, $policy, $user, $domain);

        try {
            $this->deploy->disable($user, $domain);
        } catch (ArcCommandException $e) {
            return $this->back($user, $domain)->with('error', __('Git Deploy was not turned off.'))->with('error_detail', $e->userMessage());
        }

        return $this->back($user, $domain)->with('ok', __('Git Deploy is off.'));
    }

    /** GET deploy/tasks/{task}: a deploy task of the domain and its log, for the drawer. */
    public function task(string $user, string $domain, string $task, Principal $principal, WebDomainPolicy $policy, TaskService $tasks): JsonResponse
    {
        abort_unless($policy->viewDeploy($principal, $user), 403);
        $this->web->find($user, $domain);
        $row = $this->readTask($tasks, $user, $domain, $task);
        abort_if($row === null, 404);

        return response()->json($row);
    }

    /** GET deploy/state: whether a deploy runs, with its log, for the live view. */
    public function state(string $user, string $domain, Principal $principal, WebDomainPolicy $policy, TaskService $tasks): JsonResponse
    {
        abort_unless($policy->viewDeploy($principal, $user), 403);
        $this->web->find($user, $domain);
        $running = (string) ($this->deploy->config($user, $domain)['RUNNING'] ?? '');
        $row = $running !== '' ? $this->readTask($tasks, $user, $domain, $running) : null;
        if ($row === null) {
            return response()->json(['running' => false, 'task' => null, 'lines' => []]);
        }

        return response()->json(['running' => in_array($row['task']['status'], ['running', 'queued'], true)] + $row);
    }

    /**
     * The task with the log as lines, or null when it is not a deploy task of the domain.
     *
     * @return array{task: array<string, mixed>, lines: list<string>}|null
     */
    public static function readTask(TaskService $tasks, string $user, string $domain, string $task): ?array
    {
        if (preg_match('/^\d{14}$/', $task) !== 1) {
            return null;
        }
        try {
            $view = $tasks->get(TaskId::queue($user, $task), self::LOG_LINES)->toArray();
        } catch (Throwable) {
            return null;
        }
        if ($view['kind'] !== 'deploy' || explode(' ', (string) $view['args'])[0] !== $domain) {
            return null;
        }
        $lines = $view['log'] === '' || $view['log'] === null ? [] : explode("\n", rtrim((string) $view['log'], "\n"));
        unset($view['log']);

        return ['task' => $view, 'lines' => $lines];
    }

    private function authorizeChange(Principal $principal, WebDomainPolicy $policy, string $user, string $domain): void
    {
        abort_unless($policy->viewDeploy($principal, $user), 404);
        abort_unless($policy->deploy($principal, $user), 403);
        $this->web->find($user, $domain);
    }

    /** @return array<string, mixed> the configuration of a deploy that is on */
    private function activeConfig(string $user, string $domain): array
    {
        $config = $this->deploy->config($user, $domain);
        abort_if(($config['STATE'] ?? 'off') === 'off', 409, __('Git Deploy is off for this domain.'));

        return $config;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $user, string $domain, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $e) {
            throw $e->errorBag('deploy')->redirectTo($this->tabUrl($user, $domain));
        }
    }

    /** The edit page with values shown once (no redirect: they never reach a session). */
    private function editWith(string $user, string $domain, array $data): View
    {
        /** @var View $view */
        $view = app()->call([app(WebDomainEditController::class), 'edit'], ['user' => $user, 'domain' => $domain]);

        return $view->with($data);
    }

    private function back(string $user, string $domain): RedirectResponse
    {
        return redirect()->to($this->tabUrl($user, $domain));
    }

    private function tabUrl(string $user, string $domain): string
    {
        return route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#deploy';
    }
}
