<?php

namespace App\Http\Middleware;

use App\Auth\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `not-readonly`: refuses state changes in read-only mode
 * (web/inc/policies.php) before any command runs.
 */
final class NotReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = app()->bound(Principal::class) ? app(Principal::class) : null;

        if ($principal?->readOnly && ! $request->isMethodSafe()) {
            abort(403);
        }

        return $next($request);
    }
}
