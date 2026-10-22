<?php

namespace App\Services\Backups;

/**
 * One run of a backup job as `v-list-backup-job-runs [ID] json` returns it
 * (E48 spec §5.3): state success, partial, failed or running, and the
 * counts of users.
 */
final class BackupJobRun
{
    public function __construct(
        public readonly string $job,
        public readonly string $jobName,
        public readonly string $run,
        public readonly string $trigger,
        public readonly string $status,
        public readonly string $start,
        public readonly string $end,
        public readonly int $users,
        public readonly int $done,
        public readonly int $failed,
        public readonly int $skipped,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            job: $value('JOB'),
            jobName: $value('JOB_NAME'),
            run: $value('RUN'),
            trigger: $value('TRIGGER'),
            status: $value('STATUS'),
            start: $value('START'),
            end: $value('END'),
            users: (int) $value('USERS'),
            done: (int) $value('DONE'),
            failed: (int) $value('FAILED'),
            skipped: (int) $value('SKIPPED'),
        );
    }

    /** Badge tone of the state. */
    public function tone(): string
    {
        return self::statusTone($this->status);
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'success', 'succeeded', 'ok' => 'ok',
            'partial', 'unchecked' => 'warn',
            'failed', 'failing' => 'danger',
            'running' => 'info',
            default => 'neutral',
        };
    }
}
