<?php

namespace App\Http\Middleware;

use App\Auth\EffectiveUser;
use App\Auth\OwnerScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `effective-user`: every route with a {user} parameter
 * runs through OwnerScope::assertVisible() (404 outside the scope) and gets
 * the checked owner as EffectiveUser (docs/design/03 §7.1).
 */
final class BindEffectiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) $request->route('user');
        app(OwnerScope::class)->assertVisible($user);
        app()->instance(EffectiveUser::class, new EffectiveUser($user));

        return $next($request);
    }
}
