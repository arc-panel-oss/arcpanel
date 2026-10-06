<?php

namespace App\Policies;

use App\Auth\Principal;

/**
 * IP addresses of the server (E7 batch 2 spec §5.2, §7): administrators only.
 */
final class IpPolicy
{
    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.ips');
    }

    public function manage(Principal $principal): bool
    {
        return $this->viewAny($principal) && ! $principal->isReadOnly();
    }
}
