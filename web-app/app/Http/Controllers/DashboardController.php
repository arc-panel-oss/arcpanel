<?php

namespace App\Http\Controllers;

use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Services\Dashboard\DashboardService;
use App\View\Navigation;
use Illuminate\View\View;

/**
 * GET /app/ : the dashboard of the role the panel acts with (E1-S2 spec §7,
 * E11 spec §9). While impersonating, it is the user dashboard of the
 * effective user.
 */
final class DashboardController extends Controller
{
    public function __invoke(Principal $principal, DashboardService $dashboard): View
    {
        abort_unless(Capabilities::allows($principal->actingRole(), 'dashboard.view'), 403);

        if ($principal->actingRole() === Role::Admin) {
            return view('dashboard.admin', [
                'widgets' => $dashboard->admin($principal->loginUser),
                'addUserUrl' => Navigation::url('users.create'),
                'servicesUrl' => Navigation::url('server.services'),
                'updatesUrl' => Navigation::url('server.updates'),
                'graphsUrl' => Navigation::url('server.rrd'),
                'logsUrl' => Navigation::url('logs.index'),
                'canCreate' => ! $principal->isReadOnly(),
            ]);
        }

        if ($principal->actingRole() === Role::Reseller) {
            return view('dashboard.reseller', [
                'user' => $principal->effectiveUser(),
                'widgets' => $dashboard->reseller($principal->effectiveUser()),
                'canCreate' => ! $principal->isReadOnly(),
                'backupsUrl' => Navigation::url('backups.index'),
            ]);
        }

        return view('dashboard.user', [
            'user' => $principal->effectiveUser(),
            'widgets' => $dashboard->user($principal->effectiveUser()),
            'provider' => $dashboard->provider($principal->effectiveUser()),
            'canCreate' => Capabilities::allows($principal->actingRole(), 'web.create') && ! $principal->isReadOnly(),
            'backupsUrl' => Navigation::url('backups.index'),
            'nodeAppsUrl' => route('node.apps.index', ['user' => $principal->effectiveUser()]),
            'dockerAppsUrl' => route('docker.apps.index', ['user' => $principal->effectiveUser()]),
        ]);
    }
}
