<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\ExitCode;
use App\Arc\Secret;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CreateUserSecretRequest;
use App\Http\Requests\Api\V2\PutUserSecretValueRequest;
use App\Http\Requests\Api\V2\RollbackUserSecretRequest;
use App\Http\Requests\Api\V2\UpdateUserSecretRequest;
use App\Http\Resources\V2\UserSecretResource;
use App\Policies\UserSecretPolicy;
use App\Services\Keys\UserSecretManager;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The secret store of a user (E38 spec §8): path-named secrets encrypted on
 * the server, referenced from app environments as `{{secret:NAME}}`. Values
 * only travel into the API; no answer contains one. `{name}` in a path is
 * the secret name without its leading slash.
 *
 * @tags Secrets
 */
final class SecretController extends Controller
{
    public function __construct(
        private readonly UserSecretManager $secrets,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly TenancyDirectory $directory,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the secrets of a user
     *
     * Sorted by name; values are never listed.
     *
     * @operationId listSecrets
     */
    public function index(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): AnonymousResourceCollection
    {
        $this->assertExists($user);
        abort_unless($policy->view($principal, $user), 403);
        $rows = $this->secrets->forUser($user);
        ksort($rows, SORT_STRING);
        $page = $this->pages->slice($request, array_values($rows));

        return UserSecretResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Show a secret
     *
     * Its kept versions and the apps that reference it; never the value.
     *
     * @operationId getSecret
     */
    public function show(string $user, string $name, Principal $principal, UserSecretPolicy $policy): UserSecretResource
    {
        $this->assertExists($user);
        abort_unless($policy->view($principal, $user), 403);

        return new UserSecretResource($this->found($user, $name));
    }

    /**
     * Create a secret
     *
     * The value is stored as version 1, encrypted with the user's key.
     * A name that exists answers 409 `E_EXISTS`, more secrets than the
     * server allows 422 `E_LIMIT`.
     *
     * @operationId createSecret
     *
     * @status 201
     */
    public function store(CreateUserSecretRequest $request, string $user, Principal $principal, UserSecretPolicy $policy): UserSecretResource|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        $name = (string) $request->input('name');

        $steps = [new Step(sprintf(__('Add secret %s'), $name), 'v-add-user-secret',
            [$user, $name, Secret::file((string) $request->input('value')), (string) ($request->input('description') ?? '')])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $this->gate->assertApplied($this->runner->apply($steps));

        return (new UserSecretResource($this->found($user, ltrim($name, '/'))))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.secrets.show', ['user' => $user, 'name' => ltrim($name, '/')]));
    }

    /**
     * Change the description of a secret
     *
     * @operationId updateSecret
     */
    public function update(UpdateUserSecretRequest $request, string $user, string $name, Principal $principal, UserSecretPolicy $policy): UserSecretResource|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        $secret = $this->found($user, $name);

        $steps = [new Step(sprintf(__('Change the description of secret %s'), $secret['NAME']), 'v-change-user-secret-description',
            [$user, (string) $secret['NAME'], (string) ($request->input('description') ?? '')])];

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? new UserSecretResource($this->found($user, $name));
    }

    /**
     * Replace the value of a secret
     *
     * Stores the next version (the last five are kept) and answers the apps
     * that reference the secret. With `restart` the running ones are
     * restarted, so they read the new value; otherwise they keep the old
     * value until their next start. A value that one of those apps cannot
     * take (several lines, or quotes for a Docker app) answers 422
     * `E_INVALID` and changes nothing.
     *
     * @operationId putSecretValue
     */
    public function value(PutUserSecretValueRequest $request, string $user, string $name, Principal $principal, UserSecretPolicy $policy): JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        $secret = $this->found($user, $name);
        $restart = $request->boolean('restart');

        return $this->change($user, $name, $restart, new Step(sprintf(__('Change secret %s'), $secret['NAME']), 'v-change-user-secret',
            [$user, (string) $secret['NAME'], Secret::file((string) $request->input('value')), $restart ? 'yes' : 'no']));
    }

    /**
     * Roll a secret back to a kept version
     *
     * Copies the version into a new one, like putSecretValue.
     *
     * @operationId rollbackSecret
     */
    public function rollback(RollbackUserSecretRequest $request, string $user, string $name, Principal $principal, UserSecretPolicy $policy): JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        $secret = $this->found($user, $name);
        $restart = $request->boolean('restart');
        $version = (string) $request->integer('version');

        return $this->change($user, $name, $restart, new Step(sprintf(__('Roll secret %s back to version %s'), $secret['NAME'], $version),
            'v-rollback-user-secret', [$user, (string) $secret['NAME'], $version, $restart ? 'yes' : 'no']));
    }

    /**
     * Delete a secret
     *
     * A secret that an app references answers 409 `E_INUSE` with `used_by`,
     * unless `force=1`; such apps then fail to start until the reference is
     * removed or the secret is added again.
     *
     * @operationId deleteSecret
     *
     * @status 204
     */
    public function destroy(Request $request, string $user, string $name, Principal $principal, UserSecretPolicy $policy): Response|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        $secret = $this->found($user, $name);
        $force = $request->boolean('force');

        $steps = [new Step(sprintf(__('Delete secret %s'), $secret['NAME']), 'v-delete-user-secret',
            $force ? [$user, (string) $secret['NAME'], 'yes'] : [$user, (string) $secret['NAME']])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->runner->apply($steps);
        if (! $result->ok() && $result->exitCode === ExitCode::InUse->value) {
            throw new ApiException(ErrorCode::InUse, $result->error, 409,
                ['used_by' => UserSecretResource::apps(array_map('strval', (array) ($secret['USED_BY'] ?? [])))]);
        }
        $this->gate->assertApplied($result);

        return response()->noContent();
    }

    /** Run a change step and answer the secret with the apps it printed. */
    private function change(string $user, string $name, bool $restart, Step $step): JsonResponse
    {
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $result = $this->runner->apply([$step]);
        $this->gate->assertApplied($result);
        $printed = (string) $result->stdoutOf($step->command);

        return response()->json(['data' => [
            'secret' => (new UserSecretResource($this->found($user, $name)))->resolve(),
            /** the apps that reference the secret */
            'apps' => UserSecretResource::apps(UserSecretManager::usedBy($printed)),
            /** whether the running ones were restarted */
            'restarted' => $restart,
            /** running apps that did not restart; the value is changed anyway */
            'failed' => UserSecretResource::apps(UserSecretManager::failedRestarts($printed)),
        ]]);
    }

    /**
     * `v-list-user-secret` of the name in the path (without its leading slash), or 404.
     *
     * @return array<string, mixed>
     */
    private function found(string $user, string $name): array
    {
        $secret = $this->secrets->find($user, '/'.$name);
        abort_if($secret === null, 404);

        return $secret;
    }

    /** An account the principal reaches but that does not exist answers 404 (administrators reach every name). */
    private function assertExists(string $user): void
    {
        abort_unless(array_key_exists($user, $this->directory->all()), 404);
    }
}
