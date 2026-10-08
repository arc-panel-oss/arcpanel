<?php

namespace App\Services\Ports;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;

/**
 * The port registry (E12 spec §7.1): reads the registry and plans the
 * commands of every port action. PortPolicy decides who may do what; the
 * commands check ranges, ownership, pools and PORTS again on the server.
 */
final class PortService
{
    /** ROUTE pattern of a port or a range */
    public const RANGE = '\d{1,5}(-\d{1,5})?';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly TenancyDirectory $tenancy,
    ) {}

    public function account(string $user): PortAccount
    {
        return PortAccount::from($user, $this->arc->json('v-list-user-ports', [$user]));
    }

    /**
     * @param  list<string>  $users
     * @return array<string, PortAccount>
     */
    public function accounts(array $users): array
    {
        $accounts = [];
        foreach ($users as $user) {
            $accounts[$user] = $this->account($user);
        }

        return $accounts;
    }

    public function registry(): PortRegistry
    {
        return PortRegistry::from($this->arc->json('v-list-sys-ports', []));
    }

    /** @return array{0: int, 1: int} start and end of PORT or START-END */
    public static function bounds(string $range): array
    {
        $parts = explode('-', $range, 2);

        return [(int) $parts[0], (int) ($parts[1] ?? $parts[0])];
    }

    /** Admin: a range, or COUNT ports as one block (from the reseller's pool for a customer). */
    public function planAllocate(string $user, ?string $range, ?int $count, bool $publicOk, string $comment): Step
    {
        return $range !== null
            ? new Step(sprintf(__('Allocate ports %s to %s'), $range, $user), 'v-add-user-port', [$user, $range, $this->yes($publicOk), $comment])
            : new Step(sprintf(__('Allocate %d ports to %s'), (int) $count, $user), 'v-add-user-port-pool', [$user, (string) $count, $this->yes($publicOk)]);
    }

    public function planReserve(string $user, ?int $port, string $proto, string $comment): Step
    {
        return new Step(__('Reserve a port'), 'v-allocate-user-port', [$user, 'manual', $proto, $port === null ? '' : (string) $port, $comment]);
    }

    public function planRelease(string $user, int $port): Step
    {
        return new Step(sprintf(__('Release port %d'), $port), 'v-release-user-port', [$user, (string) $port]);
    }

    public function planPublic(string $user, int $port, bool $public): Step
    {
        return new Step($public ? sprintf(__('Open port %d'), $port) : sprintf(__('Close port %d'), $port),
            'v-change-user-port-public', [$user, (string) $port, $this->yes($public)]);
    }

    public function planPublicOk(string $user, string $range, bool $publicOk): Step
    {
        return new Step(__('Change who may open these ports'), 'v-change-user-port-public-ok', [$user, $range, $this->yes($publicOk)]);
    }

    /** Move free ports: a range, or +COUNT lowest free ports. */
    public function planMove(string $from, string $rangeOrCount, string $to): Step
    {
        return new Step(sprintf(__('Move ports %s from %s to %s'), $rangeOrCount, $from, $to), 'v-change-user-port-owner', [$from, $rangeOrCount, $to]);
    }

    public function planDelete(string $user, string $range, bool $force): Step
    {
        return new Step(sprintf(__('Delete ports %s'), $range), 'v-delete-user-port', array_values(array_filter([$user, $range, $force ? 'force' : ''])));
    }

    public function planRange(int $start, int $end): Step
    {
        return new Step(__('Change the port range'), 'v-change-sys-port-range', [(string) $start, (string) $end]);
    }

    public function planAddReserved(string $range, string $comment): Step
    {
        return new Step(sprintf(__('Reserve ports %s'), $range), 'v-add-sys-port-reserved', [$range, $comment]);
    }

    public function planDeleteReserved(string $range): Step
    {
        return new Step(sprintf(__('Delete reservation %s'), $range), 'v-delete-sys-port-reserved', [$range]);
    }

    public function planRepair(): Step
    {
        return new Step(__('Repair the registry'), 'v-check-sys-ports', ['repair']);
    }

    /** @return list<Step> a listening port registered to its account */
    public function planRegister(string $user, int $port, string $proto): array
    {
        return [
            new Step(sprintf(__('Allocate ports %s to %s'), (string) $port, $user), 'v-add-user-port', [$user, (string) $port]),
            new Step(__('Reserve a port'), 'v-allocate-user-port', [$user, 'manual', $proto, (string) $port]),
        ];
    }

    /** @return list<Step> an existing firewall rule linked to an assigned port */
    public function planAdopt(string $user, int $port, string $rule): array
    {
        return [
            $this->planPublicOk($user, (string) $port, true),
            new Step(sprintf(__('Open port %d'), $port), 'v-change-user-port-public', [$user, (string) $port, 'yes', $rule]),
        ];
    }

    public function planCloseRule(string $rule): Step
    {
        return new Step(sprintf(__('Delete firewall rule %s'), $rule), 'v-delete-firewall-rule', [$rule]);
    }

    /** "Request more ports": a notification to the owner (the reseller, or the administrator). */
    public function planRequest(string $user, int $count, string $reason): Step
    {
        $owner = $this->tenancy->ownerOf($user);
        $notice = sprintf(__('%s asks for %d more ports.'), $user, $count).($reason !== '' ? ' '.$reason : '');

        return new Step(__('Request more ports'), 'v-add-user-notification', [$owner, __('Port request'), $notice]);
    }

    /**
     * Run the steps in order; the first failure stops the run.
     *
     * @param  Step|list<Step>  $steps
     */
    public function apply(Step|array $steps): ApplyResult
    {
        $applied = [];
        $stdout = [];
        foreach (is_array($steps) ? $steps : [$steps] as $step) {
            try {
                $stdout[$step->command] = $this->arc->run($step->command, $step->args)->stdout;
            } catch (ArcCommandException $e) {
                return new ApplyResult($applied, $step, self::message($e), (int) $e->getCode(), $stdout, $e->invalidArgument);
            }
            $applied[] = $step;
        }

        return new ApplyResult($applied, stdoutByCommand: $stdout);
    }

    /** The command's message without the "Error: " prefix of func/main.sh. */
    public static function message(ArcCommandException $e): string
    {
        return (string) preg_replace('/^Error:\s*/', '', $e->userMessage());
    }

    private function yes(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
