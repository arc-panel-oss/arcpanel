<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * The audit log of the server (E23 spec D9, §7): administrators read and
 * verify everything; resellers read the events of their tree; users have
 * the Logs page only. While an administrator acts as a user, the user's
 * rules apply.
 */
final class AuditPolicy
{
    public function view(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'audit.view');
    }

    public function verify(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'audit.verify');
    }

    /** The reseller whose tree limits the reads (OWNER), null for an administrator. */
    public function owner(Principal $principal): ?string
    {
        return $principal->actingRole() === Role::Admin ? null : $principal->effectiveUser();
    }
}
