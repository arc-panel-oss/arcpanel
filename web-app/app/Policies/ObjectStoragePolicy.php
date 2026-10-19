<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;

/**
 * Object storage (E44 spec §10–§11): every role on the accounts it can see.
 * Adding buckets and keys also needs the account's package to include
 * object storage, which the commands check; deleting does not, so an
 * account whose package lost it can still free its storage. The service of
 * the server is for administrators. Nothing in read-only mode.
 */
final class ObjectStoragePolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    /** See the buckets and S3 keys of $user. */
    public function view(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'storage.view', fn () => $this->scope->canSee($user));
    }

    /** Add, change and delete the buckets and S3 keys of $user. */
    public function manage(Principal $principal, string $user): bool
    {
        return ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'storage.manage', fn () => $this->scope->canManage($user));
    }

    /** See every account's buckets (the cross-user list) and the service. */
    public function viewServer(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'storage.server');
    }

    /** Install the service, set its endpoint. */
    public function manageServer(Principal $principal): bool
    {
        return ! $principal->isReadOnly() && Capabilities::allows($principal->actingRole(), 'storage.server');
    }
}
