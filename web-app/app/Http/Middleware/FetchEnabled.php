<?php

namespace App\Http\Middleware;

use App\Services\Files\RemoteFetches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `files.fetch`: upload from URL answers only while
 * FILE_FETCH is not 'no' (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md D11).
 */
final class FetchEnabled
{
    public function __construct(private readonly RemoteFetches $fetches) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->fetches->enabled(), 404);

        return $next($request);
    }
}
