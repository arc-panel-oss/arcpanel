<?php

namespace App\Services\Backups;

/**
 * One backup job as `v-list-backup-job(s) json` returns it (E48 spec §5.1):
 * engine, scope, schedule, targets with their retention, state and the
 * state of the last run.
 */
final class BackupJob
{
    /**
     * @param  list<array{destination: string, keepLast: int, daily: int, weekly: int, monthly: int, yearly: int}>  $targets
     * @param  list<string>  $excluded
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $engine,
        public readonly string $scope,
        public readonly array $excluded,
        public readonly BackupSchedule $schedule,
        public readonly array $targets,
        public readonly bool $enabled,
        public readonly string $nextRun,
        public readonly string $lastStatus,
        public readonly string $lastEnd,
        public readonly bool $running,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $id, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            id: $id,
            name: $value('NAME'),
            engine: $value('ENGINE'),
            scope: $value('SCOPE') ?: 'all',
            excluded: array_values(array_filter(explode(',', $value('EXCLUDE_USERS')), fn ($v) => $v !== '')),
            schedule: BackupSchedule::parse($value('SCHEDULE')),
            targets: self::parseTargets($value('TARGETS')),
            enabled: $value('STATE') !== 'paused',
            nextRun: $value('NEXT_RUN'),
            lastStatus: $value('LAST_STATUS'),
            lastEnd: $value('LAST_END'),
            running: $value('RUNNING') === 'yes',
        );
    }

    /**
     * DEST:KEEP_LAST:DAILY:WEEKLY:MONTHLY:YEARLY items.
     *
     * @return list<array{destination: string, keepLast: int, daily: int, weekly: int, monthly: int, yearly: int}>
     */
    public static function parseTargets(string $targets): array
    {
        $list = [];
        foreach (array_filter(explode(',', $targets)) as $item) {
            $parts = explode(':', $item);
            $number = fn (int $i): int => isset($parts[$i]) && is_numeric($parts[$i]) ? (int) $parts[$i] : -1;
            $list[] = ['destination' => $parts[0], 'keepLast' => $number(1), 'daily' => $number(2), 'weekly' => $number(3), 'monthly' => $number(4), 'yearly' => $number(5)];
        }

        return $list;
    }

    /** @param  list<array{destination: string, keepLast: int, daily: int, weekly: int, monthly: int, yearly: int}>  $targets */
    public static function targetsArg(array $targets): string
    {
        return implode(',', array_map(fn (array $t) => implode(':', [$t['destination'], $t['keepLast'], $t['daily'], $t['weekly'], $t['monthly'], $t['yearly']]), $targets));
    }

    /** @return list<string> */
    public function destinations(): array
    {
        return array_map(fn (array $t) => $t['destination'], $this->targets);
    }

    /** Scope in words: all users, packages or users. */
    public function scopeLabel(): string
    {
        if (str_starts_with($this->scope, 'packages:')) {
            return sprintf(__('Packages: %s'), str_replace(',', ', ', substr($this->scope, 9)));
        }
        if (str_starts_with($this->scope, 'users:')) {
            return sprintf(__('Users: %s'), str_replace(',', ', ', substr($this->scope, 6)));
        }

        return __('All users');
    }

    /** Whether the job backs up USER of PACKAGE (the scope, less the excluded users). */
    public function covers(string $user, string $package): bool
    {
        if (in_array($user, $this->excluded, true)) {
            return false;
        }

        return match (true) {
            str_starts_with($this->scope, 'packages:') => in_array($package, explode(',', substr($this->scope, 9)), true),
            str_starts_with($this->scope, 'users:') => in_array($user, explode(',', substr($this->scope, 6)), true),
            default => true,
        };
    }
}
