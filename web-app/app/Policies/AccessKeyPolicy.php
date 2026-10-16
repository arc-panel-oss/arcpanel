<?php

namespace App\Policies;

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Policies\Concerns\ReachesOwner;

/**
 * API access keys (E7 batch 2 spec D7, §5.2). API_SYSTEM gates the module:
 * 0 off, 1 the ROOT_USER's keys only, 2 every account. Administrators
 * manage the keys of other users; everyone else its own, with permission
 * sets of ROLE 'user' unless the owner is ROOT_USER.
 */
final class AccessKeyPolicy
{
    use ReachesOwner;

    public function __construct(
        private readonly OwnerScope $scope,
        private readonly SysConfig $config,
    ) {}

    /** Whether the owner may have access keys at all (legacy $api_status). */
    public function available(string $owner): bool
    {
        return self::apiAvailable($this->config, $owner);
    }

    public static function apiAvailable(SysConfig $config, string $owner): bool
    {
        $api = (int) $config->get('API_SYSTEM', '0');

        return $api >= 1 && ($owner === $config->get('ROOT_USER') || $api >= 2);
    }

    public function view(Principal $principal, string $owner): bool
    {
        return $this->available($owner)
            && Capabilities::allows($principal->actingRole(), 'access-keys.view', fn () => $this->reaches($principal, 'access-keys.view', $owner));
    }

    public function manage(Principal $principal, string $owner): bool
    {
        return $this->available($owner) && ! $principal->isReadOnly()
            && Capabilities::allows($principal->actingRole(), 'access-keys.manage', fn () => $this->reachesForChange($principal, 'access-keys.manage', $owner));
    }

    /**
     * Names of the permission sets the owner's keys may carry, sorted; none
     * for a principal the protected-administrator guard blocks (E39 W4 §5).
     *
     * @param  array<array-key, mixed>  $sets  `v-list-apis json`
     * @return list<string>
     */
    public function allowedSets(string $owner, array $sets): array
    {
        if ($this->scope->protects($owner)) {
            return [];
        }
        $root = $owner === $this->config->get('ROOT_USER');
        $names = [];
        foreach ($sets as $name => $set) {
            if ($root || (is_array($set) && ($set['ROLE'] ?? '') === 'user')) {
                $names[] = (string) $name;
            }
        }
        sort($names);

        return $names;
    }
}
