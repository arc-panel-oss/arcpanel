<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CopyPackageRequest;
use App\Http\Requests\Api\V2\PackageRequest;
use App\Http\Requests\Api\V2\StorePackageRequest;
use App\Http\Requests\Api\V2\UpdatePackageRequest;
use App\Http\Resources\V2\PackageResource;
use App\Policies\PackagePolicy;
use App\Services\Packages\PackageActions;
use App\Services\Packages\PackageRecord;
use App\Services\Packages\PackageService;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Hosting packages (E34 spec §6.2, E11 spec §8): administrators manage every
 * package; a reseller sees the packages it may assign and, with
 * POLICY_RESELLER_CREATE_PACKAGES, creates and changes its own within its
 * own limits.
 *
 * @tags Packages
 */
final class PackageController extends Controller
{
    public function __construct(
        private readonly PackageService $packages,
        private readonly PackageActions $actions,
        private readonly UserService $users,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List packages
     *
     * Every package but `system` for an administrator, the packages a
     * reseller may assign for a reseller.
     *
     * @operationId listPackages
     */
    public function index(Request $request, Principal $principal, PackagePolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $page = $this->pages->slice($request, array_values($this->actions->visible($principal)));

        return PackageResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a package
     *
     * Fields left out take the values of the add form of the panel. A
     * reseller's package is stored as RESELLER_NAME and may not exceed the
     * reseller's own limits; the `reseller` settings are an administrator's.
     * A repeated call answers 409 `E_EXISTS`.
     *
     * @operationId createPackage
     *
     * @status 201
     */
    public function store(StorePackageRequest $request, Principal $principal, PackagePolicy $policy): PackageResource|JsonResponse
    {
        abort_unless($policy->create($principal), 403);
        $name = (string) $request->input('name');
        $stored = PackageActions::storedName($principal, $name);
        if ($this->packages->exists($stored)) {
            throw new ApiException(ErrorCode::Exists, __('A package with this name exists.'), 409);
        }
        $base = PackageService::defaults(($this->packages->all()['default'] ?? null)?->get('NS') ?? '');
        $steps = PackageRequest::withApiFields(fn () => $this->actions->planCreate($principal, $name, $request->packageValues($base, $this->packages->resourcesLimit()),
            $request->tenancyValues($base), $request->portsValue($base), $request->nodeValues($base), $request->dockerValues($base), $request->phpValues($base),
            $request->processValues($base)));

        if ($preview = $this->gate->execute($steps, $this->users->apply(...))) {
            return $preview;
        }

        return (new PackageResource($this->packages->find($stored)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.packages.show', ['package' => $stored]));
    }

    /**
     * Get a package
     *
     * @operationId getPackage
     */
    public function show(string $package, Principal $principal, PackagePolicy $policy): PackageResource
    {
        return new PackageResource($this->visible($package, $principal, $policy));
    }

    /**
     * Update a package
     *
     * The fields left out keep their value; the package is applied to its
     * users. An administrator renames it with `name`.
     *
     * @operationId updatePackage
     */
    public function update(UpdatePackageRequest $request, string $package, Principal $principal, PackagePolicy $policy): PackageResource|JsonResponse
    {
        $record = $this->visible($package, $principal, $policy);
        abort_unless($policy->update($principal, $record), 403);
        $newName = PackageActions::resellerOf($principal) === '' ? (string) $request->input('name', $record->name) : $record->name;
        $base = array_merge(PackageService::defaults(''), $record->data);
        $steps = PackageRequest::withApiFields(fn () => $this->actions->planUpdate($principal, $record, $newName, $request->packageValues($base, $this->packages->resourcesLimit()),
            $request->tenancyValues($base), $request->portsValue($base), $request->nodeValues($base), $request->dockerValues($base), $request->phpValues($base),
            $request->processValues($base)));

        return $this->gate->execute($steps, $this->users->apply(...))
            ?? new PackageResource($this->packages->find($newName));
    }

    /**
     * Copy a package
     *
     * Administrators only; the copy has the reseller, port, Node.js and
     * Docker settings of the original.
     *
     * @operationId copyPackage
     *
     * @status 201
     */
    public function copy(CopyPackageRequest $request, string $package, Principal $principal, PackagePolicy $policy): PackageResource|JsonResponse
    {
        $record = $this->visible($package, $principal, $policy);
        abort_unless($policy->abilities($principal, $record)['copy'], 403);
        $name = (string) $request->input('name');
        if ($this->packages->exists($name)) {
            throw new ApiException(ErrorCode::Exists, __('A package with this name exists.'), 409);
        }

        if ($preview = $this->gate->execute($this->actions->planCopy($record, $name), $this->users->apply(...))) {
            return $preview;
        }

        return (new PackageResource($this->packages->find($name)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.packages.show', ['package' => $name]));
    }

    /**
     * Delete a package
     *
     * A package with users answers 409 `E_INUSE`; `default` cannot be deleted.
     *
     * @operationId deletePackage
     *
     * @status 204
     */
    public function destroy(string $package, Principal $principal, PackagePolicy $policy): Response|JsonResponse
    {
        $record = $this->visible($package, $principal, $policy);
        abort_unless($policy->delete($principal, $record), 403);
        if (($reason = $this->actions->deleteRefusal($record)) !== null) {
            throw new ApiException(ErrorCode::InUse, $reason, 409);
        }

        return $this->gate->execute($this->actions->planDelete($principal, $record), $this->users->apply(...))
            ?? response()->noContent();
    }

    /** A package the principal may see; users answer 403, packages outside the principal's reach 404. */
    private function visible(string $package, Principal $principal, PackagePolicy $policy): PackageRecord
    {
        abort_unless($policy->viewAny($principal), 403);

        return $this->actions->visibleOr404($principal, $package);
    }
}
