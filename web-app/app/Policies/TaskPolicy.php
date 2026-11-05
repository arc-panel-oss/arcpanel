<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;

/**
 * Tasks of API v2 (E34 batch 3 spec D33), since E15 the jobs of the Job
 * Center (docs/features/specs/2026-11-05-001-e15-job-center-design.md §6.3):
 * a server job (USER system) needs `server.view`, any other job `tasks.view`
 * and the owner scope of its user. Cancel and retry need `tasks.manage` in the
 * same scope; a server job only for those who see the server.
 */
final class TaskPolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    /** The job of USER (`system` for the server's) */
    public function view(Principal $principal, string $jobUser): bool
    {
        if ($jobUser === 'system') {
            return Capabilities::allows($principal->actingRole(), 'server.view');
        }

        return Capabilities::allows($principal->actingRole(), 'tasks.view', fn () => $this->scope->canSee($jobUser));
    }

    /** Cancel or retry the job of USER */
    public function manage(Principal $principal, string $jobUser): bool
    {
        if ($jobUser === 'system') {
            return Capabilities::allows($principal->actingRole(), 'server.view')
                && Capabilities::allows($principal->actingRole(), 'tasks.manage');
        }

        return Capabilities::allows($principal->actingRole(), 'tasks.manage', fn () => $this->scope->canSee($jobUser));
    }

    public function viewUser(Principal $principal, string $user): bool
    {
        return Capabilities::allows($principal->actingRole(), 'tasks.view', fn () => $this->scope->canSee($user));
    }

    public function viewSystem(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'server.view');
    }

    /** Any job list (the Jobs page, GET /api/v2/tasks) */
    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'tasks.view');
    }

    /**
     * The users whose jobs the principal sees in a list (null: all), and
     * whether the server's jobs are among them.
     *
     * @return array{users: list<string>|null, system: bool}
     */
    public function visible(Principal $principal): array
    {
        return ['users' => $this->scope->visibleUsers(), 'system' => $this->viewSystem($principal)];
    }
}
