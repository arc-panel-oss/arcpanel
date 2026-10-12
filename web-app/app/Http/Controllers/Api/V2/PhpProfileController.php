<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\Secret;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\PhpProfileRequest;
use App\Http\Resources\V2\PhpProfileResource;
use App\Policies\ServerPolicy;
use App\Services\Php\PhpManager;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * PHP-FPM profiles for administrators (E41 spec §7, §9): named pool
 * settings that packages allow and web domains choose. Changing a profile
 * rewrites and reloads every pool that uses it.
 *
 * @tags Server
 */
final class PhpProfileController extends Controller
{
    public function __construct(
        private readonly PhpManager $php,
        private readonly Paginates $pages,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
    ) {}

    /**
     * List the PHP-FPM profiles
     *
     * @operationId listPhpProfiles
     */
    public function index(Request $request, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewPhp($principal), 403);
        $page = $this->pages->slice($request, array_values($this->php->profiles()));

        return PhpProfileResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Get a PHP-FPM profile
     *
     * @operationId getPhpProfile
     */
    public function show(string $profile, Principal $principal, ServerPolicy $policy): PhpProfileResource
    {
        abort_unless($policy->viewPhp($principal), 403);

        return new PhpProfileResource($this->find($profile));
    }

    /**
     * Create a PHP-FPM profile
     *
     * A name in use answers `409 E_EXISTS`; relations the command checks
     * (spare servers, upload limits) answer `422 E_INVALID`.
     *
     * @operationId createPhpProfile
     *
     * @status 201
     */
    public function store(PhpProfileRequest $request, Principal $principal, ServerPolicy $policy): PhpProfileResource|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $name = (string) $request->input('name');
        $step = new Step(sprintf(__('Add profile %s'), $name), 'v-add-sys-php-profile', [$name, Secret::file(PhpManager::profileFile($request->values()))]);
        if ($preview = $this->gate->execute([$step], $this->runner->apply(...))) {
            return $preview;
        }

        return (new PhpProfileResource($this->find($name)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.php.profiles.show', ['profile' => $name]));
    }

    /**
     * Change a PHP-FPM profile
     *
     * Rewrites the pool of every web domain on the profile and reloads PHP-FPM;
     * when a version does not run afterwards, the previous profile comes back
     * (`500 E_RESTART`).
     *
     * @operationId updatePhpProfile
     */
    public function update(PhpProfileRequest $request, string $profile, Principal $principal, ServerPolicy $policy): PhpProfileResource|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->find($profile);
        $step = new Step(sprintf(__('Change profile %s'), $profile), 'v-change-sys-php-profile', [$profile, Secret::file(PhpManager::profileFile($request->values()))]);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? new PhpProfileResource($this->fresh($profile));
    }

    /**
     * Delete a PHP-FPM profile
     *
     * The `default` profile cannot be deleted (`403`). A profile that web
     * domains use or packages name answers `409 E_INUSE` unless `force=1`,
     * which moves the domains to `default` and removes it from the packages.
     *
     * @operationId deletePhpProfile
     */
    public function destroy(Request $request, string $profile, Principal $principal, ServerPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->managePhp($principal), 403);
        $this->find($profile);
        $force = $request->boolean('force');
        $step = new Step(sprintf(__('Delete profile %s'), $profile), 'v-delete-sys-php-profile', $force ? [$profile, 'yes'] : [$profile]);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? response()->noContent();
    }

    /** @return array<string, mixed> */
    private function find(string $profile): array
    {
        return $this->php->profile($profile) ?? throw new ApiException(ErrorCode::NotExist, sprintf(__('Profile %s does not exist.'), $profile), 404);
    }

    /**
     * The profile as listed after a change (the list is read once per request).
     *
     * @return array<string, mixed>
     */
    private function fresh(string $profile): array
    {
        $this->php->forget();

        return $this->find($profile);
    }
}
