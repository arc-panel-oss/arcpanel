<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiHeaders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First middleware of API v2: the request id, and the headers of E34 spec
 * D13 on every answer (errors of later middleware included).
 */
final class StartApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        ApiHeaders::requestId($request);

        return ApiHeaders::apply($next($request), $request);
    }
}
