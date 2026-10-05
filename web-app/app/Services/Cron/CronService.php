<?php

namespace App\Services\Cron;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cron jobs through the v-* commands, with the arguments and order of the
 * legacy pages (web/list/cron, web/add/cron, web/edit/cron, web/bulk/cron,
 * web/{suspend,unsuspend,delete}/cron, web/{add,delete}/cron/reports).
 */
final class CronService
{
    public const BULK_COMMANDS = [
        'suspend' => 'v-suspend-cron-job',
        'unsuspend' => 'v-unsuspend-cron-job',
        'delete' => 'v-delete-cron-job',
    ];

    /** The schedule fields in command order. */
    public const FIELDS = ['min', 'hour', 'day', 'month', 'wday'];

    /** @var array<string, array<string, CronJobRecord>> per-request cache */
    private array $lists = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
        private readonly PlanRunner $plans,
    ) {}

    /** @return array<string, CronJobRecord> job id => record */
    public function forUser(string $user): array
    {
        if (! isset($this->lists[$user])) {
            $records = [];
            foreach ($this->arc->json('v-list-cron-jobs', [$user]) as $job => $row) {
                if (is_array($row)) {
                    $records[(string) $job] = CronJobRecord::fromArray($user, (string) $job, $row);
                }
            }
            $this->lists[$user] = $records;
        }

        return $this->lists[$user];
    }

    /**
     * @param  list<string>  $users
     * @return list<CronJobRecord> sorted by owner, then job id
     */
    public function forUsers(array $users): array
    {
        $records = [];
        foreach ($users as $user) {
            array_push($records, ...array_values($this->forUser($user)));
        }
        usort($records, fn (CronJobRecord $a, CronJobRecord $b) => [$a->user, (int) $a->job] <=> [$b->user, (int) $b->job]);

        return $records;
    }

    /** One cron job; 404 when the user has no such job. */
    public function find(string $user, string $job): CronJobRecord
    {
        return CronJobRecord::fromArray($user, $job, $this->lookup->one('v-list-cron-job', [$user, $job], $job));
    }

    /** Whether the user receives the output of its cron jobs by e-mail (CRON_REPORTS). */
    public function reportsEnabled(string $user): bool
    {
        $record = $this->arc->json('v-list-user', [$user], Options::allowFailure())[$user] ?? [];

        return is_array($record) && ($record['CRON_REPORTS'] ?? '') === 'yes';
    }

    /** The server's time in its own time zone (`v-get-sys-timezone`), as the add and edit pages show it. */
    public function systemTime(): DateTimeImmutable
    {
        $zone = trim((string) ($this->arc->run('v-get-sys-timezone', [], Options::allowFailure())->lines()[0] ?? ''));
        try {
            $timezone = new DateTimeZone($zone !== '' ? $zone : date_default_timezone_get());
        } catch (\Exception) {
            $timezone = new DateTimeZone(date_default_timezone_get());
        }

        return new DateTimeImmutable('now', $timezone);
    }

    /**
     * `v-add-cron-job USER MIN HOUR DAY MONTH WDAY COMMAND`; the command
     * checks the schedule format and restarts cron itself.
     *
     * @param  array<string, string>  $schedule  min, hour, day, month, wday
     */
    public function planCreate(string $owner, array $schedule, string $command): Step
    {
        return new Step(__('Add the cron job'), 'v-add-cron-job', [$owner, ...self::ordered($schedule), $command]);
    }

    /**
     * `v-change-cron-job USER JOB MIN HOUR DAY MONTH WDAY COMMAND` (web/edit/cron).
     *
     * @param  array<string, string>  $schedule
     */
    public function planUpdate(CronJobRecord $record, array $schedule, string $command): Step
    {
        return new Step(__('Change the cron job'), 'v-change-cron-job', [$record->user, $record->job, ...self::ordered($schedule), $command]);
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $this->lists = [];

        return $this->plans->apply($steps);
    }

    /**
     * web/bulk/cron: each job with RESTART=no, then one `v-restart-cron`.
     *
     * @param  list<array{0: string, 1: string}>  $items  [owner, job]
     */
    public function bulk(string $action, array $items): BulkResult
    {
        $command = self::command($action);
        $this->lists = [];

        return $this->runner->run($items, fn (array $item) => [$item[0].'/'.$item[1], $command, [$item[0], $item[1], 'no']], ['v-restart-cron']);
    }

    /** web/{suspend,unsuspend,delete}/cron: one job, the command restarts cron itself. */
    public function single(string $action, string $user, string $job): BulkResult
    {
        $command = self::command($action);
        $this->lists = [];

        return $this->runner->run([[$user, $job]], fn (array $item) => [$item[0].'/'.$item[1], $command, [$item[0], $item[1]]]);
    }

    /** `v-add-cron-reports USER` or `v-delete-cron-reports USER`. */
    public function setReports(string $user, bool $enabled): CommandResult
    {
        return $this->arc->run($enabled ? 'v-add-cron-reports' : 'v-delete-cron-reports', [$user], Options::allowFailure());
    }

    /**
     * @param  array<string, string>  $schedule
     * @return list<string>
     */
    private static function ordered(array $schedule): array
    {
        return array_map(fn (string $field) => (string) ($schedule[$field] ?? ''), self::FIELDS);
    }

    private static function command(string $action): string
    {
        return self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
    }
}
