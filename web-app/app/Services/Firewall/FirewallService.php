<?php

namespace App\Services\Firewall;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;

/**
 * Firewall rules, IPset lists and the ban list through the v-* commands,
 * with the arguments and order of the legacy pages (web/list|add|edit|bulk|
 * move|delete/firewall, …/firewall/ipset, …/firewall/banlist).
 */
final class FirewallService
{
    public const BULK_COMMANDS = [
        'delete' => 'v-delete-firewall-rule',
        'suspend' => 'v-suspend-firewall-rule',
        'unsuspend' => 'v-unsuspend-firewall-rule',
    ];

    public const ACTIONS = ['DROP', 'ACCEPT'];

    public const PROTOCOLS = ['TCP', 'UDP', 'ICMP'];

    /** Chains of the ban list (web/add/firewall/banlist); RECIDIVE since E40. */
    public const CHAINS = ['SSH', 'WEB', 'FTP', 'DNS', 'MAIL', 'DB', 'ARCPANEL', 'RECIDIVE'];

    /** Values of FIREWALL_SYSTEM: nftables since 2.3 (E40), iptables before. */
    public const ENGINES = ['nftables', 'iptables'];

    /** @var array<string, FirewallRule>|null per-request cache */
    private ?array $rules = null;

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
        private readonly PlanRunner $plans,
    ) {}

    /** @return array<string, FirewallRule> position => rule, in rule order */
    public function rules(): array
    {
        if ($this->rules === null) {
            $rules = [];
            foreach ($this->arc->json('v-list-firewall') as $id => $row) {
                if (is_array($row)) {
                    $rules[(string) $id] = FirewallRule::fromArray((string) $id, $row);
                }
            }
            uksort($rules, fn (string $a, string $b) => (int) $a <=> (int) $b);
            $this->rules = $rules;
        }

        return $this->rules;
    }

    /** One rule; 404 when there is no rule at that position. */
    public function rule(string $id): FirewallRule
    {
        return FirewallRule::fromArray($id, $this->lookup->one('v-list-firewall-rule', [$id], $id));
    }

    /**
     * Active lists, the choices of the "IPset IP Lists" select
     * (web/add/firewall); IPv6 lists too since E40.
     *
     * @return list<string>
     */
    public function ruleIpsets(): array
    {
        $names = [];
        foreach ($this->ipsets() as $list) {
            if (! $list->suspended) {
                $names[] = $list->name;
            }
        }

        return $names;
    }

    /** `v-check-firewall json`: the loaded table against the last load (E40 D13). */
    public function status(): FirewallStatus
    {
        return FirewallStatus::fromArray($this->arc->json('v-check-firewall'));
    }

    /** `v-update-firewall`: load the table again. */
    public function reload(): CommandResult
    {
        return $this->arc->run('v-update-firewall', [], Options::allowFailure());
    }

    /**
     * The port field as web/add/firewall sends it: spaces become commas,
     * repeated commas collapse, outer commas go.
     */
    public static function normalizePort(string $port): string
    {
        return trim((string) preg_replace('/,+/', ',', str_replace(' ', ',', $port)), ',');
    }

    /** `v-add-firewall-rule ACTION IP PORT PROTOCOL COMMENT` */
    public function create(string $action, string $ip, string $port, string $protocol, string $comment): CommandResult
    {
        $this->rules = null;

        return $this->arc->run('v-add-firewall-rule', [$action, $ip, self::normalizePort($port), $protocol, $comment], Options::allowFailure());
    }

    /** `v-change-firewall-rule RULE ACTION IP PORT PROTOCOL COMMENT` */
    public function update(string $id, string $action, string $ip, string $port, string $protocol, string $comment): CommandResult
    {
        $this->rules = null;

        return $this->arc->run('v-change-firewall-rule', [$id, $action, $ip, self::normalizePort($port), $protocol, $comment], Options::allowFailure());
    }

    /** `v-move-firewall-rule RULE up|down` */
    public function move(string $id, string $direction): CommandResult
    {
        $this->rules = null;

        return $this->arc->run('v-move-firewall-rule', [$id, $direction], Options::allowFailure());
    }

    /**
     * Delete, suspend or unsuspend rules (web/bulk/firewall and the single
     * actions); the caller has left out the managed rules.
     *
     * @param  list<string>  $ids
     */
    public function bulk(string $action, array $ids): BulkResult
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $this->rules = null;

        return $this->runner->run($ids, fn (string $id) => ['#'.$id, $command, [$id]]);
    }

    /**
     * `v-add-firewall-rule ACTION IP PORT PROTOCOL COMMENT RULE` with the
     * position the rule gets (REST API v2, E34 batch 6 D59).
     */
    public static function planCreate(string $action, string $ip, string $port, string $protocol, string $comment, int $id): Step
    {
        return new Step(sprintf(__('Add firewall rule %d'), $id), 'v-add-firewall-rule', [$action, $ip, self::normalizePort($port), $protocol, $comment, (string) $id]);
    }

    /** `v-change-firewall-rule RULE ACTION IP PORT PROTOCOL COMMENT` */
    public static function planUpdate(string $id, string $action, string $ip, string $port, string $protocol, string $comment): Step
    {
        return new Step(sprintf(__('Change firewall rule %s'), $id), 'v-change-firewall-rule', [$id, $action, $ip, self::normalizePort($port), $protocol, $comment]);
    }

    /** `v-move-firewall-rule RULE up|down` */
    public static function planMove(string $id, string $direction): Step
    {
        return new Step(sprintf($direction === 'up' ? __('Move firewall rule %s up') : __('Move firewall rule %s down'), $id), 'v-move-firewall-rule', [$id, $direction]);
    }

    /** Delete, suspend or unsuspend one rule (BULK_COMMANDS). */
    public static function planRule(string $action, string $id): Step
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");

        return new Step(sprintf(match ($action) {
            'delete' => __('Delete firewall rule %s'),
            'suspend' => __('Suspend firewall rule %s'),
            default => __('Unsuspend firewall rule %s'),
        }, $id), $command, [$id]);
    }

    /** `v-add-firewall-ipset NAME DATASOURCE IPVER AUTOUPDATE` */
    public static function planCreateIpset(string $name, string $source, string $version, bool $autoUpdate): Step
    {
        return new Step(sprintf(__('Add IP list %s'), $name), 'v-add-firewall-ipset', [$name, $source, $version, $autoUpdate ? 'yes' : 'no']);
    }

    /** `v-delete-firewall-ipset NAME` */
    public static function planDeleteIpset(string $name): Step
    {
        return new Step(sprintf(__('Delete IP list %s'), $name), 'v-delete-firewall-ipset', [$name]);
    }

    /** `v-add-firewall-ban IP CHAIN` */
    public static function planCreateBan(string $ip, string $chain): Step
    {
        return new Step(sprintf(__('Ban %s'), $ip), 'v-add-firewall-ban', [$ip, $chain]);
    }

    /** `v-delete-firewall-ban IP CHAIN` */
    public static function planDeleteBan(string $ip, string $chain): Step
    {
        return new Step(sprintf(__('Unban %s'), $ip), 'v-delete-firewall-ban', [$ip, $chain]);
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $this->rules = null;

        return $this->plans->apply($steps);
    }

    /** Read the rules again on the next call. */
    public function forget(): void
    {
        $this->rules = null;
    }

    /** @return list<IpsetList> sorted by name */
    public function ipsets(): array
    {
        $lists = [];
        foreach ($this->arc->json('v-list-firewall-ipset') as $name => $row) {
            if (is_array($row)) {
                $lists[] = IpsetList::fromArray((string) $name, $row);
            }
        }
        usort($lists, fn (IpsetList $a, IpsetList $b) => strcmp($a->name, $b->name));

        return $lists;
    }

    /** `v-add-firewall-ipset NAME DATASOURCE IPVER AUTOUPDATE` */
    public function createIpset(string $name, string $source, string $version, string $autoupdate): CommandResult
    {
        return $this->arc->run('v-add-firewall-ipset', [$name, $source, $version, $autoupdate], Options::allowFailure());
    }

    /** @param  list<string>  $names */
    public function deleteIpsets(array $names): BulkResult
    {
        return $this->runner->run($names, fn (string $name) => [$name, 'v-delete-firewall-ipset', [$name]]);
    }

    /** @return list<FirewallBan> newest first, as web/list/firewall/banlist */
    public function bans(): array
    {
        $bans = [];
        foreach ($this->arc->json('v-list-firewall-ban') as $ip => $row) {
            if (is_array($row)) {
                $bans[] = FirewallBan::fromArray((string) $ip, $row);
            }
        }

        return array_reverse($bans);
    }

    /** `v-add-firewall-ban IP CHAIN` */
    public function createBan(string $ip, string $chain): CommandResult
    {
        return $this->arc->run('v-add-firewall-ban', [$ip, $chain], Options::allowFailure());
    }

    /** @param  list<array{0: string, 1: string}>  $bans  [chain, ip] */
    public function deleteBans(array $bans): BulkResult
    {
        return $this->runner->run($bans, fn (array $ban) => [$ban[1], 'v-delete-firewall-ban', [$ban[1], $ban[0]]]);
    }

    /**
     * A ban list item "CHAIN:IP", split at the first colon so IPv6
     * addresses keep theirs (spec §12.3).
     *
     * @return array{0: string, 1: string}|null [chain, ip]
     */
    public static function parseBanItem(string $item): ?array
    {
        $parts = explode(':', $item, 2);
        if (count($parts) !== 2 || ! in_array($parts[0], self::CHAINS, true)
            || preg_match('/^[0-9A-Fa-f:.\/]{2,64}$/', $parts[1]) !== 1) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
