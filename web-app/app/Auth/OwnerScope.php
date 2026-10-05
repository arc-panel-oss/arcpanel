<?php

namespace App\Auth;

use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which users the principal may see and act on (docs/design/03 §7.1).
 *
 * Admin: every user. User (and an admin impersonating someone): only the
 * effective user. The reseller tree arrives with E11; until then a reseller
 * sees only itself and Capabilities denies the rest.
 */
final class OwnerScope
{
    public function __construct(private readonly Principal $principal) {}

    /** @return list<string>|null usernames, or null for "all" */
    public function visibleUsers(): ?array
    {
        return $this->principal->actingRole() === Role::Admin
            ? null
            : [$this->principal->effectiveUser()];
    }

    public function canSee(string $user): bool
    {
        $visible = $this->visibleUsers();

        return $user !== '' && ($visible === null || in_array($user, $visible, true));
    }

    public function canManage(string $user): bool
    {
        return $this->canSee($user);
    }

    /** 404, not 403, so usernames outside the scope cannot be probed. */
    public function assertVisible(string $user): void
    {
        if (! $this->canSee($user)) {
            throw new NotFoundHttpException;
        }
    }

    /** @return list<string> */
    public function tree(string $reseller): array
    {
        throw new LogicException('OwnerScope::tree() arrives with the reseller role (E11).');
    }
}
