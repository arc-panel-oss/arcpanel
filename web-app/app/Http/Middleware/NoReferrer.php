<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `no-referrer`: pages that hold a reset code or a sign-on
 * token send no Referer to the next site (E39 W2 spec D9).
 */
final class NoReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
