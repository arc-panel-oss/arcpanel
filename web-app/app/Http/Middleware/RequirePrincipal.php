<?php

namespace App\Http\Middleware;

use App\Auth\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware `auth.arc`: a logged-in Principal is required. */
final class RequirePrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound(Principal::class)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => ['code' => 'E_AUTH', 'message' => 'Unauthenticated']], 401);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }
}
