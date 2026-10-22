<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StartFileFetchRequest;
use App\Http\Resources\V2\FileFetchResource;
use App\Policies\FilePolicy;
use App\Services\Files\FetchRequest;
use App\Services\Files\FileFetch;
use App\Services\Files\FilePath;
use App\Services\Files\RemoteFetches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
 * §8): the server downloads a URL into a folder of the user's home, as the
 * user, in the background. Private and local addresses are refused unless
 * an administrator's key allows them. `FILE_FETCH='no'` answers 404.
 *
 * @tags Files
 */
final class FileFetchController extends Controller
{
    public function __construct(
        private readonly RemoteFetches $fetches,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List downloads from URLs
     *
     * The account's downloads, newest first: waiting and running ones, and
     * finished ones for 24 hours. `bytes` grows while a download runs.
     * Paged with `limit` and `offset` (D20).
     *
     * @operationId listFileFetches
     */
    public function index(Request $request, string $user, Principal $principal, FilePolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->view($principal, $user), 404);
        $page = $this->pages->slice($request, $this->fetches->list($user));

        return FileFetchResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Show a download from a URL
     *
     * @operationId getFileFetch
     */
    public function show(string $user, string $id, Principal $principal, FilePolicy $policy): FileFetchResource
    {
        abort_unless($policy->view($principal, $user), 404);

        return new FileFetchResource($this->find($user, $id));
    }

    /**
     * Start a download from a URL
     *
     * Queues a GET of `url` into the folder `path`, run as the user: 3
     * downloads run at once per account and at most 20 wait or run (`422`
     * `E_LIMIT` above). The answer is `201` with the queued download and its
     * `Location`; follow it with getFileFetch until `status` is `done`,
     * `skipped`, `failed` or `cancelled`. Every address of the host and of each redirect
     * (5 at most) must be public unless `allow_private` is set by an
     * administrator key; a refused address ends the download as `failed`
     * with `error` `blocked`. Files are limited by `FILE_FETCH_MAX_SIZE`
     * (2G by default) and 1 hour. The headers and credentials are never
     * answered, also not by a dry run.
     *
     * @operationId startFileFetch
     *
     * @status 201
     */
    public function store(StartFileFetchRequest $request, string $user, Principal $principal, FilePolicy $policy): FileFetchResource|JsonResponse
    {
        abort_unless($policy->view($principal, $user), 404);
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validated();
        $private = (bool) ($data['allow_private'] ?? false);
        if ($private && $principal->actingRole() !== Role::Admin) {
            throw ValidationException::withMessages(['allow_private' => __('Only administrators may download from private addresses.')]);
        }
        $fetch = FetchRequest::from([
            'url' => $data['url'],
            'headers' => array_map(fn (array $h) => ['name' => $h['name'], 'value' => (string) ($h['value'] ?? '')], $data['headers'] ?? []),
            'auth' => $data['auth'] ?? [],
            'name' => $data['name'] ?? '',
        ]);
        $step = $this->fetches->startStep($user, FilePath::from($data['path'] ?? ''), $fetch, $data['on_conflict'] ?? 'rename', $private);
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $id = $this->fetches->start($user, FilePath::from($data['path'] ?? ''), $fetch, $data['on_conflict'] ?? 'rename', $private);

        return (new FileFetchResource($this->find($user, $id)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.files.fetches.show', ['user' => $user, 'id' => $id]));
    }

    /**
     * Cancel or remove a download from a URL
     *
     * Cancels a waiting or running download (its partial file is removed),
     * or removes a finished one from the list. Answers the download as it
     * ended; a removed one answers its last state.
     *
     * @operationId cancelFileFetch
     */
    public function destroy(Request $request, string $user, string $id, Principal $principal, FilePolicy $policy): FileFetchResource|JsonResponse
    {
        abort_unless($policy->view($principal, $user), 404);
        abort_unless($policy->manage($principal, $user), 403);
        $fetch = $this->find($user, $id);
        $step = $this->fetches->deleteStep($user, $id);
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $this->fetches->delete($user, $id);

        return new FileFetchResource($this->fetches->find($user, $id) ?? $fetch);
    }

    private function find(string $user, string $id): FileFetch
    {
        $fetch = $this->fetches->find($user, $id);
        abort_if($fetch === null, 404);

        return $fetch;
    }
}
