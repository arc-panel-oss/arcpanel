<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreFirewallBanRequest;
use App\Http\Resources\V2\FirewallBanResource;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallActions;
use App\Services\Firewall\FirewallBan;
use App\Services\Firewall\FirewallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The ban list of the firewall extension for administrators (E34 batch 6
 * spec §5.3): Admin → Server → Firewall → Banned IP addresses as JSON.
 * FIREWALL_SYSTEM or FIREWALL_EXTENSION off answers 404.
 *
 * @tags Server
 */
final class FirewallBanController extends Controller
{
    public function __construct(
        private readonly FirewallService $firewall,
        private readonly FirewallActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the banned addresses
     *
     * Newest first.
     *
     * @operationId listFirewallBans
     */
    public function index(Request $request, Principal $principal, FirewallPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $page = $this->pages->slice($request, $this->firewall->bans());

        return FirewallBanResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Ban an address
     *
     * Adds `ip` to the ban list of `chain`. An address banned in that chain
     * already answers `409 E_EXISTS`.
     *
     * @operationId createFirewallBan
     *
     * @status 201
     */
    public function store(StoreFirewallBanRequest $request, Principal $principal, FirewallPolicy $policy): FirewallBanResource|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $data = $request->validated();
        $ip = (string) $data['ip'];
        $chain = (string) $data['chain'];
        if ($this->find($chain, $ip) !== null) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('%s is banned in %s already.'), $ip, $chain), 409);
        }

        if ($preview = $this->gate->execute([FirewallService::planCreateBan($ip, $chain)], $this->firewall->apply(...))) {
            return $preview;
        }

        return (new FirewallBanResource($this->find($chain, $ip) ?? new FirewallBan($ip, $chain, '', '')))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.firewall.bans.index'));
    }

    /**
     * Unban an address
     *
     * `ip` is the banned address or network, URL-encoded (`2001%3Adb8%3A%3A7`,
     * `198.51.100.0%2F24`).
     *
     * @operationId deleteFirewallBan
     *
     * @status 204
     */
    public function destroy(string $chain, string $ip, Principal $principal, FirewallPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manage($principal), 403);
        $ban = $this->actions->ban($chain, $ip);

        return $this->gate->execute([FirewallService::planDeleteBan($ban->ip, $ban->chain)], $this->firewall->apply(...)) ?? response()->noContent();
    }

    private function find(string $chain, string $ip): ?FirewallBan
    {
        try {
            return $this->actions->ban($chain, $ip);
        } catch (NotFoundHttpException) {
            return null;
        }
    }
}
