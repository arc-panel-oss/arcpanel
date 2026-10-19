<?php

namespace App\Http\Controllers\ObjectStorage;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ObjectStorage\BucketRequest;
use App\Http\Requests\ObjectStorage\S3KeyRequest;
use App\Policies\ObjectStoragePolicy;
use App\Services\ObjectStorage\ObjectStorageService;
use App\Services\WebDomains\ApplyResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Object Storage page of an account (E44 spec §11): its buckets with
 * quota, usage and versioning, its S3 keys and how to connect. A new key's
 * secret is shown on the answer of the form, once, never stored.
 */
final class ObjectStoragePageController extends Controller
{
    public function __construct(private readonly ObjectStorageService $storage) {}

    /** GET /object-storage: the page of the effective account. */
    public function entry(Principal $principal): RedirectResponse
    {
        return redirect()->route('storage.show', ['user' => $principal->effectiveUser()]);
    }

    public function show(string $user, Principal $principal, ObjectStoragePolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);

        return view('object-storage.index', [
            'user' => $user,
            'account' => $this->storage->account($user),
            'buckets' => array_values($this->storage->buckets($user)),
            'keys' => array_values($this->storage->keys($user)),
            'canManage' => $policy->manage($principal, $user),
        ]);
    }

    public function storeBucket(BucketRequest $request, string $user, Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $name = (string) $request->input('name');
        $step = ObjectStorageService::planCreateBucket($user, $name, (int) $request->input('quota'), $request->boolean('versioning'));

        return $this->finish($user, $this->storage->apply([$step]), sprintf(__('Bucket %s has been created.'), $name));
    }

    public function updateBucket(BucketRequest $request, string $user, string $bucket, Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $record = $this->storage->bucket($user, $bucket);
        $step = ObjectStorageService::planUpdateBucket($record, (int) $request->input('quota'), $request->boolean('versioning'));

        return $this->finish($user, $this->storage->apply([$step]), __('Changes have been saved.'));
    }

    public function destroyBucket(string $user, string $bucket, Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $this->storage->bucket($user, $bucket);

        return $this->finish($user, $this->storage->apply([ObjectStorageService::planDeleteBucket($user, $bucket)]),
            sprintf(__('Bucket %s has been deleted.'), $bucket));
    }

    public function storeKey(S3KeyRequest $request, string $user, Principal $principal, ObjectStoragePolicy $policy): View|RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $bucket = (string) ($request->input('bucket') ?? '');
        if ($bucket !== '' && ! isset($this->storage->buckets($user)[$bucket])) {
            throw new NotFoundHttpException;
        }
        $name = (string) $request->input('name');
        [$result, $key, $secret] = $this->storage->createKey($user, $name, $bucket, (string) $request->input('access'), (string) ($request->input('expires') ?? ''));
        if (! $result->ok() || $key === null || $key === '' || $secret === null || $secret === '') {
            return redirect()->route('storage.show', ['user' => $user])->withInput()
                ->with('error', $result->ok() ? __('The S3 key was created but its answer could not be read.') : $result->errorMessage());
        }
        $saveAs = $request->input('save_as');
        $saved = null;
        if (is_string($saveAs) && $saveAs !== '') {
            $saved = $this->storage->saveSecret($user, $saveAs, $key, $secret);
        }

        return view('object-storage.key-created', [
            'user' => $user,
            'account' => $this->storage->account($user),
            'name' => $name,
            'key' => $key,
            'secret' => $secret,
            'savedAs' => $saved?->ok() ? $saveAs : null,
            'saveError' => $saved !== null && ! $saved->ok() ? $saved->errorMessage() : null,
        ]);
    }

    public function destroyKey(string $user, string $key, Principal $principal, ObjectStoragePolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        if (! isset($this->storage->keys($user)[$key])) {
            throw new NotFoundHttpException;
        }

        return $this->finish($user, $this->storage->apply([ObjectStorageService::planDeleteKey($user, $key)]),
            sprintf(__('S3 key %s has been deleted.'), $key));
    }

    private function finish(string $user, ApplyResult $result, string $ok): RedirectResponse
    {
        $back = redirect()->route('storage.show', ['user' => $user]);
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->error);
        }

        return $back->with('ok', $ok);
    }
}
