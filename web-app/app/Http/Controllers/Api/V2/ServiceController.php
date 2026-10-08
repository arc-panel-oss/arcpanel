<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RestartServerRequest;
use App\Http\Resources\V2\ServiceResource;
use App\Http\Resources\V2\ServiceStatusResource;
use App\Policies\ServerPolicy;
use App\Services\Server\ServiceActions;
use App\Services\Server\SystemServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Services of the server for administrators (E34 batch 6 spec §5.1, D61):
 * Admin → Server → Services as JSON, with the rules of ServiceActions. With
 * POLICY_SYSTEM_HIDE_SERVICES on, only a key of the root administrator may
 * use these endpoints, as only that login may use the page.
 *
 * @tags Server
 */
final class ServiceController extends Controller
{
    public function __construct(
        private readonly SystemServices $services,
        private readonly ServiceActions $actions,
        private readonly PlanGate $gate,
    ) {}

    /**
     * List the services of the server
     *
     * Every service the panel watches, with its state and usage. `meta`
     * describes the server: host name, operating system, versions, uptime
     * and load. The list is not paginated.
     *
     * @operationId listServices
     */
    public function index(Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewServices($principal), 403);
        $info = $this->services->info();

        return ServiceResource::collection(array_values($this->services->all()))->additional(['meta' => [
            'hostname' => $info['hostname'],
            'os' => $info['os'],
            'os_version' => $info['version'],
            'arch' => $info['arch'],
            'arcpanel_version' => $info['arcpanel'],
            'release' => $info['release'],
            'uptime_minutes' => $info['uptime'],
            'load' => self::load($info['load']),
        ]]);
    }

    /**
     * Get a status report of the server
     *
     * The plain text of one report of the services page: `cpu`, `mem`,
     * `disk`, `net`, `web`, `dns`, `mail` or `db`.
     *
     * @operationId getServiceStatus
     */
    public function status(string $kind, Principal $principal, ServerPolicy $policy): ServiceStatusResource
    {
        abort_unless($policy->viewServices($principal), 403);
        abort_unless(isset(SystemServices::REPORTS[$kind]), 404);

        return new ServiceStatusResource(['kind' => $kind, 'text' => $this->services->report($kind)]);
    }

    /**
     * Start a service
     *
     * @operationId startService
     */
    public function start(string $service, Principal $principal, ServerPolicy $policy): ServiceResource|JsonResponse
    {
        return $this->act('start', $service, $principal, $policy);
    }

    /**
     * Stop a service
     *
     * The panel's own service (`arcpanel`) is never stopped: `403`.
     *
     * @operationId stopService
     */
    public function stop(string $service, Principal $principal, ServerPolicy $policy): ServiceResource|JsonResponse
    {
        return $this->act('stop', $service, $principal, $policy);
    }

    /**
     * Restart a service
     *
     * Restarting `arcpanel` restarts the panel that answers this request.
     *
     * @operationId restartService
     */
    public function restart(string $service, Principal $principal, ServerPolicy $policy): ServiceResource|JsonResponse
    {
        return $this->act('restart', $service, $principal, $policy);
    }

    /**
     * Restart the server
     *
     * Reboots the whole server. `confirm_hostname` must be the server's host
     * name (`meta.hostname` of listServices), otherwise `422`. Answers `204`
     * once the command accepted the restart; the server then goes down, so
     * there is no task to follow. Try it with `dry_run` first.
     *
     * @operationId restartServer
     *
     * @status 204
     */
    public function restartServer(RestartServerRequest $request, Principal $principal, ServerPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        $steps = [SystemServices::restartServerStep()];
        $this->gate->authorize($steps);
        $this->actions->confirmRestart($request->input('confirm_hostname'), 'confirm_hostname');

        return $this->gate->execute($steps, $this->services->apply(...)) ?? response()->noContent();
    }

    private function act(string $action, string $service, Principal $principal, ServerPolicy $policy): ServiceResource|JsonResponse
    {
        abort_unless($policy->manageServices($principal), 403);
        // A protected service refuses a stop whether or not the list shows it
        if (! ServiceActions::allows($action, $service)) {
            throw new ApiException(ErrorCode::Forbidden, sprintf(__('Service %s is required by the panel: it can be restarted, not stopped.'), $service), 403);
        }
        $this->actions->assertKnown([$service]);
        if ($preview = $this->gate->execute([SystemServices::step($action, $service)], $this->services->apply(...))) {
            return $preview;
        }
        $this->services->forget();
        $row = $this->services->all()[$service] ?? null;
        abort_if($row === null, 404);

        return new ServiceResource($row);
    }

    /** @return list<float> the load averages of 1, 5 and 15 minutes */
    private static function load(string $load): array
    {
        $values = preg_split('/[\s\/,]+/', trim($load), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map('floatval', array_filter($values, 'is_numeric')));
    }
}
