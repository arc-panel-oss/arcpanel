<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\GetGraphRequest;
use App\Http\Resources\V2\GraphDataResource;
use App\Http\Resources\V2\GraphResource;
use App\Policies\ServerPolicy;
use App\Services\Server\RrdService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Server graphs for administrators (E34 batch 6 spec D65): the series of
 * Admin → Server → Task Monitor as JSON data points, never as images.
 *
 * @tags Server
 */
final class GraphController extends Controller
{
    public function __construct(
        private readonly RrdService $rrd,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the server graphs
     *
     * Load, memory, network, web, mail, database and other graphs that the
     * server records.
     *
     * @operationId listGraphs
     */
    public function index(Request $request, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewGraphs($principal), 403);
        $page = $this->pages->slice($request, $this->rrd->graphs());

        return GraphResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Get the data of a server graph
     *
     * The data points of one graph for `period` (default `daily`), with the
     * names of its series and the unit of their values.
     *
     * @operationId getGraph
     */
    public function show(GetGraphRequest $request, string $graph, Principal $principal, ServerPolicy $policy): GraphDataResource
    {
        abort_unless($policy->viewGraphs($principal), 403);
        $period = $request->period();
        $entry = $this->rrd->find($graph);
        abort_if($entry === null, 404);
        $data = $this->rrd->export($graph, $period);
        if ($data === null) {
            throw new ApiException(ErrorCode::NotExist, __('The server has no data for this graph.'), 404);
        }

        return new GraphDataResource(['graph' => $entry, 'period' => $period, 'unit' => RrdService::unit($graph)] + $data);
    }
}
