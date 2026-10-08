<?php

namespace App\Services\Firewall;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rules of the firewall pages that the pages and REST API v2 share
 * (E34 batch 6 spec D59, D66): the existence checks of rules, IPset lists
 * and bans, the filter that keeps the port registry's rules out of changes
 * (E7 batch 2 spec D9), and what a new rule or a move needs to know about
 * the rule positions.
 */
final class FirewallActions
{
    public function __construct(private readonly FirewallService $firewall) {}

    /**
     * The rules at these positions; 404 when one does not exist.
     *
     * @param  list<string>  $ids
     * @return list<FirewallRule>
     */
    public function existingRules(array $ids): array
    {
        $rules = $this->firewall->rules();
        $found = [];
        foreach ($ids as $id) {
            if (! isset($rules[$id])) {
                throw new NotFoundHttpException;
            }
            $found[] = $rules[$id];
        }

        return $found;
    }

    /** Whether a rule may be changed, suspended or deleted: not one of the port registry's. */
    public static function changeable(FirewallRule $rule): bool
    {
        return ! $rule->managed();
    }

    /**
     * Split rules into the positions a change may touch and those of the
     * port registry, which it skips.
     *
     * @param  list<FirewallRule>  $rules
     * @return array{0: list<string>, 1: list<string>} [changeable ids, managed ids]
     */
    public static function partition(array $rules): array
    {
        $ids = [];
        $managed = [];
        foreach ($rules as $rule) {
            if (self::changeable($rule)) {
                $ids[] = $rule->id;
            } else {
                $managed[] = $rule->id;
            }
        }

        return [$ids, $managed];
    }

    /** The reason a managed rule gives (bulk results). */
    public static function managedReason(): string
    {
        return __('Managed by Ports');
    }

    /**
     * 404 unless every IPset list exists.
     *
     * @param  list<string>  $names
     */
    public function assertIpsetsExist(array $names): void
    {
        $known = array_map(fn (IpsetList $list) => $list->name, $this->firewall->ipsets());
        foreach ($names as $name) {
            if (! in_array($name, $known, true)) {
                throw new NotFoundHttpException;
            }
        }
    }

    /**
     * Ban list items ("CHAIN:IP") of listed bans: 422 for an item that is
     * not one, 404 for a ban that does not exist.
     *
     * @param  list<string>  $items
     * @return list<array{0: string, 1: string}> [chain, ip]
     */
    public function existingBans(array $items): array
    {
        $known = array_map(fn (FirewallBan $ban) => $ban->item(), $this->firewall->bans());
        $bans = [];
        foreach (array_values(array_unique($items)) as $item) {
            $ban = FirewallService::parseBanItem($item);
            if ($ban === null) {
                throw new HttpException(422);
            }
            if (! in_array($item, $known, true)) {
                throw new NotFoundHttpException;
            }
            $bans[] = $ban;
        }

        return $bans;
    }

    /** The ban of $ip in $chain; 404 when there is none. */
    public function ban(string $chain, string $ip): FirewallBan
    {
        foreach ($this->firewall->bans() as $ban) {
            if ($ban->chain === $chain && $ban->ip === $ip) {
                return $ban;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * A rule identical to this one (same action, protocol, port and
     * address; D59), or null.
     */
    public function duplicateOf(string $action, string $protocol, string $port, string $ip): ?FirewallRule
    {
        $port = FirewallService::normalizePort($port);
        foreach ($this->firewall->rules() as $rule) {
            if (strcasecmp($rule->action, $action) === 0 && strcasecmp($rule->protocol, $protocol) === 0
                && FirewallService::normalizePort($rule->port) === $port && $rule->ip === $ip) {
                return $rule;
            }
        }

        return null;
    }

    /** The position a new rule gets: one after the last (D59). */
    public function nextId(): int
    {
        $ids = array_map('intval', array_keys($this->firewall->rules()));

        return $ids === [] ? 1 : max($ids) + 1;
    }

    /**
     * The rule a move swaps $rule with: the one at the position above or
     * below. Null when there is none (the first rule up, the last down, or
     * a gap in the positions, which `v-move-firewall-rule` cannot handle).
     *
     * @param  'up'|'down'  $direction
     */
    public function neighbour(FirewallRule $rule, string $direction): ?FirewallRule
    {
        $target = (string) ((int) $rule->id + ($direction === 'up' ? -1 : 1));

        return $this->firewall->rules()[$target] ?? null;
    }
}
