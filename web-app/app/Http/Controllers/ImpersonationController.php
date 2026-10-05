<?php

namespace App\Http\Controllers;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Auth\LegacySession;
use App\Auth\Principal;
use App\Auth\PrincipalResolver;
use App\Auth\Role;
use App\Policies\ImpersonationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Login as": the same session keys and log lines as web/login/index.php
 * (?loginas=) and web/logout/index.php, so either app can end what the other
 * started. With ARCPANEL_APP_PAGES='yes' both directions land on /app/.
 *
 * Resellers (E11 spec D4) use their own key `arc_look`, which only the panel
 * app reads: their "login as" works on /app pages only.
 */
final class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly LegacySession $legacy,
        private readonly SysConfig $config,
    ) {}

    public function start(string $user, Principal $principal, ImpersonationPolicy $policy): RedirectResponse
    {
        abort_unless($policy->start($principal, $user), 403);

        $data = $this->arc->json('v-list-user', [$user], Options::allowFailure());
        abort_unless(isset($data[$user]), 404);

        $this->arc->run('v-log-action', [
            $principal->loginUser, 'Info', 'Security',
            "Logged in as another user (User: {$user})",
        ], Options::allowFailure());
        $this->arc->run('v-log-action', [
            'system', 'Warning', 'Security',
            "User impersonation session started (User: {$user}, {$this->actor($principal)}: {$principal->loginUser})",
        ], Options::allowFailure());

        if ($principal->loginRole === Role::Reseller) {
            $this->legacy->write([PrincipalResolver::RESELLER_LOOK => $user]);

            return redirect()->route('dashboard');
        }

        // File Manager state belongs to the previous account
        $this->legacy->write(['look' => $user, '_sf2_attributes' => null, '_sf2_meta' => null]);

        // The legacy /login/ sends a logged-in session to its landing page.
        return $this->config->is('ARCPANEL_APP_PAGES') ? redirect()->route('dashboard') : redirect()->away('/login/');
    }

    /** The user switcher of the top bar posts the user name in the body. */
    public function fromForm(Request $request, Principal $principal, ImpersonationPolicy $policy): RedirectResponse
    {
        $user = (string) $request->validate([
            'user' => ['required', 'string', 'regex:/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/'],
        ])['user'];

        return $this->start($user, $principal, $policy);
    }

    public function stop(Principal $principal): RedirectResponse
    {
        if ($principal->isImpersonating()) {
            $this->arc->run('v-log-action', [
                'system', 'Warning', 'Security',
                "User impersonation session ended (User: {$principal->impersonated}, {$this->actor($principal)}: {$principal->loginUser})",
            ], Options::allowFailure());

            if ($principal->loginRole === Role::Reseller) {
                $this->legacy->write([PrincipalResolver::RESELLER_LOOK => null]);

                return redirect()->route('dashboard');
            }
            $this->legacy->write(['look' => null, '_sf2_attributes' => null, '_sf2_meta' => null]);
        }

        return $this->config->is('ARCPANEL_APP_PAGES') ? redirect()->route('dashboard') : redirect()->away('/');
    }

    private function actor(Principal $principal): string
    {
        return $principal->loginRole === Role::Reseller ? 'Reseller' : 'Administrator';
    }
}
