<?php

namespace App\Http\Controllers\WebDomains;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\WebDomainPolicy;
use App\Services\Php\PhpManager;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The PHP tab of a web domain (E41 spec §8): the PHP version and PHP-FPM
 * profile within the domain's package. A value the package no longer allows
 * may stay but cannot be chosen again (D11); the command decides.
 */
final class WebDomainPhpController extends Controller
{
    public function update(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, WebDomainService $web, PhpManager $php): RedirectResponse
    {
        abort_unless($policy->changePhp($principal, $user), 403);
        $web->find($user, $domain);
        $current = $php->domain($user, $domain);
        abort_if($current === null, 404);
        $tab = route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#php';

        $versions = array_keys($current['versions']);
        $profiles = $current['profiles'];
        if ($current['version'] !== '') {
            $versions[] = $current['version'];
        }
        $profiles[] = $current['profile'];
        try {
            $data = $request->validate([
                'php_version' => ['required', 'string', Rule::in(array_values(array_unique($versions)))],
                'php_profile' => ['required', 'string', Rule::in(array_values(array_unique($profiles)))],
            ], [
                'php_version.in' => __('The package of this domain does not offer this PHP version.'),
                'php_profile.in' => __('The package of this domain does not offer this profile.'),
            ]);
        } catch (ValidationException $e) {
            throw $e->errorBag('php')->redirectTo($tab);
        }

        if ($data['php_version'] === $current['version'] && $data['php_profile'] === $current['profile']) {
            return redirect()->to($tab)->with('ok', __('Nothing has changed.'));
        }
        $result = $php->setDomain($user, $domain, $data['php_version'], $data['php_profile']);
        if (! $result->ok()) {
            return redirect()->to($tab)->withInput()->with('error', __('The PHP settings were not saved.'))->with('error_detail', $result->errorMessage());
        }

        return redirect()->to($tab)->with('ok', __('Changes have been saved.'));
    }
}
