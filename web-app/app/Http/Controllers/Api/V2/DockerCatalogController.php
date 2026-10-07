<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\DockerCatalogAppResource;
use App\Http\Resources\V2\DockerCatalogEntryResource;
use App\Policies\DockerPolicy;
use App\Services\Docker\DockerAppActions;
use App\Services\Docker\DockerCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Docker app catalog (E34 batch 4 spec §4.3, D44).
 *
 * @tags Docker catalog
 */
final class DockerCatalogController extends Controller
{
    public function __construct(
        private readonly DockerCatalog $catalog,
        private readonly DockerAppActions $actions,
    ) {}

    /**
     * List catalog apps visible to the caller
     *
     * @operationId listDockerCatalog
     */
    public function index(Principal $principal, DockerPolicy $policy): AnonymousResourceCollection
    {
        $apps = $this->actions->visibleCatalog($this->catalog->apps(), $policy->installAdminOnly($principal));

        return DockerCatalogEntryResource::collection($apps);
    }

    /**
     * Get a catalog app manifest
     *
     * @operationId getDockerCatalogApp
     */
    public function show(Request $request, string $slug, Principal $principal, DockerPolicy $policy): DockerCatalogAppResource|JsonResponse
    {
        abort_unless(preg_match(DockerCatalog::SLUG, $slug) === 1, 404);
        $version = (string) ($request->validate(['version' => ['nullable', 'string', 'regex:'.DockerCatalog::VERSION]])['version'] ?? '');
        $app = $this->catalog->app($slug, $version);
        abort_unless($this->actions->catalogAppVisible($app, $policy->installAdminOnly($principal)), 404);
        $app['variables'] = $this->actions->catalogVariablesForApi($app['variables'] ?? []);

        return new DockerCatalogAppResource($app);
    }
}
