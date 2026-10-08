<?php

namespace App\Http\Controllers\Stats;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Services\Stats\StatsActions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Statistics (web/list/stats), E7 batch 2 spec §9.4: administrators see the
 * server totals or one user, resellers the totals of their tree or one
 * customer, users themselves. StatsActions resolves the scope.
 */
final class StatsController extends Controller
{
    public function __invoke(Request $request, Principal $principal, StatsActions $actions): View
    {
        $owner = is_string($request->query('owner')) ? trim($request->query('owner')) : '';
        $resolved = $actions->resolve($principal, $owner);

        return view('stats.index', [
            'months' => $resolved['months'],
            'owner' => $resolved['owner'],
            'owners' => $actions->owners($principal),
            'scope' => $resolved['scope'],
            'isAdmin' => $principal->actingRole() === Role::Admin,
        ]);
    }
}
