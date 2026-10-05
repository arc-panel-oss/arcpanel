<?php

namespace App\Services\Support;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\Capabilities;
use App\Services\UserDirectory;

/**
 * Whose objects a list page shows and who may own a new object: every user
 * for administrators, the reseller's tree for resellers, the effective user
 * for everyone else (docs/design/03 §7.1). An owner outside the scope
 * answers 404.
 */
final class OwnerList
{
    public function __construct(
        private readonly OwnerScope $scope,
        private readonly UserDirectory $directory,
        private readonly Principal $principal,
    ) {}

    /** The owners of a list page and its ?owner= filter. */
    public function forList(mixed $requested): OwnerSelection
    {
        $visible = $this->scope->visibleUsers();
        $everyUser = $visible === null;
        $owners = $everyUser ? $this->directory->names() : $visible;

        $owner = is_string($requested) ? trim($requested) : '';
        if ($owner !== '') {
            $this->scope->assertVisible($owner);
            abort_unless(in_array($owner, $owners, true), 404);
        }

        return new OwnerSelection($owners, $owner, $everyUser);
    }

    /** The owner of a new object: administrators and resellers pick one, everyone else gets themselves. */
    public function forCreate(mixed $requested): string
    {
        $owner = is_string($requested) && $requested !== '' ? $requested : $this->principal->effectiveUser();
        $this->scope->assertVisible($owner);
        abort_unless($owner === $this->principal->effectiveUser() || in_array($owner, $this->directory->names(), true), 404);

        return $owner;
    }

    /** @return list<string>|null owners of the create form's picker, null when there is no choice */
    public function pickable(): ?array
    {
        return Capabilities::allows($this->principal->actingRole(), 'users.view')
            ? ($this->scope->visibleUsers() ?? $this->directory->names())
            : null;
    }

    /** Whether $owner is an administrator (the "create a standard user" hint of the add pages). */
    public function isAdmin(string $owner): bool
    {
        return ($this->directory->all()[$owner]['ROLE'] ?? '') === 'admin';
    }
}
