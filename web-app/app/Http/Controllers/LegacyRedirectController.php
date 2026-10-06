<?php

namespace App\Http\Controllers;

use App\Auth\Principal;
use App\View\LegacyUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old URLs for one release (E8 spec §5.3): the /app prefix of 1.x and the
 * page paths of the HestiaCP 1.x interface answer 301. Removed in 2.1.
 */
final class LegacyRedirectController extends Controller
{
    /** /app/… → /… with the query string. */
    public function app(Request $request, string $path = ''): RedirectResponse
    {
        // url() keeps the target on this host: "/app//example.net" must not
        // become a protocol-relative redirect.
        $target = url('/'.ltrim($path, '/'));
        $query = $request->getQueryString();

        return redirect()->to($query === null ? $target : $target.'?'.$query, 301);
    }

    /** The logout link of 1.x was a GET; logging out is a POST now. */
    public function logout(): RedirectResponse
    {
        return redirect()->route('login', status: 301);
    }

    public function page(Request $request, string $legacy): RedirectResponse
    {
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);
        $principal = app()->bound(Principal::class) ? app(Principal::class) : null;

        $target = LegacyUrls::target($legacy, $request->query(), $principal);
        abort_if($target === null, 404);

        return redirect()->to($target, LegacyUrls::permanent($legacy) ? 301 : 302);
    }
}
