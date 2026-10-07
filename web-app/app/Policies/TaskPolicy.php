<?php

namespace App\Policies;

use App\Api\TaskId;
use App\Auth\OwnerScope;
use App\Auth\Principal;

/** Task reads of API v2 (E34 batch 3 spec D33). */
final class TaskPolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    public function view(Principal $principal, TaskId $id): bool
    {
        return match ($id->source) {
            TaskId::SOURCE_SYS, TaskId::SOURCE_DOCKER_ENGINE => Capabilities::allows($principal->actingRole(), 'server.view'),
            default => Capabilities::allows($principal->actingRole(), 'tasks.view', fn () => $this->scope->canSee((string) $id->user)),
        };
    }

    public function viewUser(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'tasks.view', fn () => $this->scope->canSee($user));
    }

    public function viewSystem(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }
}
