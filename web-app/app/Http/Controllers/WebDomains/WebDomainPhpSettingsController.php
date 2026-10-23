<?php

namespace App\Http\Controllers\WebDomains;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\WebDomainPolicy;
use App\Services\Php\PhpManager;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The PHP settings card of a web domain's PHP tab (E19 spec §6): seven
 * php.ini values of the domain's pool within the package maximums. An empty
 * field resets the value to the profile's or php.ini's; the commands check
 * again.
 */
final class WebDomainPhpSettingsController extends Controller
{
    public function update(Request $request, string $user, string $domain, Principal $principal, WebDomainPolicy $policy, WebDomainService $web, PhpManager $php): RedirectResponse
    {
        abort_unless($policy->changePhpSettings($principal, $user), 403);
        $web->find($user, $domain);
        $current = $php->settings($user, $domain);
        abort_if($current === null, 404);
        abort_unless($current['supported'], 409);
        $tab = route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#php';

        try {
            $data = $request->validate(self::rules());
            $wanted = [];
            foreach (array_keys(PhpManager::DOMAIN_SETTINGS) as $directive) {
                $wanted[$directive] = trim((string) ($data['settings'][self::field($directive)] ?? ''));
            }
            $errors = PhpManager::settingErrors($current['settings'], $wanted);
            if ($errors !== []) {
                throw ValidationException::withMessages(array_combine(
                    array_map(fn (string $directive) => 'settings.'.self::field($directive), array_keys($errors)), array_values($errors)));
            }
        } catch (ValidationException $e) {
            throw $e->errorBag('phpSettings')->redirectTo($tab);
        }

        $plan = PhpManager::settingsPlan($current['settings'], $wanted);
        if ($plan === []) {
            return redirect()->to($tab)->with('ok', __('Nothing has changed.'));
        }
        $result = $php->applySettings($user, $domain, $plan);
        if (! $result->ok()) {
            return redirect()->to($tab)->withInput()->with('error', __('The PHP settings were not saved.'))->with('error_detail', $result->errorMessage());
        }

        return redirect()->to($tab)->with('ok', __('Changes have been saved.'));
    }

    public function reset(string $user, string $domain, Principal $principal, WebDomainPolicy $policy, WebDomainService $web, PhpManager $php): RedirectResponse
    {
        abort_unless($policy->changePhpSettings($principal, $user), 403);
        $web->find($user, $domain);
        $tab = route('web.domains.edit', ['user' => $user, 'domain' => $domain]).'#php';

        $result = $php->resetSetting($user, $domain, 'all');
        if (! $result->ok()) {
            return redirect()->to($tab)->with('error', __('The PHP settings were not saved.'))->with('error_detail', $result->errorMessage());
        }

        return redirect()->to($tab)->with('ok', __('Changes have been saved.'));
    }

    /** The form field of a directive: date.timezone is date_timezone, as a dot would nest it. */
    public static function field(string $directive): string
    {
        return str_replace('.', '_', $directive);
    }

    /** @return array<string, mixed> */
    private static function rules(): array
    {
        $size = ['nullable', 'string', 'max:20', 'regex:/^\d{1,6}[KMG]?$/', 'not_regex:/^0+[KMG]?$/'];

        return [
            'settings' => ['required', 'array'],
            'settings.memory_limit' => $size,
            'settings.upload_max_filesize' => $size,
            'settings.post_max_size' => $size,
            'settings.max_execution_time' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'settings.max_input_vars' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'settings.display_errors' => ['nullable', 'in:on,off'],
            'settings.date_timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
        ];
    }
}
