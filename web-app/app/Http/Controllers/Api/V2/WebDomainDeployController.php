<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Api\TaskId;
use App\Api\TaskLink;
use App\Arc\ArcCommandException;
use App\Arc\Secret;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\AddWebDomainDeployPublisherRequest;
use App\Http\Requests\Api\V2\ConfigureWebDomainDeployRequest;
use App\Http\Requests\Api\V2\RunWebDomainDeployRequest;
use App\Http\Resources\V2\TaskResource;
use App\Http\Resources\V2\WebDomainDeployReleaseResource;
use App\Http\Resources\V2\WebDomainDeployResource;
use App\Policies\WebDomainPolicy;
use App\Services\Deploy\DeployService;
use App\Services\Support\PlanRunner;
use App\Services\Tasks\TaskMapper;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Git Deploy of a web domain (E20 spec §8,
 * docs/features/specs/2026-10-29-001-e20-git-deploy-design.md): the
 * configuration, deploys and rollbacks (background tasks), the releases and
 * the trusted publishers of push mode. Secrets are never answered; the
 * public deploy key and the webhook secret come back once, in the answer
 * of the request that created them.
 *
 * @tags Web domains
 */
final class WebDomainDeployController extends Controller
{
    /** Settings that only pull mode has, by request field. */
    private const PULL_ONLY = ['url', 'branch', 'preset', 'migrate', 'auth', 'token'];

    public function __construct(
        private readonly DeployService $deploy,
        private readonly WebDomainActions $actions,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly Paginates $pages,
    ) {}

    /**
     * Get the Git Deploy of a web domain
     *
     * Its state (`off`, `new` until the first deploy succeeded, `active`),
     * mode, settings, endpoint URLs, trusted publishers, and whether the
     * domain can use deploy at all (`supported`, `reason`).
     *
     * @operationId getWebDomainDeploy
     */
    public function show(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainDeployResource
    {
        $this->assertVisible($user, $principal, $policy);

        return $this->resource($user, $domain, $this->deploy->config($user, $domain));
    }

    /**
     * Configure the Git Deploy of a web domain
     *
     * When deploy is off, turns it on: `mode` `pull` with `url` (and
     * `branch`, `preset`, `auth`, `token`) or `push` with an optional first
     * `publisher`; the other settings are saved after it. A new deploy key
     * and webhook secret are answered once (`public_key`, `webhook_secret`).
     * When deploy is on, saves the settings that differ; `auth` `key` makes a
     * new key pair (answered once), `token` stores a new token. The mode
     * cannot change while deploy is on. A package without Git Deploy answers
     * `403`, a domain whose template cannot serve releases `409` `E_DISABLED`.
     *
     * @operationId configureWebDomainDeploy
     *
     * @conflict E_DISABLED The domain has no PHP-FPM backend, or its pool template lacks the release paths.
     */
    public function update(ConfigureWebDomainDeployRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainDeployResource|JsonResponse
    {
        $this->assertVisible($user, $principal, $policy);
        abort_unless($policy->deploy($principal, $user), 403);
        $current = $this->deploy->config($user, $domain);

        return self::isOff($current)
            ? $this->enable($request, $user, $domain, $current)
            : $this->change($request, $user, $domain, $current);
    }

    /**
     * Turn the Git Deploy of a web domain off
     *
     * Running and pending deploys stop, the document root from before the
     * first deploy comes back and the credentials are deleted; `releases/`
     * and `shared/` stay in the home directory.
     *
     * @operationId disableWebDomainDeploy
     */
    public function destroy(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainDeployResource|JsonResponse
    {
        $this->assertVisible($user, $principal, $policy);
        abort_unless($policy->deploy($principal, $user), 403);
        $this->assertOn($domain, $this->deploy->config($user, $domain));
        $steps = [new Step(sprintf(__('Turn Git Deploy off for %s'), $domain), 'v-delete-web-domain-deploy', [$user, $domain])];

        return $this->gate->execute($steps, $this->runner->apply(...), ['v-list-web-domain-deploy'])
            ?? $this->resource($user, $domain, $this->deploy->config($user, $domain));
    }

    /**
     * Deploy a web domain
     *
     * Pull mode: fetches the branch, or `ref` (a commit or a ref name), builds
     * it with the preset and switches to it. While a deploy of the domain
     * runs, the request waits as the pending one (the newest wins). A domain
     * in push mode deploys the archives its CI uploads (`422`).
     *
     * @operationId runWebDomainDeploy
     *
     * @monitor
     *
     * @status 202
     */
    public function run(RunWebDomainDeployRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertVisible($user, $principal, $policy);
        abort_unless($policy->deploy($principal, $user), 403);
        $ref = trim((string) $request->input('ref', ''));
        $steps = [new Step(sprintf(__('Deploy %s'), $domain), 'v-schedule-web-domain-deploy', [$user, $domain, $ref, 'json'])];
        $this->gate->authorize($steps, ['v-list-web-domain-deploy']);
        $current = $this->deploy->config($user, $domain);
        $this->assertOn($domain, $current);
        if (($current['MODE'] ?? '') !== 'pull') {
            throw new ApiException(ErrorCode::Invalid, __('A domain in push mode deploys the archives its CI uploads.'), 422);
        }
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $domain, $this->deploy->deployRef($user, $domain, $ref), 'pull '.($ref !== '' ? $ref : (string) ($current['BRANCH'] ?? '')));
    }

    /**
     * List the releases of a web domain's Git Deploy
     *
     * Newest first, with the one that is live (`current`).
     *
     * @operationId listWebDomainDeployReleases
     */
    public function releases(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): AnonymousResourceCollection
    {
        $this->assertVisible($user, $principal, $policy);
        $rows = array_map(fn (array $row) => ['user' => $user, 'row' => $row], $this->deploy->history($user, $domain)['releases']);
        $page = $this->pages->slice($request, $rows);

        return WebDomainDeployReleaseResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Roll a web domain back to a release
     *
     * Switches to the release without a build; database migrations of later
     * releases are not undone. Runs as a deploy task, after a deploy that runs.
     *
     * @operationId rollbackWebDomainDeploy
     *
     * @monitor
     *
     * @status 202
     */
    public function rollback(string $user, string $domain, string $id, Principal $principal, WebDomainPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertVisible($user, $principal, $policy);
        abort_unless($policy->deploy($principal, $user), 403);
        $steps = [new Step(sprintf(__('Roll back to release %s'), $id), 'v-rollback-web-domain-deploy', [$user, $domain, $id, 'json'])];
        $this->gate->authorize($steps, ['v-list-web-domain-deploy', 'v-list-web-domain-deploys']);
        $this->assertOn($domain, $this->deploy->config($user, $domain));
        if (! in_array($id, array_column($this->deploy->history($user, $domain)['releases'], 'ID'), true)) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('Release %s does not exist.'), $id), 404);
        }
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        return $this->queued($user, $domain, $this->deploy->rollback($user, $domain, $id), 'rollback '.$id);
    }

    /**
     * Add a trusted publisher to a web domain's Git Deploy
     *
     * Push mode: a CI whose OIDC token comes from `issuer` for `repository`,
     * and matches `ref` or `environment` (at least one), may upload archives.
     * An issuer the server does not allow answers `422`.
     *
     * @operationId addWebDomainDeployPublisher
     */
    public function addPublisher(AddWebDomainDeployPublisherRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainDeployResource|JsonResponse
    {
        $this->assertVisible($user, $principal, $policy);
        abort_unless($policy->deploy($principal, $user), 403);
        $steps = [$this->publisherStep($user, $domain, $request->publisher())];
        $this->gate->authorize($steps, ['v-list-web-domain-deploy']);
        $current = $this->deploy->config($user, $domain);
        $this->assertOn($domain, $current);
        if (($current['MODE'] ?? '') !== 'push') {
            throw new ApiException(ErrorCode::Invalid, __('Trusted publishers belong to push mode.'), 422);
        }

        return $this->gate->execute($steps, $this->runner->apply(...))
            ?? $this->resource($user, $domain, $this->deploy->config($user, $domain));
    }

    /**
     * Remove a trusted publisher from a web domain's Git Deploy
     *
     * Its CI can no longer upload archives.
     *
     * @operationId deleteWebDomainDeployPublisher
     */
    public function deletePublisher(string $user, string $domain, string $pub, Principal $principal, WebDomainPolicy $policy): WebDomainDeployResource|JsonResponse
    {
        $this->assertVisible($user, $principal, $policy);
        abort_unless($policy->deploy($principal, $user), 403);
        $steps = [new Step(sprintf(__('Remove trusted publisher %s'), $pub), 'v-delete-web-domain-deploy-publisher', [$user, $domain, $pub])];
        $this->gate->authorize($steps, ['v-list-web-domain-deploy']);
        $current = $this->deploy->config($user, $domain);
        $this->assertOn($domain, $current);
        if (! in_array($pub, array_column(array_filter((array) ($current['PUBLISHERS'] ?? []), 'is_array'), 'PUB'), true)) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('Trusted publisher %s does not exist.'), $pub), 404);
        }

        return $this->gate->execute($steps, $this->runner->apply(...))
            ?? $this->resource($user, $domain, $this->deploy->config($user, $domain));
    }

    /**
     * Turn deploy on: the add, the credential, then the settings that differ
     * from what the add set and the first publisher. A failure after the add
     * turns deploy off again, so no half set up deploy is left behind.
     *
     * @param  array<string, mixed>  $current
     */
    private function enable(ConfigureWebDomainDeployRequest $request, string $user, string $domain, array $current): WebDomainDeployResource|JsonResponse
    {
        $mode = $request->input('mode');
        $settings = $request->settings();
        $publisher = $request->input('publisher');
        $errors = [];
        if (! is_string($mode)) {
            $errors['mode'] = __('Choose pull or push to turn Git Deploy on.');
        }
        $first = [];
        if ($mode === 'pull') {
            $url = (string) $request->input('url', '');
            $auth = (string) $request->input('auth', 'key');
            $token = (string) $request->input('token', '');
            if ($url === '') {
                $errors['url'] = __('Enter the URL of the repository.');
            }
            if ($auth === 'token' && $token === '') {
                $errors['token'] = __('Enter the token.');
            } elseif ($auth === 'token' && $url !== '' && ! str_starts_with($url, 'https://')) {
                $errors['auth'] = __('A token needs an https:// repository URL.');
            } elseif ($auth === 'key' && $request->has('token')) {
                $errors['token'] = __('A token goes with the token authentication.');
            }
            if ($publisher !== null) {
                $errors['publisher'] = __('Trusted publishers belong to push mode.');
            }
            $first[] = new Step(sprintf(__('Turn Git Deploy on for %s'), $domain), 'v-add-web-domain-deploy', [
                $user, $domain, 'pull', $url, $settings['BRANCH'] ?? 'main', $settings['PRESET'] ?? 'none', $auth,
            ]);
            if ($auth === 'token') {
                $first[] = new Step(__('Store the token'), 'v-change-web-domain-deploy-credential', [$user, $domain, 'token', Secret::file($token)]);
            }
            unset($settings['URL'], $settings['BRANCH'], $settings['PRESET']);
        } elseif ($mode === 'push') {
            $errors += $this->pullOnlyErrors($request);
            $first[] = new Step(sprintf(__('Turn Git Deploy on for %s'), $domain), 'v-add-web-domain-deploy', [$user, $domain, 'push']);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->assertSupported($current);

        $publisherSteps = is_array($publisher) ? [$this->publisherStep($user, $domain, AddWebDomainDeployPublisherRequest::publisherOf($publisher))] : [];
        $plan = [...$first, ...$this->settingSteps($user, $domain, [], $settings), ...$publisherSteps];
        $this->gate->authorize($plan, ['v-list-web-domain-deploy', 'v-delete-web-domain-deploy']);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($plan);
        }

        $added = $this->runner->apply($first);
        if (! $added->ok()) {
            $this->undo($user, $domain, $added);
            $this->gate->assertApplied($added);
        }
        $credentials = DeployService::printedValues((string) $added->stdoutOf('v-add-web-domain-deploy'));
        $rest = $this->runner->apply([...$this->settingSteps($user, $domain, $this->deploy->config($user, $domain), $settings), ...$publisherSteps]);
        if (! $rest->ok()) {
            $this->undo($user, $domain, $added);
            $this->gate->assertApplied(new ApplyResult(
                [...$added->applied, ...$rest->applied], $rest->failed, $rest->error, $rest->exitCode, [], $rest->invalidArgument,
            ));
        }

        return $this->resource($user, $domain, $this->deploy->config($user, $domain), $credentials);
    }

    /**
     * Change a deploy that is on: the settings that differ, then a new key
     * pair or token.
     *
     * @param  array<string, mixed>  $current
     */
    private function change(ConfigureWebDomainDeployRequest $request, string $user, string $domain, array $current): WebDomainDeployResource|JsonResponse
    {
        $mode = (string) ($current['MODE'] ?? '');
        $settings = $request->settings();
        $errors = [];
        if ($request->has('mode') && $request->input('mode') !== $mode) {
            $errors['mode'] = __('Turn Git Deploy off to change its mode.');
        }
        if ($request->input('publisher') !== null) {
            $errors['publisher'] = __('Add trusted publishers one by one, with their own request.');
        }
        $credentials = [];
        if ($mode === 'push') {
            $errors += $this->pullOnlyErrors($request);
        } else {
            $auth = (string) $request->input('auth', (string) ($current['AUTH'] ?? 'key'));
            $token = (string) $request->input('token', '');
            if ($auth === 'token' && ($auth !== ($current['AUTH'] ?? '') || $request->has('token'))) {
                if ($token === '') {
                    $errors['token'] = __('Enter the token.');
                } elseif (! str_starts_with($settings['URL'] ?? (string) ($current['URL'] ?? ''), 'https://')) {
                    $errors['auth'] = __('A token needs an https:// repository URL.');
                }
                $credentials[] = new Step(__('Store the token'), 'v-change-web-domain-deploy-credential', [$user, $domain, 'token', Secret::file($token)]);
            } elseif ($auth === 'key' && $request->has('token')) {
                $errors['token'] = __('A token goes with the token authentication.');
            } elseif ($auth === 'key' && ($current['AUTH'] ?? '') !== 'key') {
                $credentials[] = new Step(__('Create a new deploy key'), 'v-change-web-domain-deploy-credential', [$user, $domain, 'key']);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $steps = [...$this->settingSteps($user, $domain, $current, $settings), ...$credentials];
        if ($steps === []) {
            return $this->resource($user, $domain, $current);
        }
        $this->gate->authorize($steps, ['v-list-web-domain-deploy']);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->runner->apply($steps);
        $this->gate->assertApplied($result);

        return $this->resource($user, $domain, $this->deploy->config($user, $domain),
            DeployService::printedValues((string) $result->stdoutOf('v-change-web-domain-deploy-credential')));
    }

    /**
     * One step per setting whose value differs from $current.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, string>  $wanted
     * @return list<Step>
     */
    private function settingSteps(string $user, string $domain, array $current, array $wanted): array
    {
        $steps = [];
        foreach (DeployService::SETTINGS as $key) {
            if (array_key_exists($key, $wanted) && (string) ($current[$key] ?? '') !== $wanted[$key]) {
                $steps[] = new Step(sprintf(__('Set %1$s to %2$s'), $key, $wanted[$key]), 'v-change-web-domain-deploy', [$user, $domain, $key, $wanted[$key]]);
            }
        }

        return $steps;
    }

    /** @param  array{issuer: string, repo: string, ref?: string|null, environment?: string|null, workflow?: string|null}  $publisher */
    private function publisherStep(string $user, string $domain, array $publisher): Step
    {
        return new Step(sprintf(__('Trust %s'), $publisher['repo']), 'v-add-web-domain-deploy-publisher', [$user, $domain, ...DeployService::publisherArguments($publisher)]);
    }

    /** @return array<string, string> the pull mode fields of a request for push mode */
    private function pullOnlyErrors(ConfigureWebDomainDeployRequest $request): array
    {
        $errors = [];
        foreach (self::PULL_ONLY as $field) {
            if ($request->has($field)) {
                $errors[$field] = __('This is a setting of pull mode.');
            }
        }

        return $errors;
    }

    /** Turn deploy off again after a failed enable() whose add ran. */
    private function undo(string $user, string $domain, ApplyResult $added): void
    {
        if ($added->applied === [] || $added->applied[0]->command !== 'v-add-web-domain-deploy') {
            return;
        }
        try {
            $this->deploy->disable($user, $domain);
        } catch (ArcCommandException) {
            // The answer names the step that failed; this one is secondary
        }
    }

    /** @param  array<string, mixed>  $current */
    private function assertSupported(array $current): void
    {
        if (($current['SUPPORTED'] ?? 'yes') !== 'no') {
            return;
        }
        throw match ((string) ($current['REASON'] ?? '')) {
            'package' => new ApiException(ErrorCode::Forbidden, __('The package does not allow Git Deploy.'), 403),
            'backend' => new ApiException(ErrorCode::Disabled, __('This domain has no PHP-FPM backend.'), 409),
            default => new ApiException(ErrorCode::Disabled, __('The pool template of this domain does not allow the release folders.'), 409),
        };
    }

    /** @param  array<string, mixed>  $current */
    private function assertOn(string $domain, array $current): void
    {
        if (self::isOff($current)) {
            throw new ApiException(ErrorCode::NotExist, sprintf(__('Git Deploy is not on for %s.'), $domain), 404);
        }
    }

    /** @param  array<string, mixed>  $current */
    private static function isOff(array $current): bool
    {
        return in_array((string) ($current['STATE'] ?? ''), ['', 'off'], true);
    }

    /** A user the principal may see (the route's middleware) that exists; 404 otherwise. */
    private function assertVisible(string $user, Principal $principal, WebDomainPolicy $policy): void
    {
        abort_unless($policy->viewDeploy($principal, $user) && $this->actions->ownerExists($user), 404);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $credentials  PUBLIC_KEY and WEBHOOK_SECRET a command printed
     */
    private function resource(string $user, string $domain, array $row, array $credentials = []): WebDomainDeployResource
    {
        return new WebDomainDeployResource([
            'user' => $user, 'domain' => $domain, 'row' => $row,
            'credentials' => array_intersect_key($credentials, ['PUBLIC_KEY' => true, 'WEBHOOK_SECRET' => true]),
        ]);
    }

    /** The 202 answer of a deploy task, as GET /tasks/{id} reads it. */
    private function queued(string $user, string $domain, string $recordId, string $action): JsonResponse
    {
        if ($recordId === '') {
            throw new ApiException(ErrorCode::Server, __('The task could not be queued.'), 500);
        }
        $task = TaskMapper::fromSource(TaskId::queue($user, $recordId), [
            'ID' => $recordId, 'KIND' => 'deploy', 'ARGS' => trim($domain.' '.$action), 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => now()->format('Y-m-d H:i:s'), 'START' => '', 'END' => '', 'LOG' => '',
        ], 1);

        return (new TaskResource($task))->response()->setStatusCode(202)->header('Link', TaskLink::queue($user, $recordId));
    }
}
