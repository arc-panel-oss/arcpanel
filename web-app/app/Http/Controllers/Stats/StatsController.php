<?php

namespace App\Http\Controllers\Stats;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\StatsPolicy;
use App\Services\Stats\StatsService;
use App\Services\UserDirectory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Statistics (web/list/stats), E7 batch 2 spec §9.4: administrators see the
 * server totals or one user, resellers the totals of their tree or one
 * customer, users themselves.
 */
final class StatsController extends Controller
{
    public function __construct(private readonly StatsService $stats) {}

    public function __invoke(Request $request, Principal $principal, StatsPolicy $policy, OwnerScope $scope, UserDirectory $directory, SysConfig $config): View
    {
        abort_unless($policy->viewAny($principal), 403);

        $owner = is_string($request->query('owner')) ? trim($request->query('owner')) : '';
        $overall = $policy->overall($principal);
        $tree = $policy->tree($principal);
        if ($owner === '' && ! $overall && ! $tree) {
            $owner = $principal->effectiveUser();
        }
        if ($owner !== '') {
            $scope->assertVisible($owner);
            abort_unless(in_array($owner, $directory->names(), true) || $owner === $principal->effectiveUser(), 404);
            abort_unless($policy->view($principal, $owner), 403);
        }

        $owners = null;
        if ($overall) {
            $owners = array_values(array_filter($directory->names(),
                fn (string $name) => ! ($config->is('POLICY_SYSTEM_HIDE_ADMIN') && $name === $config->get('ROOT_USER'))));
        } elseif ($tree) {
            $owners = $scope->tree($principal->effectiveUser());
        }

        return view('stats.index', [
            'months' => match (true) {
                $owner !== '' => $this->stats->forUser($owner),
                $overall => $this->stats->overall(),
                default => $this->stats->tree($principal->effectiveUser()),
            },
            'owner' => $owner,
            'owners' => $owners,
            'scope' => $owner !== '' ? 'user' : ($overall ? 'server' : 'tree'),
            'isAdmin' => $principal->actingRole() === Role::Admin,
        ]);
    }
}
