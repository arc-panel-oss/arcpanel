<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Api\SystemTaskStarter;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\InstallObjectStorageRequest;
use App\Http\Requests\Api\V2\SetObjectStorageEndpointRequest;
use App\Http\Resources\V2\ObjectStorageResource;
use App\Policies\ObjectStoragePolicy;
use App\Services\ObjectStorage\ObjectStorageService;
use App\Services\Server\SystemTaskService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;

/**
 * The object storage service of the server for administrators (E44 spec
 * §4, §5, §10).
 *
 * @tags Object storage
 */
final class ObjectStorageController extends Controller
{
    public function __construct(
        private readonly ObjectStorageService $storage,
        private readonly PlanGate $gate,
    ) {}

    /**
     * Get the object storage service
     *
     * Whether Silo is installed, its version, data path, endpoint and
     * state, and the totals of every account's buckets as the last repair
     * counted them.
     *
     * @operationId getObjectStorage
     */
    public function show(Principal $principal, ObjectStoragePolicy $policy): ObjectStorageResource
    {
        abort_unless($policy->viewServer($principal), 403);

        return new ObjectStorageResource($this->storage->server());
    }

    /**
     * Install object storage
     *
     * Installs Silo from the pinned and checked packages as the system
     * task, with its data in `data_path`. Installed already answers
     * `409 E_EXISTS`; another running system task `409 E_INUSE` with
     * `existing`.
     *
     * @operationId installObjectStorage
     *
     * @monitor
     *
     * @status 202
     */
    public function install(InstallObjectStorageRequest $request, Principal $principal, ObjectStoragePolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->manageServer($principal), 403);
        if ($this->storage->installed()) {
            throw new ApiException(ErrorCode::Exists, __('Object storage is installed.'), 409,
                existing: route('api.v2.system.object-storage.show', [], false));
        }
        $path = $request->input('data_path');
        $task = SystemTaskService::step(__('Install object storage'), 'plugin', ['silo', 'on']);
        if (is_string($path) && $path !== '') {
            $config = new Step(__('Set the data path of object storage'), 'v-change-sys-config-value', ['SILO_DATA', rtrim($path, '/')]);
            $this->gate->authorize([$config, $task]);
            if ($this->gate->dryRun()) {
                return $this->gate->preview([$config, $task]);
            }
            $this->gate->execute([$config], $this->storage->apply(...));
        }

        return $starter->start($task);
    }

    /**
     * Set the object storage endpoint
     *
     * Publishes the S3 API on `hostname`, a web domain of the administrator
     * with the internal template `silo-endpoint`, and requests a Let's
     * Encrypt certificate unless `letsencrypt` is false. `hostname: null`
     * switches the endpoint domain back to its default template. A web
     * domain of another account answers `409 E_EXISTS`.
     *
     * @operationId setObjectStorageEndpoint
     */
    public function endpoint(SetObjectStorageEndpointRequest $request, Principal $principal, ObjectStoragePolicy $policy): ObjectStorageResource|JsonResponse
    {
        abort_unless($policy->manageServer($principal), 403);
        abort_unless($this->storage->installed(), 404);
        $hostname = $request->input('hostname');
        $args = is_string($hostname) && $hostname !== ''
            ? [strtolower($hostname), $request->exists('letsencrypt') && ! $request->boolean('letsencrypt') ? 'no' : 'yes']
            : ['none'];
        $step = new Step($args[0] === 'none' ? __('Remove the object storage endpoint') : sprintf(__('Publish object storage on %s'), $args[0]),
            'v-change-sys-silo-endpoint', $args);

        return $this->gate->execute([$step], $this->storage->apply(...))
            ?? new ObjectStorageResource($this->storage->server());
    }
}
