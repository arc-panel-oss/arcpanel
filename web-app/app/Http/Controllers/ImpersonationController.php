<?php

namespace App\Http\Controllers;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Auth\LegacySession;
use App\Auth\Principal;
use App\Policies\ImpersonationPolicy;
use Illuminate\Http\RedirectResponse;

/**
 * "Login as": the same session keys and log lines as web/login/index.php
 * (?loginas=) and web/logout/index.php, so either app can end what the other
 * started.
 */
final class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly LegacySession $legacy,
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
            "User impersonation session started (User: {$user}, Administrator: {$principal->loginUser})",
        ], Options::allowFailure());

        // File Manager state belongs to the previous account
        $this->legacy->write(['look' => $user, '_sf2_attributes' => null, '_sf2_meta' => null]);

        // The legacy /login/ sends a logged-in session to its landing page.
        return redirect()->away('/login/');
    }

    public function stop(Principal $principal): RedirectResponse
    {
        if ($principal->isImpersonating()) {
            $this->arc->run('v-log-action', [
                'system', 'Warning', 'Security',
                "User impersonation session ended (User: {$principal->impersonated}, Administrator: {$principal->loginUser})",
            ], Options::allowFailure());

            $this->legacy->write(['look' => null, '_sf2_attributes' => null, '_sf2_meta' => null]);
        }

        return redirect()->away('/');
    }
}
