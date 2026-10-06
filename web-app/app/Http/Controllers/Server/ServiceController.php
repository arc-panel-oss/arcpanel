<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\ServiceConfigs;
use App\Services\Server\SystemServices;
use App\Services\Support\BulkResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Services of the server (web/list/server, web/bulk/service,
 * web/start|stop|restart/service, web/restart/system), E7 batch 3 spec §7.
 */
final class ServiceController extends Controller
{
    public function __construct(private readonly SystemServices $services) {}

    public function index(Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewServices($principal), 403);
        $editors = Route::has('server.services.config') && $policy->viewConfig($principal);

        $services = [];
        foreach ($this->services->all() as $name => $service) {
            $editor = $editors ? ServiceConfigs::editorFor($name) : null;
            $services[] = $service + [
                'editor' => $editor === null ? null : route('server.services.config', ['service' => $editor]),
                'canStop' => $policy->stopService($principal, $name),
            ];
        }

        return view('server.services.index', [
            'info' => $this->services->info(),
            'php' => $this->services->phpVersions(),
            'services' => $services,
            'reports' => array_keys(SystemServices::REPORTS),
            'canManage' => $policy->manageServices($principal),
        ]);
    }

    /** GET /app/server/services/status/{kind}: one status report (web/list/server/?cpu …). */
    public function status(string $kind, Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewServices($principal), 403);
        abort_unless(isset(SystemServices::REPORTS[$kind]), 404);

        return view('server.services.status', [
            'kind' => $kind,
            'reports' => array_keys(SystemServices::REPORTS),
            'report' => $this->services->report($kind),
        ]);
    }

    /** POST /app/server/services/{service}/{action} */
    public function action(string $service, string $action, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        abort_unless($this->services->knows($service), 404);
        abort_if($action === 'stop' && ! $policy->stopService($principal, $service), 403);

        $result = $this->services->run($action, $service);
        $back = redirect()->route('server.services');
        if (! $result->ok()) {
            $error = $result->message() !== '' ? $result->message() : sprintf(__('Restart "%s" failed'), $service);

            return $back->with('error', $error);
        }

        return $back->with('ok', sprintf(match ($action) {
            'start' => __('Service %s has been started.'),
            'stop' => __('Service %s has been stopped.'),
            default => __('Service %s has been restarted.'),
        }, $service));
    }

    /** POST /app/server/services/bulk: a protected service in a Stop is a failed item. */
    public function bulk(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', SystemServices::ACTIONS)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'string', 'max:64'],
        ]);
        $items = array_values(array_unique($data['items']));
        foreach ($items as $service) {
            abort_unless($this->services->knows($service), 404);
        }

        $allowed = array_values(array_filter($items, fn (string $service) => $data['action'] !== 'stop' || $policy->stopService($principal, $service)));
        $result = $this->services->bulk($data['action'], $allowed);
        $refused = array_fill_keys(array_diff($items, $allowed), __('This service is required by the panel.'));

        return (new BulkResult($result->done, $result->failed + $refused))->flash(redirect()->route('server.services'));
    }

    /** POST /app/server/services/restart-server: the host name confirms it (spec D5). */
    public function restartServer(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        $hostname = $this->services->info()['hostname'];
        $request->validate(['hostname' => ['required', 'string', Rule::in([$hostname])]], [
            'hostname.in' => __('Type the host name of the server to confirm.'),
            'hostname.required' => __('Type the host name of the server to confirm.'),
        ]);

        $result = $this->services->restartServer();
        $back = redirect()->route('server.services');

        return $result->ok()
            ? $back->with('ok', __('The system is going down for reboot NOW!'))
            : $back->with('error', $result->errorMessage());
    }
}
