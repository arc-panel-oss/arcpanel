<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\CrossUserList;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreBucketRequest;
use App\Http\Requests\Api\V2\UpdateBucketRequest;
use App\Http\Resources\V2\BucketResource;
use App\Policies\ObjectStoragePolicy;
use App\Services\ObjectStorage\AccountStorage;
use App\Services\ObjectStorage\Bucket;
use App\Services\ObjectStorage\ObjectStorageService;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Object storage buckets of a user (E44 spec §6, §10). Without object
 * storage on the server every route answers 404.
 *
 * @tags Object storage
 */
final class BucketController extends Controller
{
    public function __construct(
        private readonly ObjectStorageService $storage,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * List the buckets of every user
     *
     * Every user's buckets for an administrator's key, those of the reseller
     * and its customers for a reseller's key; a user's key answers 403
     * (`listBuckets` covers it). Sorted by user, then name. With
     * `updated_since`, only the users whose buckets changed at or after that
     * time are returned, each with all its buckets (E43 B2).
     *
     * @operationId listAllBuckets
     */
    public function indexAll(Request $request, CrossUserList $lists): AnonymousResourceCollection
    {
        abort_unless($this->storage->installed(), 404);
        $page = $lists->page($request, 'v-list-sys-buckets', Bucket::fromArray(...), fn (Bucket $bucket) => $bucket->name);

        return BucketResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the buckets of a user
     *
     * `meta.storage` gives the limits of the account's package, what its
     * buckets hold against them and how to connect: path-style addressing
     * on `endpoint` in `region`.
     *
     * @operationId listBuckets
     */
    public function index(Request $request, string $user, Principal $principal, ObjectStoragePolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->storage->buckets($user)));
        $page['meta']['storage'] = self::storageMeta($this->storage->account($user));

        return BucketResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a bucket
     *
     * Bucket names are unique on the server. The bucket's hard quota and
     * the quotas of the account's other buckets stay within its package.
     * Silo refuses one upload larger than the quota at once; uploads that
     * add up beyond it are refused from its next scan, about a minute later.
     *
     * @operationId createBucket
     *
     * @status 201
     *
     * @conflict E_EXISTS The name is taken on the server; `existing` names the account's own bucket when it is the account's.
     */
    public function store(StoreBucketRequest $request, string $user, Principal $principal, ObjectStoragePolicy $policy): BucketResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user), 403);
        $name = (string) $request->input('name');
        if (isset($this->storage->buckets($user)[$name])) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Bucket %s exists.'), $name), 409,
                existing: route('api.v2.buckets.show', ['user' => $user, 'bucket' => $name], false));
        }

        $step = ObjectStorageService::planCreateBucket($user, $name, (int) $request->input('quota_mb'), $request->boolean('versioning'));
        if ($preview = $this->gate->execute([$step], $this->storage->apply(...))) {
            return $preview;
        }

        return (new BucketResource($this->storage->bucket($user, $name)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.buckets.show', ['user' => $user, 'bucket' => $name]));
    }

    /**
     * Get a bucket
     *
     * @operationId getBucket
     */
    public function show(string $user, string $bucket, Principal $principal, ObjectStoragePolicy $policy): BucketResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new BucketResource($this->storage->bucket($user, $bucket));
    }

    /**
     * Change a bucket
     *
     * Fields left out keep their value. Raising the quota checks the
     * package; nothing runs when nothing changes.
     *
     * @operationId updateBucket
     */
    public function update(UpdateBucketRequest $request, string $user, string $bucket, Principal $principal, ObjectStoragePolicy $policy): BucketResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user), 403);
        $record = $this->storage->bucket($user, $bucket);
        $quota = $request->exists('quota_mb') ? (int) $request->input('quota_mb') : $record->quotaMb;
        $versioning = $request->exists('versioning') ? $request->boolean('versioning') : $record->versioning;
        $steps = $quota === $record->quotaMb && $versioning === $record->versioning
            ? [] : [ObjectStorageService::planUpdateBucket($record, $quota, $versioning)];

        return $this->gate->execute($steps, $this->storage->apply(...))
            ?? new BucketResource($this->storage->bucket($user, $bucket));
    }

    /**
     * Delete a bucket
     *
     * Deletes every object and version in it, and the S3 keys limited to
     * it.
     *
     * @operationId deleteBucket
     *
     * @status 204
     */
    public function destroy(string $user, string $bucket, Principal $principal, ObjectStoragePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user), 403);
        $this->storage->bucket($user, $bucket);

        return $this->gate->execute([ObjectStorageService::planDeleteBucket($user, $bucket)], $this->storage->apply(...))
            ?? response()->noContent();
    }

    /** @return array<string, mixed> */
    public static function storageMeta(AccountStorage $account): array
    {
        return [
            'allowed' => $account->allowed,
            'buckets' => $account->buckets,
            'buckets_limit' => $account->bucketsLimit,
            'quota_used_mb' => $account->quotaUsedMb,
            'quota_limit_mb' => $account->quotaLimitMb,
            'used_bytes' => $account->usedBytes,
            'objects' => $account->objects,
            'keys' => $account->keys,
            'keys_limit' => $account->keysLimit,
            'backup_objects' => $account->backup,
            'endpoint' => $account->endpoint !== '' ? $account->endpoint : null,
            'region' => $account->region,
            'path_style' => true,
        ];
    }

    private function assertOwner(string $user, Principal $principal, ObjectStoragePolicy $policy): void
    {
        abort_unless($this->storage->installed(), 404);
        abort_unless($policy->view($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }
}
