<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\AddSshKeyRequest;
use App\Http\Resources\V2\SshKeyResource;
use App\Policies\SshKeyPolicy;
use App\Services\Keys\SshKeyService;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * SSH keys of a user's authorized_keys (E34 batch 6 §4.4), with the rules
 * of the SSH keys page (SshKeyPolicy): administrators manage the keys of any
 * account, resellers and users only their own. The file manager's own key
 * is neither listed nor counted.
 *
 * @tags SSH keys
 */
final class SshKeyController extends Controller
{
    public function __construct(
        private readonly SshKeyService $keys,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly TenancyDirectory $directory,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the SSH keys of a user
     *
     * @operationId listSshKeys
     */
    public function index(Request $request, string $user, Principal $principal, SshKeyPolicy $policy): AnonymousResourceCollection
    {
        $this->assertExists($user);
        abort_unless($policy->view($principal, $user), 403);
        $page = $this->pages->slice($request, self::rows($this->keys->forUser($user)));

        return SshKeyResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add an SSH key
     *
     * A key line without a comment gets one. A key whose comment or
     * fingerprint the user already has answers 409 `E_EXISTS`.
     *
     * @operationId addSshKey
     *
     * @status 201
     */
    public function store(AddSshKeyRequest $request, string $user, Principal $principal, SshKeyPolicy $policy): SshKeyResource|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        $existing = $this->keys->forUser($user);
        $line = SshKeyService::prepare((string) $request->input('key'), $existing);
        $fingerprint = SshKeyService::fingerprint((string) $request->input('key'));
        if ($line === null || ($fingerprint !== null && in_array($fingerprint, $existing, true))) {
            throw new ApiException(ErrorCode::Exists, __('SSH Key already exists.'), 409);
        }
        $id = implode(' ', array_slice(preg_split('/\s+/', $line) ?: [], 2));

        $steps = [new Step(__('Add the SSH key'), 'v-add-user-ssh-key', [$user, $line])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->runner->apply($steps);
        if (! $result->ok() && $result->exitCode === ExitCode::Parsing->value) {
            throw new ApiException(ErrorCode::Validation, __('The key is not a valid SSH public key.'), 422, ['fields' => ['key' => [__('The key is not a valid SSH public key.')]]]);
        }
        $this->gate->assertApplied($result);

        $added = $this->keys->forUser($user);

        return (new SshKeyResource(['id' => $id, 'fingerprint' => $added[$id] ?? (string) $fingerprint]))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.ssh-keys.index', ['user' => $user]));
    }

    /**
     * Delete an SSH key
     *
     * `id` is the key's comment, URL-encoded.
     *
     * @operationId deleteSshKey
     *
     * @status 204
     */
    public function destroy(string $user, string $id, Principal $principal, SshKeyPolicy $policy): Response|JsonResponse
    {
        $this->assertExists($user);
        abort_unless($policy->manage($principal, $user), 403);
        abort_unless(array_key_exists($id, $this->keys->forUser($user)), 404);

        return $this->gate->execute([new Step(sprintf(__('Delete SSH key %s'), $id), 'v-delete-user-ssh-key', [$user, $id])], $this->runner->apply(...))
            ?? response()->noContent();
    }

    /**
     * @param  array<string, string>  $keys  id => fingerprint
     * @return list<array{id: string, fingerprint: string}>
     */
    private static function rows(array $keys): array
    {
        $rows = [];
        foreach ($keys as $id => $fingerprint) {
            $rows[] = ['id' => (string) $id, 'fingerprint' => $fingerprint];
        }

        return $rows;
    }

    /** An account the principal reaches but that does not exist answers 404 (administrators reach every name). */
    private function assertExists(string $user): void
    {
        abort_unless(array_key_exists($user, $this->directory->all()), 404);
    }
}
