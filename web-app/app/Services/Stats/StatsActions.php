<?php

namespace App\Services\Stats;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\StatsPolicy;
use App\Services\UserDirectory;

/**
 * Which months of statistics a principal reads (E7 batch 2 spec §9.4),
 * shared by the Statistics page and API v2 (E34 batch 6 D66): one account
 * of the scope, the server totals for administrators, the totals of the
 * tree for resellers, or the principal's own account.
 */
final class StatsActions
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly StatsPolicy $policy,
        private readonly OwnerScope $scope,
        private readonly UserDirectory $directory,
        private readonly SysConfig $config,
    ) {}

    /**
     * The months for $owner, or with '' the totals the principal sees. An
     * owner outside the scope answers 404, a refused one 403.
     *
     * @return array{scope: 'user'|'server'|'tree', owner: string, months: list<StatsMonth>}
     */
    public function resolve(Principal $principal, string $owner): array
    {
        abort_unless($this->policy->viewAny($principal), 403);

        $overall = $this->policy->overall($principal);
        $tree = $this->policy->tree($principal);
        if ($owner === '' && ! $overall && ! $tree) {
            $owner = $principal->effectiveUser();
        }
        if ($owner !== '') {
            $this->scope->assertVisible($owner);
            abort_unless(in_array($owner, $this->directory->names(), true) || $owner === $principal->effectiveUser(), 404);
            abort_unless($this->policy->view($principal, $owner), 403);

            return ['scope' => 'user', 'owner' => $owner, 'months' => $this->stats->forUser($owner)];
        }

        return $overall
            ? ['scope' => 'server', 'owner' => '', 'months' => $this->stats->overall()]
            : ['scope' => 'tree', 'owner' => '', 'months' => $this->stats->tree($principal->effectiveUser())];
    }

    /**
     * The accounts the principal may pick on the page: every user for an
     * administrator (without the hidden administrator), the tree for a
     * reseller, none for a user.
     *
     * @return list<string>|null
     */
    public function owners(Principal $principal): ?array
    {
        if ($this->policy->overall($principal)) {
            return array_values(array_filter($this->directory->names(),
                fn (string $name) => ! ($this->config->is('POLICY_SYSTEM_HIDE_ADMIN') && $name === $this->config->get('ROOT_USER'))));
        }

        return $this->policy->tree($principal) ? $this->scope->tree($principal->effectiveUser()) : null;
    }
}
