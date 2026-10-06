<?php

namespace App\Policies;

use App\Auth\Principal;

/**
 * Firewall rules, IPset lists and the ban list (E7 batch 2 spec §5.2, §6):
 * administrators only. Rules of the port registry (E12 spec D7) are changed
 * through the Ports pages, never here (D9); moving them stays allowed.
 */
final class FirewallPolicy
{
    /** Comment of a rule written by the port registry (E12 spec D7). */
    private const MANAGED_COMMENT = '/^arcpanel-port-\d+-[a-z0-9._-]+$/';

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.firewall');
    }

    public function manage(Principal $principal): bool
    {
        return $this->viewAny($principal) && ! $principal->isReadOnly();
    }

    /** Edit, suspend, unsuspend or delete a rule with this comment. */
    public function change(Principal $principal, string $comment): bool
    {
        return $this->manage($principal) && ! self::isManaged($comment);
    }

    public static function isManaged(string $comment): bool
    {
        return preg_match(self::MANAGED_COMMENT, $comment) === 1;
    }
}
