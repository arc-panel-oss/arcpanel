<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreFirewallIpsetRequest;
use App\Http\Resources\V2\FirewallIpsetResource;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallActions;
use App\Services\Firewall\FirewallService;
use App\Services\Firewall\IpsetList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * IPset lists of the firewall for administrators (E34 batch 6 spec §5.3):
 * Admin → Server → Firewall → IPset IP lists as JSON. FIREWALL_SYSTEM off
 * answers 404.
 *
 * @tags Server
 */
final class FirewallIpsetController extends Controller
{
    public function __construct(
        private readonly FirewallService $firewall,
        private readonly FirewallActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the IPset lists of the firewall
     *
     * Sorted by name.
     *
     * @operationId listFirewallIpsets
     */
    public function index(Request $request, Principal $principal, FirewallPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $page = $this->pages->slice($request, $this->firewall->ipsets());

        return FirewallIpsetResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add an IPset list
     *
     * A rule uses an active IPv4 list as `source` `ipset:NAME`. A name that
     * exists answers `409 E_EXISTS`.
     *
     * @operationId createFirewallIpset
     *
     * @status 201
     */
    public function store(StoreFirewallIpsetRequest $request, Principal $principal, FirewallPolicy $policy): FirewallIpsetResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validated();
        $name = (string) $data['name'];
        if ($this->find($name) !== null) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('IP list %s already exists.'), $name), 409);
        }
        $source = trim((string) $data['source']);
        $autoUpdate = (bool) ($data['auto_update'] ?? false);

        $step = FirewallService::planCreateIpset($name, $source, (string) $data['ip_version'], $autoUpdate);
        if ($preview = $this->gate->execute([$step], $this->firewall->apply(...))) {
            return $preview;
        }
        $list = $this->find($name) ?? new IpsetList($name, $source, (string) $data['ip_version'], $autoUpdate, false, '', '');

        return (new FirewallIpsetResource($list))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.firewall.ipsets.index'));
    }

    /**
     * Delete an IPset list
     *
     * @operationId deleteFirewallIpset
     *
     * @status 204
     */
    public function destroy(string $name, Principal $principal, FirewallPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $this->actions->assertIpsetsExist([$name]);

        return $this->gate->execute([FirewallService::planDeleteIpset($name)], $this->firewall->apply(...)) ?? response()->noContent();
    }

    private function find(string $name): ?IpsetList
    {
        foreach ($this->firewall->ipsets() as $list) {
            if ($list->name === $name) {
                return $list;
            }
        }

        return null;
    }
}
