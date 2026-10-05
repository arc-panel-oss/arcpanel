<?php

namespace App\Policies;

use App\Auth\OwnerScope;
use App\Auth\Principal;

/**
 * Cron jobs (E7 batch 1 spec §5.2): capability from Capabilities, owner from
 * OwnerScope, and no change in read-only mode. Suspend follows update for
 * every role (D10), in bulk and for single jobs.
 */
final class CronPolicy
{
    public function __construct(private readonly OwnerScope $scope) {}

    public function viewAny(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'cron.view');
    }

    public function view(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'cron.view', fn () => $this->scope->canSee($owner));
    }

    public function create(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'cron.create', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function update(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'cron.update', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function delete(Principal $principal, string $owner): bool
    {
        return Capabilities::allows($principal->actingRole(), 'cron.delete', fn () => $this->scope->canManage($owner))
            && ! $principal->isReadOnly();
    }

    public function suspend(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner);
    }

    /** E-mail reports of the owner's cron jobs (CRON_REPORTS). */
    public function reports(Principal $principal, string $owner): bool
    {
        return $this->update($principal, $owner);
    }

    /** Whether a bulk or single action is allowed for the owner. */
    public function action(string $action, Principal $principal, string $owner): bool
    {
        return match ($action) {
            'delete' => $this->delete($principal, $owner),
            default => $this->suspend($principal, $owner),
        };
    }

    /**
     * What the views may show; they never compare user names (03 §7.3).
     *
     * @return array<string, bool>
     */
    public function abilities(Principal $principal, string $owner): array
    {
        return [
            'view' => $this->view($principal, $owner),
            'update' => $this->update($principal, $owner),
            'delete' => $this->delete($principal, $owner),
            'suspend' => $this->suspend($principal, $owner),
        ];
    }
}
