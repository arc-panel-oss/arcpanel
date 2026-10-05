<?php

namespace App\Http\Middleware;

use App\Arc\SysConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `files.enabled`: the file manager answers only when
 * FILE_MANAGER='true' (E3 spec D11), the switch of v-add-sys-filemanager.
 */
final class FilesEnabled
{
    public function __construct(private readonly SysConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->config->is('FILE_MANAGER', 'true'), 404);

        return $next($request);
    }
}
