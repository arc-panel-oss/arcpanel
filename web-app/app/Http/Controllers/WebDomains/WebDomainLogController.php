<?php

namespace App\Http\Controllers\WebDomains;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\WebDomainPolicy;
use App\Services\WebDomains\WebDomainService;
use App\View\Navigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Access and error log of a web domain (web/list/web-log): the last lines,
 * as a page or as JSON for the refresh button.
 */
final class WebDomainLogController extends Controller
{
    public function show(Request $request, string $user, string $domain, string $type, Principal $principal, WebDomainPolicy $policy, WebDomainService $web): View|JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $web->find($user, $domain);
        $lines = $web->log($user, $domain, $type);

        if ($request->expectsJson()) {
            return response()->json(['lines' => $lines]);
        }

        // The legacy download serves the effective user's logs.
        $download = $user === $principal->effectiveUser()
            ? Navigation::url('web.logs.download').'?'.http_build_query(['domain' => $domain, 'type' => $type, 'token' => $principal->legacyToken])
            : null;

        return view('web.logs', [
            'record' => $record,
            'type' => $type,
            'lines' => $lines,
            'download' => $download,
        ]);
    }
}
