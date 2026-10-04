<?php

namespace App\Http\Middleware;

use App\Auth\ClientIp;
use App\Auth\Principal;
use App\Auth\PrincipalResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the legacy session once per request (web/inc/main.php checks) and
 * binds the Principal when someone is logged in.
 */
final class ResolvePrincipal
{
    public function __construct(private readonly PrincipalResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetInstance(Principal::class);
        $principal = $this->resolver->resolve(ClientIp::of($request));

        if ($principal !== null) {
            app()->instance(Principal::class, $principal);
        }
        if ($this->resolver->logoutMessage !== null) {
            $request->session()->flash('error', __($this->resolver->logoutMessage));
        }
        View::share('principal', $principal);

        return $next($request);
    }
}
