<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\AddReservedPortsRequest;
use App\Http\Requests\Api\V2\AdoptPortRuleRequest;
use App\Http\Requests\Api\V2\AllocatePortsRequest;
use App\Http\Requests\Api\V2\ClosePortRuleRequest;
use App\Http\Requests\Api\V2\RegisterPortRequest;
use App\Http\Requests\Api\V2\SetPortRangeRequest;
use App\Http\Resources\V2\PortEntryResource;
use App\Http\Resources\V2\PortFindingResource;
use App\Http\Resources\V2\PortRegistryResource;
use App\Policies\PortPolicy;
use App\Services\Ports\PortActions;
use App\Services\Ports\PortEntry;
use App\Services\Ports\PortService;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The port registry of the server for administrators (E34 batch 4 spec
 * §4.5, D45): Admin → Server → Ports as JSON, on the plan steps of
 * PortService with the rules of PortActions.
 *
 * @tags Server
 */
final class PortRegistryController extends Controller
{
    public function __construct(
        private readonly PortService $ports,
        private readonly PortActions $actions,
        private readonly PlanGate $gate,
    ) {}

    /**
     * Get the port registry
     *
     * The server range, the firewall, every account with its totals, every
     * entry, the reserved ports, the sockets that listen in the range and the
     * reconciliation findings.
     *
     * @operationId getPortRegistry
     */
    public function show(Principal $principal, PortPolicy $policy): PortRegistryResource
    {
        abort_unless($policy->viewServer($principal), 403);

        return new PortRegistryResource($this->ports->registry());
    }

    /**
     * Allocate ports to a user
     *
     * A `range` of the server range, or `count` ports as one block (from the
     * reseller's pool for a customer of a reseller). Answers the user's ports.
     *
     * @operationId allocatePorts
     */
    public function allocate(AllocatePortsRequest $request, Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validated();
        $user = $this->existingUser((string) $data['user']);
        [$range, $count] = $this->actions->rangeOrCount($data);
        $step = $this->ports->planAllocate($user, $range, $count, (bool) ($data['public_ok'] ?? false), (string) ($data['comment'] ?? ''));
        if ($preview = $this->run([$step])) {
            return $preview;
        }

        return PortEntryResource::list($this->ports->account($user));
    }

    /**
     * Change the server port range
     *
     * @operationId setPortRange
     */
    public function range(SetPortRangeRequest $request, Principal $principal, PortPolicy $policy): PortRegistryResource|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validated();
        if ($preview = $this->run([$this->ports->planRange((int) $data['start'], (int) $data['end'])])) {
            return $preview;
        }

        return new PortRegistryResource($this->ports->registry());
    }

    /**
     * Reserve ports of the server
     *
     * Keeps a port or a range out of every allocation.
     *
     * @operationId addReservedPorts
     */
    public function storeReserved(AddReservedPortsRequest $request, Principal $principal, PortPolicy $policy): PortRegistryResource|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validated();
        if ($preview = $this->run([$this->ports->planAddReserved((string) $data['range'], (string) ($data['comment'] ?? ''))])) {
            return $preview;
        }

        return new PortRegistryResource($this->ports->registry());
    }

    /**
     * Delete a reservation
     *
     * Reservations of the panel's own services (`kind` `system`) stay.
     *
     * @operationId deleteReservedPorts
     *
     * @status 204
     */
    public function destroyReserved(string $range, Principal $principal, PortPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $bounds = $this->actions->bounds($range);
        $label = $bounds === null ? '' : ($bounds[0] === $bounds[1] ? (string) $bounds[0] : $bounds[0].'-'.$bounds[1]);
        $reserved = array_values(array_filter($this->ports->registry()->reserved, fn (PortEntry $entry) => $entry->label() === $label));
        abort_if($reserved === [], 404);
        if ($reserved[0]->app === 'system') {
            throw new ApiException(ErrorCode::Forbidden, sprintf(__('Reservation %s belongs to a system service.'), $label), 403);
        }

        return $this->run([$this->ports->planDeleteReserved($label)]) ?? response()->noContent();
    }

    /**
     * Repair the registry
     *
     * Runs `v-check-sys-ports repair` and answers the findings that are left.
     *
     * @operationId repairPorts
     */
    public function repair(Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        if ($preview = $this->run([$this->ports->planRepair()])) {
            return $preview;
        }

        return PortFindingResource::collection($this->ports->registry()->findings);
    }

    /**
     * Register a listening port to its account
     *
     * For an `orphan-socket` finding: allocates the port to `user` and
     * reserves it there. Answers the user's ports.
     *
     * @operationId registerPort
     */
    public function register(RegisterPortRequest $request, Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validated();
        $user = $this->existingUser((string) $data['user']);
        if ($preview = $this->run($this->ports->planRegister($user, (int) $data['port'], (string) $data['proto']))) {
            return $preview;
        }

        return PortEntryResource::list($this->ports->account($user));
    }

    /**
     * Link a firewall rule to its port
     *
     * For an `unmanaged-rule` finding: the assigned single port becomes
     * public through that rule. Answers the port.
     *
     * @operationId adoptPortRule
     */
    public function adopt(AdoptPortRuleRequest $request, Principal $principal, PortPolicy $policy): PortEntryResource|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);
        $data = $request->validated();
        $port = (int) $data['port'];
        $holder = $this->actions->adoptable($port);
        if ($holder === null) {
            throw ValidationException::withMessages(['port' => __('Reserve the port for its user first, then link the rule.')]);
        }
        if ($preview = $this->run($this->ports->planAdopt($holder->user, $port, (string) $data['rule']))) {
            return $preview;
        }

        return new PortEntryResource($this->ports->account($holder->user)->entry($port) ?? $holder);
    }

    /**
     * Delete a firewall rule
     *
     * For a `stale-rule` finding: deletes the rule that opens no public port.
     *
     * @operationId closePortRule
     *
     * @status 204
     */
    public function closeRule(ClosePortRuleRequest $request, Principal $principal, PortPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->allocate($principal), 403);

        return $this->run([$this->ports->planCloseRule((string) $request->validated()['rule'])]) ?? response()->noContent();
    }

    /** @param  list<Step>  $steps */
    private function run(array $steps): ?JsonResponse
    {
        return $this->gate->execute($steps, $this->ports->apply(...));
    }

    private function existingUser(string $user): string
    {
        if (! $this->actions->userExists($user)) {
            throw ValidationException::withMessages(['user' => __('This user does not exist.')]);
        }

        return $user;
    }
}
