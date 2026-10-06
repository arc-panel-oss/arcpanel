<?php

namespace App\Http\Controllers\WebDomains;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\WebDomainPolicy;
use App\Services\WebDomains\WebDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Access and error log of a web domain (web/list/web-log): the last lines,
 * as a page or as JSON for the refresh button, and the download of the last
 * 5000 lines (web/download/web-log).
 */
final class WebDomainLogController extends Controller
{
    private const DOWNLOAD_LINES = 5000;

    public function show(Request $request, string $user, string $domain, string $type, Principal $principal, WebDomainPolicy $policy, WebDomainService $web): View|JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $web->find($user, $domain);
        $lines = $web->log($user, $domain, $type);

        if ($request->expectsJson()) {
            return response()->json(['lines' => $lines]);
        }

        return view('web.logs', [
            'record' => $record,
            'type' => $type,
            'lines' => $lines,
            'download' => route('web.domains.logs.download', ['user' => $user, 'domain' => $domain, 'type' => $type]),
        ]);
    }

    public function download(string $user, string $domain, string $type, Principal $principal, WebDomainPolicy $policy, WebDomainService $web): Response
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $web->find($user, $domain);
        $lines = $web->log($user, $domain, $type, self::DOWNLOAD_LINES);
        $body = $lines === [] ? '' : implode("\n", $lines)."\n";

        return response()->streamDownload(
            function () use ($body): void {
                echo $body;
            },
            $record->domain.'.'.$type.'-log.txt',
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
