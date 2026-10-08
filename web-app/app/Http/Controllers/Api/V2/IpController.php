<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreIpRequest;
use App\Http\Requests\Api\V2\UpdateIpRequest;
use App\Http\Resources\V2\IpResource;
use App\Http\Resources\V2\NetworkInterfaceResource;
use App\Policies\IpPolicy;
use App\Services\Ips\IpService;
use App\Services\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * IP addresses of the server for administrators (E34 batch 6 spec §5.2):
 * Admin → Server → IP addresses as JSON, with the commands and the order
 * of the page (IpService). An IPv6 address in a path may be URL-encoded.
 *
 * @tags Server
 */
final class IpController extends Controller
{
    public function __construct(
        private readonly IpService $ips,
        private readonly UserDirectory $directory,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the IP addresses of the server
     *
     * @operationId listIps
     */
    public function index(Request $request, Principal $principal, IpPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $page = $this->pages->slice($request, $this->ips->all());

        return IpResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add an IP address
     *
     * `interface` is one of listInterfaces. An address the server already
     * has answers `409 E_EXISTS` with `existing`.
     *
     * @operationId createIp
     *
     * @status 201
     */
    public function store(StoreIpRequest $request, Principal $principal, IpPolicy $policy, SysConfig $config): IpResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validated();
        $ip = (string) $data['ip'];
        if (! in_array($data['interface'], $this->ips->interfaces(), true)) {
            throw ValidationException::withMessages(['interface' => __('This network interface does not exist.')]);
        }
        $owner = (string) ($data['owner'] ?? $config->get('ROOT_USER'));
        $this->assertAccount($owner);
        foreach ($this->ips->all() as $known) {
            if (strcasecmp($known->ip, $ip) === 0) {
                throw new ApiException(ErrorCode::Exists, sprintf(__('IP address %s already exists.'), $known->ip), 409, existing: self::path($known->ip));
            }
        }

        $step = IpService::planCreate($ip, (string) $data['netmask'], (string) $data['interface'], $owner,
            (bool) ($data['shared'] ?? true), (string) ($data['name'] ?? ''), (string) ($data['nat'] ?? ''));
        if ($preview = $this->gate->execute([$step], $this->ips->apply(...))) {
            return $preview;
        }

        return (new IpResource($this->ips->find($ip)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.ips.show', ['ip' => $ip]));
    }

    /**
     * Get an IP address
     *
     * @operationId getIp
     */
    public function show(string $ip, Principal $principal, IpPolicy $policy): IpResource
    {
        abort_unless($policy->viewAny($principal), 403);

        return new IpResource($this->ips->find($ip));
    }

    /**
     * Update an IP address
     *
     * `owner`, `shared`, `name` and `nat`; fields left out keep their value,
     * and only a changed field runs its command, in the order of the page.
     *
     * @operationId updateIp
     */
    public function update(UpdateIpRequest $request, string $ip, Principal $principal, IpPolicy $policy): IpResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $current = $this->ips->find($ip);
        $data = $request->validated();
        if (array_key_exists('owner', $data)) {
            $this->assertAccount((string) $data['owner']);
        }

        $steps = $this->ips->planUpdate(
            $current,
            array_key_exists('shared', $data) ? (bool) $data['shared'] : $current->shared(),
            (string) ($data['owner'] ?? ''),
            array_key_exists('name', $data) ? trim((string) $data['name']) : $current->name,
            array_key_exists('nat', $data) ? trim((string) $data['nat']) : $current->nat,
        );

        return $this->gate->execute($steps, $this->ips->apply(...)) ?? new IpResource($this->ips->find($current->ip));
    }

    /**
     * Delete an IP address
     *
     * @operationId deleteIp
     *
     * @status 204
     */
    public function destroy(string $ip, Principal $principal, IpPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $current = $this->ips->find($ip);

        return $this->gate->execute([IpService::planDelete($current->ip)], $this->ips->apply(...)) ?? response()->noContent();
    }

    /**
     * Refresh the IP addresses
     *
     * Runs `v-update-sys-ip`, which reads the addresses of the network
     * interfaces again, and answers the addresses.
     *
     * @operationId refreshIps
     */
    public function refresh(Principal $principal, IpPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        if ($preview = $this->gate->execute([IpService::planRefresh()], $this->ips->apply(...))) {
            return $preview;
        }
        $ips = $this->ips->all();

        return IpResource::collection($ips)->additional(['meta' => ['total' => count($ips)]]);
    }

    /**
     * List the network interfaces of the server
     *
     * The choices of `interface` in createIp.
     *
     * @operationId listInterfaces
     */
    public function interfaces(Request $request, Principal $principal, IpPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $page = $this->pages->slice($request, $this->ips->interfaces());

        return NetworkInterfaceResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    private function assertAccount(string $owner): void
    {
        if (! in_array($owner, $this->directory->names(), true)) {
            throw ValidationException::withMessages(['owner' => __('This user does not exist.')]);
        }
    }

    private static function path(string $ip): string
    {
        return '/api/v2/system/ips/'.$ip;
    }
}
