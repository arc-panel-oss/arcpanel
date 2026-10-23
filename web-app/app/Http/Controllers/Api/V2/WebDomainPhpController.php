<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SetWebDomainPhpRequest;
use App\Http\Requests\Api\V2\SetWebDomainPhpSettingsRequest;
use App\Http\Resources\V2\WebDomainPhpResource;
use App\Http\Resources\V2\WebDomainPhpSettingsResource;
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

    /**
     * Get the PHP settings of a web domain
     *
     * The seven per-domain settings (E19): the domain's value, the package
     * maximum, the values of php.ini and the profile, and the effective value
     * with its source; with one pool per user they are the user's.
     *
     * @operationId getWebDomainPhpSettings
     */
    public function settings(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainPhpSettingsResource
    {
        abort_unless($policy->viewPhp($principal, $user) && $this->actions->ownerExists($user), 404);

        return new WebDomainPhpSettingsResource($this->findSettings($user, $domain));
    }

    /**
     * Change the PHP settings of a web domain
     *
     * Settings left out stay; `null` resets one to the value of the profile
     * or php.ini. Values must be within the package maximums (`422`);
     * PHP-FPM reloads once. A backend template without the profile line
     * takes no settings (`409`).
     *
     * @operationId updateWebDomainPhpSettings
     */
    public function updateSettings(SetWebDomainPhpSettingsRequest $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainPhpSettingsResource|JsonResponse
    {
        abort_unless($policy->viewPhp($principal, $user) && $this->actions->ownerExists($user), 404);
        abort_unless($policy->changePhpSettings($principal, $user), 403);
        $current = $this->findSettings($user, $domain);
        $this->supported($current);
        $wanted = $request->wanted();
        $errors = PhpManager::settingErrors($current['settings'], $wanted);
        if ($errors !== []) {
            throw ValidationException::withMessages(array_combine(array_map(fn (string $d) => str_replace('.', '_', $d), array_keys($errors)), array_values($errors)));
        }
        $plan = PhpManager::settingsPlan($current['settings'], $wanted);
        if ($plan === []) {
            return new WebDomainPhpSettingsResource($current);
        }

        return $this->gate->execute($this->settingSteps($user, $domain, $plan), $this->runner->apply(...))
            ?? new WebDomainPhpSettingsResource($this->findSettings($user, $domain));
    }

    /**
     * Reset the PHP settings of a web domain
     *
     * Every setting takes the value of the profile or php.ini again.
     *
     * @operationId resetWebDomainPhpSettings
     */
    public function resetSettings(string $user, string $domain, Principal $principal, WebDomainPolicy $policy): WebDomainPhpSettingsResource|JsonResponse
    {
        abort_unless($policy->viewPhp($principal, $user) && $this->actions->ownerExists($user), 404);
        abort_unless($policy->changePhpSettings($principal, $user), 403);
        $this->findSettings($user, $domain);
        $step = new Step(__('Reset the PHP settings'), 'v-delete-web-domain-php-setting', [$user, $domain, 'all']);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? new WebDomainPhpSettingsResource($this->findSettings($user, $domain));
    }

    /**
     * The steps of a settings plan, the last one with the PHP-FPM reload.
     *
     * @param  list<array{0: string, 1: string|null}>  $plan
     * @return list<Step>
     */
    private function settingSteps(string $user, string $domain, array $plan): array
    {
        $steps = [];
        foreach ($plan as $i => [$directive, $value]) {
            $reload = $i === count($plan) - 1 ? 'yes' : 'no';
            $steps[] = $value === null
                ? new Step(sprintf(__('Reset %s'), $directive), 'v-delete-web-domain-php-setting', [$user, $domain, $directive, $reload])
                : new Step(sprintf(__('Set %1$s to %2$s'), $directive, $value), 'v-change-web-domain-php-setting', [$user, $domain, $directive, $value, $reload]);
        }

        return $steps;
    }

    /** @param  array{supported: bool}  $settings */
    private function supported(array $settings): void
    {
        if (! $settings['supported']) {
            throw new ApiException(ErrorCode::Disabled, __('This backend template takes no PHP settings.'), 409);
        }
    }

    /** @return array{pool: string, supported: bool, nginxMaxBody: string, settings: array<string, array{value: string, max: string, ini: string, profile: string, effective: string, source: string}>, warnings: list<string>} */
    private function findSettings(string $user, string $domain): array
    {
        return $this->php->settings($user, $domain) ?? throw new ApiException(ErrorCode::NotExist, sprintf(__('Web domain %s does not exist.'), $domain), 404);
    }

    /** @return array<string, mixed> */
    private function find(string $user, string $domain): array
    {
        return $this->php->domain($user, $domain) ?? throw new ApiException(ErrorCode::NotExist, sprintf(__('Web domain %s does not exist.'), $domain), 404);
    }
}
