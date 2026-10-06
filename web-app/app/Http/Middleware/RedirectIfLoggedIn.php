<?php

namespace App\Http\Middleware;

use App\Auth\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `guest.arc`: a logged-in user who opens the login pages
 * goes to the dashboard.
 */
final class RedirectIfLoggedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound(Principal::class)) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
