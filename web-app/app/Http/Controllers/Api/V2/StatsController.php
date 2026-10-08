<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\Paginates;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\StatsMonthResource;
use App\Policies\StatsPolicy;
use App\Services\Stats\StatsActions;
use App\Services\Stats\StatsMonth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Monthly statistics (E34 batch 6 §4.2), resolved as on the Statistics
 * page (StatsActions): one account of the scope, the server totals for
 * administrators, the totals of the tree for resellers.
 *
 * @tags Statistics
 */
final class StatsController extends Controller
{
    public function __construct(
        private readonly StatsActions $actions,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the monthly statistics of a user
     *
     * Newest first.
     *
     * @operationId listUserStats
     */
    public function show(Request $request, string $user, Principal $principal): AnonymousResourceCollection
    {
        return $this->answer($request, $this->actions->resolve($principal, $user)['months']);
    }

    /**
     * List the monthly totals
     *
     * Administrators: the totals of the server; resellers: the totals of
     * their account and their customers; users: 403. Newest first.
     *
     * @operationId listStats
     */
    public function index(Request $request, Principal $principal, StatsPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->overall($principal) || $policy->tree($principal), 403);

        return $this->answer($request, $this->actions->resolve($principal, '')['months']);
    }

    /** @param  list<StatsMonth>  $months */
    private function answer(Request $request, array $months): AnonymousResourceCollection
    {
        $page = $this->pages->slice($request, $months);

        return StatsMonthResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }
}
