<?php

namespace App\Http\Controllers\Auth;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Auth\LegacySession;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Ends the session in both apps, as web/logout/index.php does. */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, Principal $principal, ArcCommand $arc, LegacySession $legacy): RedirectResponse
    {
        $arc->run('v-log-user-logout', [$principal->loginUser, $principal->legacyToken], Options::allowFailure());
        $legacy->destroy();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
