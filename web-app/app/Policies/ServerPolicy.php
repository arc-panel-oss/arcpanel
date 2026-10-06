<?php

namespace App\Policies;

use App\Auth\Principal;

/**
 * Server health and services, read only in E1-S2 (dashboard widgets), and
 * the server graphs (E7 batch 2).
 */
final class ServerPolicy
{
    public function viewHealth(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }

    /** The Task Monitor page and the graph data (E7 batch 2 spec §9.5). */
    public function viewGraphs(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.rrd');
    }

    public function viewServices(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }
}
