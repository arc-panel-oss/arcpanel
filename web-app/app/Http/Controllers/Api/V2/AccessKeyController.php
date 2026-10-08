<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\KeyPermissions;
use App\Api\KeyScopedArcCommand;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\ArcCommand;
use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CreateAccessKeyRequest;
use App\Http\Resources\V2\AccessKeyResource;
use App\Http\Resources\V2\AccessKeySetResource;
use App\Http\Resources\V2\NewAccessKeyResource;
use App\Policies\AccessKeyPolicy;
use App\Services\Keys\AccessKey;
use App\Services\Keys\AccessKeyService;
use App\Services\Keys\AccessKeySets;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * API access keys (E34 batch 6 §4.6, D62) with the rules of the access key
 * page (AccessKeyPolicy): API_SYSTEM decides who may have keys (1: the root
 * administrator only, 2: everyone; an owner without keys answers 404),
 * administrators manage the keys of any user, everyone else its own. A key
 * gives only the permission sets it holds itself; the secret of a new key
 * is answered once.
 *
 * @tags Access keys
 */
final class AccessKeyController extends Controller
{
    public function __construct(
        private readonly AccessKeyService $keys,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly Paginates $pages,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * List the access keys of a user
     *
     * Oldest first, never with their secrets.
     *
     * @operationId listAccessKeys
     */
    public function index(Request $request, string $user, Principal $principal, AccessKeyPolicy $policy): AnonymousResourceCollection
    {
        $this->authorizeView($user, $principal, $policy);
        $page = $this->pages->slice($request, $this->keys->forUser($user));

        return AccessKeyResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create an access key
     *
     * `permissions` are sets of `GET /access-key-sets`: a key gives only
     * sets it holds itself, and only sets the owner may have (sets of role
     * `user`, every set for the root administrator). An empty list makes a
     * key that may run every command: only for an administrator's own
     * account, and only by a key without sets. The answer carries the
     * secret, once, with `Cache-Control: no-store`; no other answer, log or
     * dry run shows it.
     *
     * @operationId createAccessKey
     *
     * @status 201
     */
    public function store(CreateAccessKeyRequest $request, string $user, Principal $principal, AccessKeyPolicy $policy, KeyPermissions $calling): NewAccessKeyResource|JsonResponse
    {
        $this->authorizeManage($user, $principal, $policy);
        $sets = $request->sets();
        $this->assertGivable($sets, $user, $principal, $policy, $calling);
        $comment = trim((string) $request->input('comment', ''));

        $steps = [new Step(__('Create an access key'), 'v-add-access-key', [$user, implode(',', $sets), $comment, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->runner->apply($steps);
        $this->gate->assertApplied($result);
        $row = json_decode((string) $result->stdoutOf('v-add-access-key'), true);
        $key = AccessKey::fromArray(is_array($row) ? $row : [], withSecret: true);
        if ($key->id === '' || $key->secret === null) {
            throw new ApiException(ErrorCode::Server, 'The access key was created, but its secret could not be read.', 500);
        }

        return (new NewAccessKeyResource($key))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.access-keys.show', ['user' => $user, 'key' => $key->id]))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Get an access key
     *
     * @operationId getAccessKey
     */
    public function show(string $user, string $key, Principal $principal, AccessKeyPolicy $policy): AccessKeyResource
    {
        $this->authorizeView($user, $principal, $policy);

        return new AccessKeyResource($this->keys->find($user, $key));
    }

    /**
     * Delete an access key
     *
     * A key may always delete itself, whatever its permission sets: to
     * rotate a key, create the new one, then delete the old one with it.
     * Deleting another key needs a set that allows it.
     *
     * @operationId deleteAccessKey
     *
     * @status 204
     */
    public function destroy(string $user, string $key, Principal $principal, AccessKeyPolicy $policy, KeyPermissions $calling, ArcCommand $arc, SysConfig $config): Response|JsonResponse
    {
        $this->authorizeManage($user, $principal, $policy);
        $steps = [new Step(sprintf(__('Delete access key %s'), $key), 'v-delete-access-key', [$key])];
        if ($key !== $calling->keyId || $user !== $calling->user) {
            $this->keys->find($user, $key);

            return $this->gate->execute($steps, $this->runner->apply(...)) ?? response()->noContent();
        }

        // The calling key revokes itself (D62): it authenticated, so it exists,
        // and no permission set is needed to give up its own access.
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $unscoped = $arc instanceof KeyScopedArcCommand ? $arc->inner() : $arc;
        $this->gate->assertApplied((new PlanRunner($unscoped, $config))->apply($steps));

        return response()->noContent();
    }

    /**
     * List the permission sets a key may give
     *
     * The sets the caller may give to a key of `user` (default: the owner
     * of the calling key): those the owner may have, and, for a key with
     * sets, only those the calling key holds.
     *
     * @operationId listAccessKeySets
     */
    public function sets(Request $request, Principal $principal, AccessKeyPolicy $policy, OwnerScope $scope, KeyPermissions $calling): AnonymousResourceCollection
    {
        $owner = (string) ($request->validate([
            /** The owner of the keys to create. */
            'user' => ['sometimes', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
        ])['user'] ?? $principal->effectiveUser());
        $scope->assertVisible($owner);
        abort_unless(array_key_exists($owner, $this->directory->all()) && $policy->available($owner), 404);
        abort_unless($policy->manage($principal, $owner), 403);

        $rows = $this->keys->permissionSets();
        $names = $policy->allowedSets($owner, $rows);
        if (! $calling->unrestricted()) {
            $names = array_values(array_intersect($names, $calling->sets));
        }
        $sets = array_map(fn (string $name) => [
            'name' => $name,
            'api' => (string) ($rows[$name]['API_VERSION'] ?? '1') === '2' ? 2 : 1,
            'description' => AccessKeySets::description($name),
        ], $names);
        $page = $this->pages->slice($request, $sets);

        return AccessKeySetResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * D62: sets the owner may have (422 otherwise), held by the calling key
     * (403 otherwise); no set only for an administrator's own key, made by
     * a key without sets.
     *
     * @param  list<string>  $sets
     */
    private function assertGivable(array $sets, string $user, Principal $principal, AccessKeyPolicy $policy, KeyPermissions $calling): void
    {
        if ($sets === []) {
            if ($principal->actingRole() !== Role::Admin || $user !== $principal->effectiveUser()) {
                throw ValidationException::withMessages(['permissions' => sprintf(__('Field "%s" can not be blank.'), __('Permissions'))]);
            }
            if (! $calling->unrestricted()) {
                throw new ApiException(ErrorCode::Forbidden, 'A key with permission sets cannot create a key without sets.', 403);
            }

            return;
        }
        $allowed = $policy->allowedSets($user, $this->keys->permissionSets());
        foreach ($sets as $set) {
            if (! in_array($set, $allowed, true)) {
                throw ValidationException::withMessages(['permissions' => sprintf(__('API %s not allowed'), $set)]);
            }
        }
        $missing = $calling->unrestricted() ? [] : array_values(array_diff($sets, $calling->sets));
        if ($missing !== []) {
            throw new ApiException(ErrorCode::Forbidden, 'The access key may only give the permission sets it holds; it does not hold '.implode(', ', $missing).'.', 403);
        }
    }

    private function authorizeView(string $user, Principal $principal, AccessKeyPolicy $policy): void
    {
        abort_unless(array_key_exists($user, $this->directory->all()) && $policy->available($user), 404);
        abort_unless($policy->view($principal, $user), 403);
    }

    private function authorizeManage(string $user, Principal $principal, AccessKeyPolicy $policy): void
    {
        abort_unless(array_key_exists($user, $this->directory->all()) && $policy->available($user), 404);
        abort_unless($policy->manage($principal, $user), 403);
    }
}
