<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\RrdService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Task Monitor: every server graph (web/list/rrd), E7 batch 2 spec §9.5.
 */
final class RrdPageController extends Controller
{
    public function __invoke(Request $request, Principal $principal, ServerPolicy $policy, RrdService $rrd): View
    {
        abort_unless($policy->viewGraphs($principal), 403);
        $period = in_array($request->query('period'), RrdService::PERIODS, true) ? (string) $request->query('period') : 'daily';

        return view('server.rrd', [
            'graphs' => $rrd->graphs(),
            'period' => $period,
            'periods' => RrdService::PERIODS,
        ]);
    }
}
