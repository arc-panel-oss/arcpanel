<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\ServiceActions;
use App\Services\Server\ServiceConfigs;
use App\Services\Server\SystemServices;
use App\Services\Support\BulkResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Services of the server (web/list/server, web/bulk/service,
 * web/start|stop|restart/service, web/restart/system), E7 batch 3 spec §7.
 */
final class ServiceController extends Controller
{
    public function __construct(
        private readonly SystemServices $services,
        private readonly ServiceActions $actions,
    ) {}

    public function index(Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewServices($principal), 403);
        $editors = Route::has('server.services.config') && $policy->viewConfig($principal);

        $services = [];
        foreach ($this->services->all() as $name => $service) {
            $editor = $editors ? ServiceConfigs::editorFor($name) : null;
            // A PHP-FPM version opens its php.ini on the PHP page (E41 §6.5)
            $phpIni = preg_match('/^php(\d{1,2}\.\d{1,2})-fpm$/', $name, $m) === 1 && $policy->viewPhp($principal)
                ? route('server.php.show', ['version' => $m[1], 'tab' => 'ini-fpm']) : null;
            $services[] = $service + [
                'editor' => $phpIni ?? ($editor === null ? null : route('server.services.config', ['service' => $editor])),
                'canStop' => $policy->stopService($principal, $name),
            ];
        }

        return view('server.services.index', [
            'info' => $this->services->info(),
            'php' => $this->services->phpVersions(),
            'services' => $services,
            'reports' => $this->services->reports(),
            'canManage' => $policy->manageServices($principal),
        ]);
    }

    /** GET /app/server/services/status/{kind}: one status report (web/list/server/?cpu …). */
    public function status(string $kind, Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewServices($principal), 403);
        $reports = $this->services->reports();
        abort_unless(in_array($kind, $reports, true), 404);

        return view('server.services.status', [
            'kind' => $kind,
            'reports' => $reports,
            'report' => $this->services->report($kind),
        ]);
    }

    /** POST /app/server/services/{service}/{action} */
    public function action(string $service, string $action, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        $this->actions->assertKnown([$service]);
        abort_unless(ServiceActions::allows($action, $service), 403);

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
        $this->actions->assertKnown($items);

        [$allowed, $refused] = ServiceActions::partition($data['action'], $items);
        $result = $this->services->bulk($data['action'], $allowed);
        $refused = array_fill_keys($refused, ServiceActions::refusal());

        return (new BulkResult($result->done, $result->failed + $refused))->flash(redirect()->route('server.services'));
    }

    /** POST /app/server/services/restart-server: the host name confirms it (spec D5). */
    public function restartServer(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        $this->actions->confirmRestart($request->input('hostname'));

        $result = $this->services->restartServer();
        $back = redirect()->route('server.services');

        return $result->ok()
            ? $back->with('ok', __('The system is going down for reboot NOW!'))
            : $back->with('error', $result->errorMessage());
    }
}
