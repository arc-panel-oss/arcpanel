<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreS3KeyRequest;
use App\Http\Resources\V2\S3KeyResource;
use App\Policies\ObjectStoragePolicy;
use App\Services\ObjectStorage\ObjectStorageService;
use App\Services\ObjectStorage\S3Key;
use App\Services\WebDomains\ApplyResult;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S3 keys of a user's object storage (E44 spec §7, §10): service accounts of
 * the account, called S3 keys so they are not confused with panel access
 * keys. Without object storage on the server every route answers 404.
 *
 * @tags Object storage
 */
final class S3KeyController extends Controller
{
    public function __construct(
        private readonly ObjectStorageService $storage,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * List the S3 keys of a user
     *
     * Newest first, without secrets, which are never kept.
     *
     * @operationId listS3Keys
     */
    public function index(Request $request, string $user, Principal $principal, ObjectStoragePolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->storage->keys($user)));

        return S3KeyResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create an S3 key
     *
     * The answer holds `secret`, once: no request can read it again. With
     * `save_as` the secret is also saved in the account's secret store,
     * where apps reference it as `{{secret:NAME}}`. An account holds at
     * most 20 keys (`422 E_LIMIT`).
     *
     * @operationId createS3Key
     *
     * @status 201
     */
    public function store(StoreS3KeyRequest $request, string $user, Principal $principal, ObjectStoragePolicy $policy): S3KeyResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user), 403);
        $name = (string) $request->input('name');
        $bucket = (string) ($request->input('bucket') ?? '');
        $access = (string) ($request->input('access') ?? 'rw');
        $expires = (string) ($request->input('expires_on') ?? '');
        $saveAs = $request->input('save_as');
        if ($bucket !== '' && ! isset($this->storage->buckets($user)[$bucket])) {
            throw new NotFoundHttpException;
        }

        $step = ObjectStorageService::planCreateKey($user, $name, $bucket, $access, $expires);
        $this->gate->authorize([$step], is_string($saveAs) && $saveAs !== '' ? ['v-add-user-secret'] : []);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        [$result, $key, $secret] = $this->storage->createKey($user, $name, $bucket, $access, $expires);
        $this->gate->assertApplied($result->ok() ? new ApplyResult([$step]) : new ApplyResult([], $step, $result->errorMessage(), $result->exitCode));
        if ($key === null || $key === '' || $secret === null || $secret === '') {
            throw new ApiException(ErrorCode::Server, __('The S3 key was created but its answer could not be read.'), 500);
        }

        $resource = new S3KeyResource($this->storage->keys($user)[$key] ?? new S3Key($user, $key, $name, $bucket, $access, $expires, false, '', ''));
        $resource->secret = $secret;
        if (is_string($saveAs) && $saveAs !== '') {
            $saved = $this->storage->saveSecret($user, $saveAs, $key, $secret);
            if ($saved->ok()) {
                $resource->savedAs = $saveAs;
            }
        }

        return $resource->response()->setStatusCode(201)
            ->header('Location', route('api.v2.s3-keys.index', ['user' => $user]));
    }

    /**
     * Delete an S3 key
     *
     * The key stops working at once.
     *
     * @operationId deleteS3Key
     *
     * @status 204
     */
    public function destroy(string $user, string $key, Principal $principal, ObjectStoragePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user), 403);
        if (! isset($this->storage->keys($user)[$key])) {
            throw new NotFoundHttpException;
        }

        return $this->gate->execute([ObjectStorageService::planDeleteKey($user, $key)], $this->storage->apply(...))
            ?? response()->noContent();
    }

    private function assertOwner(string $user, Principal $principal, ObjectStoragePolicy $policy): void
    {
        abort_unless($this->storage->installed(), 404);
        abort_unless($policy->view($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }
}
