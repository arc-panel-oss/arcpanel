<?php

namespace App\Auth;

use App\Tenancy\TenancyDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which users the principal may see and act on (docs/design/03 §7.1).
 *
 * Admin: every user. Reseller: itself and its customers (E11). User (and
 * anyone impersonating someone): only the effective user.
 */
final class OwnerScope
{
    public function __construct(
        private readonly Principal $principal,
        private readonly TenancyDirectory $tenancy,
    ) {}

    /** @return list<string>|null usernames, or null for "all" */
    public function visibleUsers(): ?array
    {
        return match ($this->principal->actingRole()) {
            Role::Admin => null,
            Role::Reseller => $this->tree($this->principal->effectiveUser()),
            default => [$this->principal->effectiveUser()],
        };
    }

    public function canSee(string $user): bool
    {
        $visible = $this->visibleUsers();

        return $user !== '' && ($visible === null || in_array($user, $visible, true));
    }

    /** Objects (web domains …) of $user: the same reach as canSee(). */
    public function canManage(string $user): bool
    {
        return $this->canSee($user);
    }

    /**
     * The account $user itself (suspend, delete, package, role): never the
     * principal's own account, which it edits through "My account".
     */
    public function canManageAccount(string $user): bool
    {
        if ($user === '' || $user === $this->principal->effectiveUser()) {
            return false;
        }

        return match ($this->principal->actingRole()) {
            Role::Admin => $this->tenancy->exists($user),
            Role::Reseller => $this->tenancy->isCustomerOf($user, $this->principal->effectiveUser()),
            default => false,
        };
    }

    /** 404, not 403, so usernames outside the scope cannot be probed. */
    public function assertVisible(string $user): void
    {
        if (! $this->canSee($user)) {
            throw new NotFoundHttpException;
        }
    }

    /** @return list<string> the reseller and its customers */
    public function tree(string $reseller): array
    {
        return [$reseller, ...$this->tenancy->customersOf($reseller)];
    }
}
