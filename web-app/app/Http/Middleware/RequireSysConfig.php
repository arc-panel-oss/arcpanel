<?php

namespace App\Http\Middleware;

use App\Arc\SysConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `sys-config:KEY` or `sys-config:KEY=value` (E7 batch 2
 * spec §5.3): the route answers 404 when the hestia.conf key is empty or
 * differs, as Navigation leaves the item out.
 */
final class RequireSysConfig
{
    public function __construct(private readonly SysConfig $config) {}

    public function handle(Request $request, Closure $next, string $requirement): Response
    {
        if (str_contains($requirement, '=')) {
            [$key, $value] = explode('=', $requirement, 2);
            abort_unless($this->config->get($key) === $value, 404);
        } else {
            abort_if($this->config->get($requirement) === '', 404);
        }

        return $next($request);
    }
}
