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
    private const UNITS = [
        'la' => 'Points',
        'mem' => 'Mbytes',
        'apache2' => 'Connections',
        'nginx' => 'Connections',
        'mail' => 'Queue Size',
        'ftp' => 'Connections',
        'ssh' => 'Connections',
    ];

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
        $data['unit'] = self::UNITS[$service] ?? match (true) {
            str_starts_with($service, 'net_') => 'KBytes',
            str_starts_with($service, 'pgsql_'), str_starts_with($service, 'mysql_') => 'Queries',
            default => null,
        };

        return response()->json($data);
    }
}
