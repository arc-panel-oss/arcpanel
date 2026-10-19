<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\FirewallStatusResource;
use App\Policies\FirewallPolicy;
use App\Services\Firewall\FirewallService;

/**
 * The state of the loaded firewall for administrators (E40 D13).
 * FIREWALL_SYSTEM off answers 404.
 *
 * @tags Server
 */
final class FirewallStatusController extends Controller
{
    public function __construct(private readonly FirewallService $firewall) {}

    /**
     * Check the loaded firewall
     *
     * Compares the nftables table that runs with the one the panel loaded
     * last, ignoring banned addresses. `drift` or `missing` means something
     * outside the panel changed or removed it; restarting the `nftables`
     * service loads the rules again.
     *
     * @operationId getFirewallStatus
     */
    public function show(Principal $principal, FirewallPolicy $policy): FirewallStatusResource
    {
        abort_unless($policy->viewAny($principal), 403);

        return new FirewallStatusResource($this->firewall->status());
    }
}
