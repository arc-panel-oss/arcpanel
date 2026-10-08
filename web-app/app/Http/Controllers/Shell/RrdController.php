<?php

namespace App\Http\Controllers\Shell;

use App\Arc\ArcCommand;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\RrdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /app/_rrd?service=&period= : data of one server graph from
 * `v-export-rrd`, as web/list/rrd/ajax.php returns it (administrators only).
 */
final class RrdController extends Controller
{
    public function __invoke(Request $request, ArcCommand $arc, Principal $principal, ServerPolicy $policy, RrdService $rrd): JsonResponse
    {
        abort_unless($policy->viewGraphs($principal), 403);

        $period = in_array($request->query('period'), RrdService::PERIODS, true) ? (string) $request->query('period') : 'daily';
        $service = (string) $request->query('service', 'la');
        abort_unless(preg_match('/^[\w.-]{1,64}$/', $service) === 1, 422);
        // only the graphs the server lists (E7 batch 2 spec §12.2)
        abort_unless($rrd->knows($service), 404);

        $data = $arc->json('v-export-rrd', [$service, $period]);
        $data['service'] = $service;
        $data['unit'] = RrdService::unit($service);

        return response()->json($data);
    }
}
