<?php

namespace App\View;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\UserDirectory;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Data of the shell (x-ap-shell): navigation, preferences, the user menu and
 * the user switcher. Views get it ready to render (03 §7.3).
 */
final class ShellComposer
{
    private const RTL = ['ar', 'fa', 'he', 'ur'];

    public function __construct(
        private readonly Navigation $navigation,
        private readonly SysConfig $config,
        private readonly UserDirectory $users,
        private readonly TenancyDirectory $tenancy,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $principal = app(Principal::class);
        $locale = app()->getLocale();
        $switchUsers = $principal->isImpersonating() ? [] : match ($principal->loginRole) {
            Role::Admin => array_values(array_diff($this->users->names(), [$principal->loginUser])),
            Role::Reseller => $this->config->is('POLICY_RESELLER_LOGIN_AS', 'no') ? [] : $this->tenancy->customersOf($principal->loginUser),
            default => [],
        };
        $canSwitch = ! $principal->isImpersonating() && ($principal->loginRole === Role::Admin || $switchUsers !== []);
        $prefs = self::preferences($principal, $this->config, $this->request);
        $dir = in_array(explode('-', $locale)[0], self::RTL, true) ? 'rtl' : 'ltr';

        // The style guide renders in any theme, density and direction on request.
        if ($this->request->routeIs('styleguide')) {
            $theme = $this->request->query('theme');
            $density = $this->request->query('density');
            $prefs = new UiPreferences(
                in_array($theme, UiPreferences::THEMES, true) ? $theme : $prefs->theme,
                in_array($density, UiPreferences::DENSITIES, true) ? $density : $prefs->density,
                $prefs->canChangeTheme,
                $prefs->sidebar,
            );
            $dir = in_array($this->request->query('dir'), ['ltr', 'rtl'], true) ? (string) $this->request->query('dir') : $dir;
        }

        $view->with('shell', [
            'appName' => $this->config->get('APP_NAME', 'ArcPanel') ?: 'ArcPanel',
            'hostname' => (string) gethostname(),
            'home' => route('dashboard'),
            'dir' => $dir,
            'prefs' => $prefs,
            'navigation' => $this->navigation->for($principal, $this->request->route()?->getName()),
            'impersonating' => $principal->isImpersonating(),
            'effectiveUser' => $principal->effectiveUser(),
            'loginUser' => $principal->loginUser,
            'initials' => strtoupper(substr($principal->loginUser, 0, 2)),
            'roleLabel' => match ($principal->loginRole) {
                Role::Admin => __('Administrator'),
                Role::Reseller => __('Reseller'),
                default => __('User'),
            },
            'accountUrl' => route('account.edit'),
            'canSwitchUser' => $canSwitch,
            'switchUsers' => $switchUsers,
            'languages' => self::languages(),
        ]);
    }

    public static function preferences(Principal $principal, SysConfig $config, Request $request): UiPreferences
    {
        $canChangeTheme = $principal->loginRole === Role::Admin || ! $config->is('POLICY_USER_CHANGE_THEME', 'no');
        $legacyTheme = $principal->theme !== null && $principal->theme !== '' ? $principal->theme : $config->get('THEME');

        return UiPreferences::resolve($request->cookie(UiPreferences::COOKIE), $legacyTheme, $canChangeTheme);
    }

    /** @return array<string, string> code => name, from the installed translations */
    public static function languages(): array
    {
        $codes = ['en'];
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $codes[] = basename($file, '.json');
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        $languages = [];
        foreach ($codes as $code) {
            $languages[$code] = class_exists(\Locale::class)
                ? (string) (\Locale::getDisplayName($code, $code) ?: $code)
                : strtoupper($code);
        }

        return $languages;
    }

    /**
     * Options of a language select: code => "name in that language (code)",
     * the code alone when the intl extension is missing.
     *
     * @param  iterable<string>  $codes
     * @return array<string, string>
     */
    public static function languageOptions(iterable $codes): array
    {
        $options = [];
        foreach ($codes as $code) {
            $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayName($code, $code) : '';
            $options[$code] = $name !== '' && $name !== $code ? $name.' ('.$code.')' : $code;
        }

        return $options;
    }
}
