<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\Paginates;
use App\Api\SystemTaskStarter;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\SystemPackageResource;
use App\Policies\ServerPolicy;
use App\Services\Server\SystemPackages;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * System packages of the catalog for administrators (E34 batch 6 spec §5.5,
 * E5): Admin → Server → System packages as JSON. Installs and removals run
 * as the system task of the server: `202` with the task `sys:ID`. The
 * command decides what may be installed or removed, as for the page.
 *
 * @tags Server
 */
final class SystemPackageController extends Controller
{
    public function __construct(
        private readonly SystemPackages $packages,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the system packages
     *
     * The catalog of runtimes and tools that administrators install from the
     * distribution's archive, with their state, version and the web domains
     * that need them.
     *
     * @operationId listSystemPackages
     */
    public function index(Request $request, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewPackages($principal), 403);
        $page = $this->pages->slice($request, $this->packages->all());

        return SystemPackageResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Install a system package
     *
     * Installs the distribution packages of a catalog entry as the system
     * task. An entry that is installed answers `409 E_EXISTS`, one the
     * release of the server does not have `422`; another running system
     * task answers `409 E_INUSE` with `existing`.
     *
     * @operationId installSystemPackage
     *
     * @status 202
     */
    public function install(string $slug, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        return $this->start($slug, 'add', $principal, $policy, $starter);
    }

    /**
     * Remove a system package
     *
     * Removes an entry that the panel installed, as the system task. An entry
     * installed by the system, or needed by web domains, answers `403`;
     * another running system task answers `409 E_INUSE` with `existing`.
     *
     * @operationId removeSystemPackage
     *
     * @status 202
     */
    public function remove(string $slug, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        return $this->start($slug, 'delete', $principal, $policy, $starter);
    }

    private function start(string $slug, string $action, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->managePackages($principal), 403);
        $entry = $this->packages->find($slug);
        abort_if($entry === null, 404);
        $label = sprintf($action === 'add' ? __('Install %s') : __('Remove %s'), $entry['name']);

        return $starter->start(SystemTaskService::step($label, 'package', [$slug, $action]));
    }
}
