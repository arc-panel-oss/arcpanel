<?php

namespace App\Http\Controllers;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Auth\LegacySession;
use App\Auth\Principal;
use App\Auth\PrincipalResolver;
use App\Auth\Role;
use App\Policies\ImpersonationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Login as": the session key `look` and the log lines of the ArcPanel 1.x
 * login page (?loginas=), which the web terminal still reads. Both directions
 * land on the dashboard.
 *
 * Resellers (E11 spec D4) use their own key `arc_look`, which only the panel
 * app reads.
 */
final class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly LegacySession $legacy,
    ) {}

    public function start(Request $request, string $user, Principal $principal, ImpersonationPolicy $policy): RedirectResponse
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

        $this->switchSessions($request, $principal->loginRole === Role::Reseller
            ? [PrincipalResolver::RESELLER_LOOK => $user]
            : ['look' => $user]);

        return redirect()->route('dashboard');
    }

    /** The user switcher of the top bar posts the user name in the body. */
    public function fromForm(Request $request, Principal $principal, ImpersonationPolicy $policy): RedirectResponse
    {
        $user = (string) $request->validate([
            'user' => ['required', 'string', 'regex:/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/'],
        ])['user'];

        return $this->start($request, $user, $principal, $policy);
    }

    public function stop(Request $request, Principal $principal): RedirectResponse
    {
        if ($principal->isImpersonating()) {
            $this->arc->run('v-log-action', [
                'system', 'Warning', 'Security',
                "User impersonation session ended (User: {$principal->impersonated}, {$this->actor($principal)}: {$principal->loginUser})",
            ], Options::allowFailure());

            $this->switchSessions($request, $principal->loginRole === Role::Reseller
                ? [PrincipalResolver::RESELLER_LOOK => null]
                : ['look' => null]);
        }

        return redirect()->route('dashboard');
    }

    /**
     * Apply $patch to the legacy session and give it and the Laravel session
     * new ids, as a sign-in does (E39 W5 L22).
     *
     * @param  array<string, string|null>  $patch
     */
    private function switchSessions(Request $request, array $patch): void
    {
        $this->legacy->establish($patch);
        $request->session()->regenerate();
    }

    private function actor(Principal $principal): string
    {
        return $principal->loginRole === Role::Reseller ? 'Reseller' : 'Administrator';
    }
}
