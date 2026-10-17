<?php

namespace App\Http\Middleware\Api;

use App\Api\KeyPermissions;
use App\Api\RequestJournal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journals each write of a key (E43 B4): the first line before the
 * controller runs, the second one when the answer is built. Reads and dry
 * runs are not recorded. A write keeps running when its client goes away,
 * so the command finishes and the second line is still written.
 */
final class JournalApiRequest
{
    public function __construct(private readonly RequestJournal $journal) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->boolean('dry_run') && app()->bound(KeyPermissions::class)) {
            ignore_user_abort(true);
            $this->journal->start($request, app(KeyPermissions::class));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->journal->finish($request, $response);
    }
}
