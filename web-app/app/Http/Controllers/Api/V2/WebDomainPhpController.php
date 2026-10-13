<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SetWebDomainPhpRequest;
use App\Http\Resources\V2\WebDomainPhpResource;
use App\Policies\WebDomainPolicy;
use App\Services\Php\PhpManager;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The PHP of a web domain (E41 spec §7.2, §9): its PHP version and PHP-FPM
 * profile within the owner's package, as the PHP tab of the domain shows
 * them. A value the package no longer allows stays but cannot be chosen
 * again (`403`).
 *
 * @tags Web domains
 */
final class WebDomainPhpController extends Controller
{
    public function __construct(
        private readonly PhpManager $php,
        private readonly WebDomainActions $actions,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
    ) {}

    /**
     * Get the PHP of a web domain
     *
     * Its version and profile, the versions and profiles the package allows,
     * and the effective PHP limits with their source.
     *
     * @operationId getWebDomainPhp
     */
    public function show(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainPhpResource
    {
        abort_unless($policy->viewPhp($principal, $user) && $this->actions->ownerExists($user), 404);

        return new WebDomainPhpResource($this->find($user, $domain));
    }

    /**
     * Set the PHP of a web domain
     *
     * A new version changes the backend template to `PHP-X_Y`; the pool is
     * written with the profile. Both must be allowed by the package of the
     * owner unless the domain already has them (`403`).
     *
     * @operationId setWebDomainPhp
     */
    public function update(SetWebDomainPhpRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainPhpResource|JsonResponse
    {
        abort_unless($policy->viewPhp($principal, $user) && $this->actions->ownerExists($user), 404);
        abort_unless($policy->changePhp($principal, $user), 403);
        $current = $this->find($user, $domain);
        $version = (string) $request->input('version', $current['version']);
        $profile = (string) $request->input('profile', $current['profile']);
        if ($version === '') {
            throw ValidationException::withMessages(['version' => __('Choose a PHP version for a domain without PHP.')]);
        }
        if ($version === $current['version'] && $profile === $current['profile']) {
            return new WebDomainPhpResource($current);
        }
        $step = new Step(sprintf(__('Set PHP %1$s and profile %2$s'), $version, $profile), 'v-change-web-domain-php', [$user, $domain, $version, $profile]);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? new WebDomainPhpResource($this->find($user, $domain));
    }

    /** @return array<string, mixed> */
    private function find(string $user, string $domain): array
    {
        return $this->php->domain($user, $domain) ?? throw new ApiException(ErrorCode::NotExist, sprintf(__('Web domain %s does not exist.'), $domain), 404);
    }
}
