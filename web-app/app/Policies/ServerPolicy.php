<?php

namespace App\Policies;

use App\Auth\Principal;

/**
 * Server health and services, read only in E1-S2 (dashboard widgets).
 */
final class ServerPolicy
{
    public function viewHealth(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }

    public function viewServices(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }
}
