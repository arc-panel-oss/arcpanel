<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;

/**
 * Backups (E7 batch 1 spec §5.2, D7): administrators, resellers for their
 * tree and users for themselves list, schedule, restore and download
 * backups; nothing changes in read-only mode.
 */
final class BackupPolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'backups.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'backups.view', fn () => $this->scope->canSee($owner));
    }

    /** Download an archive (web/download/backup has no read-only check). */
    public function download(Principal $principal, string $owner): bool
    {
        return $this->view($principal, $owner);
    }

    public function create(Principal $principal, string $owner): bool
    {
        return $this->allows($principal, 'backups.create', $owner);
    }

    public function restore(Principal $principal, string $owner): bool
    {
        return $this->allows($principal, 'backups.restore', $owner);
    }

    public function delete(Principal $principal, string $owner): bool
    {
        return $this->allows($principal, 'backups.delete', $owner);
    }

    public function editExclusions(Principal $principal, string $owner): bool
    {
        return $this->allows($principal, 'backups.exclusions.edit', $owner);
    }

    /** The backup runs of OWNER (E48 spec §8.1 History). */
    public function viewHistory(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'backups.history.view', fn () => $this->scope->canSee($owner));
    }

    /** The administrators' Backups hub: overview and activity (E48 spec §8.2). */
    public function viewHub(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'backups.activity.view');
    }

    public function viewDestinations(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'backups.destinations.manage');
    }

    public function manageDestinations(Principal $principal): bool
    {
        return $this->viewDestinations($principal) && ! $principal->isReadOnly();
    }

    public function viewJobs(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'backups.jobs.manage');
    }

    public function manageJobs(Principal $principal): bool
    {
        return $this->viewJobs($principal) && ! $principal->isReadOnly();
    }

    /** @return array<string, bool> what the views may show */
    public function abilities(Principal $principal, string $owner): array
    {
        return [
            'view' => $this->view($principal, $owner),
            'download' => $this->download($principal, $owner),
            'create' => $this->create($principal, $owner),
            'restore' => $this->restore($principal, $owner),
            'delete' => $this->delete($principal, $owner),
            'exclusions' => $this->editExclusions($principal, $owner),
        ];
    }

    private function allows(Principal $principal, string $capability, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), $capability, fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }
}
