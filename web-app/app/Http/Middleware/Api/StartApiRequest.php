<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiHeaders;
use App\Api\KeyPermissions;
use App\Api\KeyScopedArcCommand;
use App\Arc\ArcCommand;
use App\Auth\Principal;
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
        // An application that serves several requests (the test suite) must
        // not keep the key, the principal or the scoped commands of the last one.
        app()->forgetInstance(Principal::class);
        app()->forgetInstance(KeyPermissions::class);
        $arc = app(ArcCommand::class);
        if ($arc instanceof KeyScopedArcCommand) {
            app()->instance(ArcCommand::class, $arc->inner());
        }
        app()->forgetScopedInstances();

        ApiHeaders::requestId($request);

        return ApiHeaders::apply($next($request), $request);
    }
}
