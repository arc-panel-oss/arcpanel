<?php

namespace App\Http\Middleware;

use App\Audit\AuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The audit log of the panel and REST API v2 (E23 spec §5.1): every request
 * that is not GET, HEAD or OPTIONS, and the sensitive reads, become one
 * event, written in terminate() so that PHP-FPM has already sent the answer
 * when `v-add-sys-audit-event` runs.
 */
final class RecordAuditEvent
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->recorder->finish($request, $response);
    }
}
