<?php

namespace App\Http\Middleware;

use App\Arc\SysConfig;
use App\Auth\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `guest.arc`: a logged-in user who opens the login pages
 * goes to the dashboard (ARCPANEL_APP_PAGES='yes'), else to the legacy
 * /login/, which picks their landing page.
 */
final class RedirectIfLoggedIn
{
    public function __construct(private readonly SysConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound(Principal::class)) {
            return $this->config->is('ARCPANEL_APP_PAGES')
                ? redirect()->route('dashboard')
                : redirect()->away('/login/');
        }

        return $next($request);
    }
}
