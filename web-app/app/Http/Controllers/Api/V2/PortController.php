<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\GivePortsRequest;
use App\Http\Requests\Api\V2\RequestPortsRequest;
use App\Http\Requests\Api\V2\ReservePortRequest;
use App\Http\Requests\Api\V2\SetPortPublicOkRequest;
use App\Http\Requests\Api\V2\SetPortPublicRequest;
use App\Http\Resources\V2\PortEntryResource;
use App\Policies\PortPolicy;
use App\Services\Ports\PortAccount;
use App\Services\Ports\PortActions;
use App\Services\Ports\PortEntry;
use App\Services\Ports\PortService;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The ports of a user (E34 batch 4 spec §4.5, D45): a JSON layer on the
 * plan steps of PortService with the rules of PortActions and PortPolicy,
 * as the Ports page. A range is named as `30005` or `30010-30019`.
 *
 * @tags Ports
 */
final class PortController extends Controller
{
    public function __construct(
        private readonly PortService $ports,
        private readonly PortActions $actions,
        private readonly PlanGate $gate,
        private readonly OwnerScope $scope,
    ) {}

    /**
     * List the ports of a user
     *
     * The entries of the user's pool: single ports (free or assigned to an
     * app) and free ranges. `meta` holds the totals of the pool and the
     * server range; the list is not paginated.
     *
     * @operationId listPorts
     */
    public function index(string $user, Principal $principal, PortPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);

        return PortEntryResource::list($this->ports->account($user));
    }

    /**
     * Reserve a port
     *
     * Assigns a port of the user's own pool (the first free one, or `port`)
     * to `manual` use, as "Reserve a port" on the page.
     *
     * @operationId reservePort
     */
    public function reserve(ReservePortRequest $request, string $user, Principal $principal, PortPolicy $policy): PortEntryResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->reserve($principal, $user), 403);
        $data = $request->validated();
        $port = isset($data['port']) ? (int) $data['port'] : null;
        $result = null;
        $step = $this->ports->planReserve($user, $port, (string) $data['proto'], (string) ($data['comment'] ?? ''));
        if ($preview = $this->run([$step], $result)) {
            return $preview;
        }
        $reserved = (int) trim((string) $result?->stdoutOf('v-allocate-user-port'));
        $entry = $this->ports->account($user)->entry($reserved > 0 ? $reserved : (int) $port);
        if ($entry === null) {
            throw new ApiException(ErrorCode::Server, __('The reserved port could not be read.'), 500);
        }

        return new PortEntryResource($entry);
    }

    /**
     * Give ports to a customer
     *
     * Moves free ports of `{user}`'s pool to `to`, one of its customers: a
     * `range` of free ports, or the `count` lowest free ones. Administrators
     * and resellers only (`PortPolicy::give`). Answers the ports of `to`.
     *
     * @operationId givePorts
     */
    public function give(GivePortsRequest $request, string $user, Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $data = $request->validated();
        $to = (string) $data['to'];
        if (! $this->actions->userExists($to) || ! $this->scope->canSee($to)) {
            throw ValidationException::withMessages(['to' => __('This user does not exist.')]);
        }
        abort_unless($policy->give($principal, $to), 403);
        if ($this->actions->ownerOf($to) !== $user) {
            throw ValidationException::withMessages(['to' => sprintf(__('%s is not a customer of %s.'), $to, $user)]);
        }
        [$range, $count] = $this->actions->rangeOrCount($data);
        if ($preview = $this->run([$this->ports->planMove($user, $this->actions->moveSpec($range, $count), $to)])) {
            return $preview;
        }

        return PortEntryResource::list($this->ports->account($to));
    }

    /**
     * Ask for more ports
     *
     * Sends a notification to the owner of the user (its reseller, or the
     * administrator). Only for the key's own account, never by an
     * administrator.
     *
     * @operationId requestPorts
     */
    public function request(RequestPortsRequest $request, string $user, Principal $principal, PortPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->request($principal) && $user === $principal->effectiveUser(), 403);
        $data = $request->validated();
        $count = (int) $data['count'];
        if ($preview = $this->run([$this->ports->planRequest($user, $count, trim((string) ($data['reason'] ?? '')))])) {
            return $preview;
        }

        return response()->json(['data' => ['owner' => $this->actions->ownerOf($user), 'count' => $count]]);
    }

    /**
     * Release a port
     *
     * Frees a port the user reserved or an app holds; it stays in the pool.
     *
     * @operationId releasePort
     */
    public function release(Request $request, string $user, string $range, Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->reserve($principal, $user), 403);
        $entry = $this->entry($request, $this->ports->account($user), $range);
        if ($preview = $this->run([$this->ports->planRelease($user, $entry->start)])) {
            return $preview;
        }

        return PortEntryResource::list($this->ports->account($user));
    }

    /**
     * Open or close a port to the internet
     *
     * Only an assigned single port; opening needs `public_ok`.
     *
     * @operationId setPortPublic
     */
    public function public(SetPortPublicRequest $request, string $user, string $range, Principal $principal, PortPolicy $policy): PortEntryResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $public = $request->boolean('public');
        $entry = $this->entry($request, $this->ports->account($user), $range);
        abort_unless($policy->setPublic($principal, $user, $entry, false), 403);
        if ($public && ! $policy->setPublic($principal, $user, $entry, true)) {
            throw new ApiException(ErrorCode::Forbidden, __('This port may not be open to the internet.'), 403);
        }
        if ($preview = $this->run([$this->ports->planPublic($user, $entry->start, $public)])) {
            return $preview;
        }

        return new PortEntryResource($this->ports->account($user)->entry($entry->start) ?? $entry);
    }

    /**
     * Allow or forbid public ports
     *
     * Administrators either way; a reseller may only forbid them on its
     * customers' ports.
     *
     * @operationId setPortPublicOk
     */
    public function publicOk(SetPortPublicOkRequest $request, string $user, string $range, Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $publicOk = $request->boolean('public_ok');
        abort_unless($policy->setPublicOk($principal, $user, $publicOk), 403);
        $this->assertHolds($user, $range);
        if ($preview = $this->run([$this->ports->planPublicOk($user, $range, $publicOk)])) {
            return $preview;
        }

        return PortEntryResource::list($this->ports->account($user));
    }

    /**
     * Take free ports back
     *
     * Moves free ports of a customer back into its reseller's pool.
     *
     * @operationId reclaimPorts
     */
    public function reclaim(string $user, string $range, Principal $principal, PortPolicy $policy): AnonymousResourceCollection|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->reclaim($principal, $user), 403);
        $this->assertHolds($user, $range);
        if ($preview = $this->run([$this->ports->planMove($user, $range, $this->actions->ownerOf($user))])) {
            return $preview;
        }

        return PortEntryResource::list($this->ports->account($user));
    }

    /**
     * Delete ports of a user
     *
     * Administrators only. `?force=1` also deletes assigned ports.
     *
     * @operationId deletePorts
     *
     * @status 204
     */
    public function destroy(Request $request, string $user, string $range, Principal $principal, PortPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->allocate($principal), 403);
        $this->assertHolds($user, $range);

        return $this->run([$this->ports->planDelete($user, $range, $request->boolean('force'))]) ?? response()->noContent();
    }

    /**
     * Authorize, preview or run the plan; a package limit answers 422.
     *
     * @param  list<Step>  $steps
     */
    private function run(array $steps, ?ApplyResult &$result = null): ?JsonResponse
    {
        return $this->gate->executeWithLimits($steps, function (array $steps) use (&$result): ApplyResult {
            return $result = $this->ports->apply($steps);
        });
    }

    /** {user} exists and the key may see its ports; 404 otherwise. */
    private function assertOwner(string $user, Principal $principal, PortPolicy $policy): void
    {
        abort_unless($this->actions->userExists($user) && $policy->view($principal, $user), 404);
    }

    /** Every port of $range belongs to $user; 404 otherwise. */
    private function assertHolds(string $user, string $range): void
    {
        abort_unless($this->actions->holds($this->ports->account($user), $range), 404);
    }

    /** The single port of $range, of the protocol in `?proto=` when given; 404 otherwise. */
    private function entry(Request $request, PortAccount $account, string $range): PortEntry
    {
        $proto = $request->query('proto');
        if ($proto !== null && ! in_array($proto, PortActions::PROTOS, true)) {
            throw ValidationException::withMessages(['proto' => __('Choose tcp or udp.')]);
        }
        $entry = $this->actions->entry($account, $range, $proto);
        abort_if($entry === null, 404);

        return $entry;
    }
}
