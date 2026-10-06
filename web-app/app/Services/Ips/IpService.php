<?php

namespace App\Services\Ips;

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
 * IP addresses of the server through the v-* commands, with the arguments
 * and order of the legacy pages (web/list|add|edit|bulk|delete/ip).
 */
final class IpService
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
        private readonly PlanRunner $plans,
    ) {}

    /** @return list<SystemIp> sorted by address */
    public function all(): array
    {
        $ips = [];
        foreach ($this->arc->json('v-list-sys-ips') as $ip => $row) {
            if (is_array($row)) {
                $ips[] = SystemIp::fromArray((string) $ip, $row);
            }
        }
        usort($ips, fn (SystemIp $a, SystemIp $b) => strnatcmp($a->ip, $b->ip));

        return $ips;
    }

    /** One address; 404 when the server has no such address. */
    public function find(string $ip): SystemIp
    {
        return SystemIp::fromArray($ip, $this->lookup->one('v-list-sys-ip', [$ip], $ip));
    }

    /** @return list<string> network interfaces (`v-list-sys-interfaces json`) */
    public function interfaces(): array
    {
        return array_values(array_map('strval', array_filter($this->arc->json('v-list-sys-interfaces'), 'is_string')));
    }

    /**
     * `v-add-sys-ip IP NETMASK INTERFACE OWNER STATUS NAME NAT`
     * (web/add/ip): the status is shared when the box is checked.
     */
    public function create(string $ip, string $netmask, string $interface, string $owner, bool $shared, string $name, string $nat): CommandResult
    {
        return $this->arc->run('v-add-sys-ip', [$ip, $netmask, $interface, $owner, $shared ? 'shared' : 'dedicated', $name, $nat], Options::allowFailure());
    }

    /**
     * The steps of web/edit/ip, in its order and only for changed values.
     *
     * @return list<Step>
     */
    public function planUpdate(SystemIp $current, bool $shared, string $owner, string $name, string $nat): array
    {
        $steps = [];
        if ($current->shared() !== $shared) {
            $steps[] = new Step(__('Change the status'), 'v-change-sys-ip-status', [$current->ip, $shared ? 'shared' : 'dedicated']);
        }
        if ($owner !== '' && $owner !== $current->owner) {
            $steps[] = new Step(__('Change the owner'), 'v-change-sys-ip-owner', [$current->ip, $owner]);
        }
        if ($name !== $current->name) {
            $steps[] = new Step(__('Change the assigned domain'), 'v-change-sys-ip-name', [$current->ip, $name]);
        }
        if ($nat !== $current->nat) {
            $steps[] = new Step(__('Change the NAT address'), 'v-change-sys-ip-nat', [$current->ip, $nat]);
        }

        return $steps;
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        return $this->plans->apply($steps);
    }

    /** @param  list<string>  $ips */
    public function delete(array $ips): BulkResult
    {
        return $this->runner->run($ips, fn (string $ip) => [$ip, 'v-delete-sys-ip', [$ip]]);
    }

    /** `v-update-sys-ip`: read the addresses of the interfaces again. */
    public function refresh(): CommandResult
    {
        return $this->arc->run('v-update-sys-ip', [], Options::allowFailure());
    }
}
