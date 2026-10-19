<?php

namespace App\Services\Backups;

/**
 * One recorded run of v-backup-user or v-backup-user-restic as
 * `v-list-user-backup-runs USER json` returns it (Fleet PD2,
 * docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §5).
 */
final class BackupRun
{
    public function __construct(
        public readonly string $user,
        public readonly string $id,
        public readonly string $engine,
        public readonly string $trigger,
        public readonly string $task,
        public readonly string $status,
        public readonly string $start,
        public readonly string $end,
        public readonly string $exit,
        public readonly string $error,
        public readonly string $archive,
        public readonly string $size,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $id, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            user: $user,
            id: $id,
            engine: $value('ENGINE'),
            trigger: $value('TRIGGER'),
            task: $value('TASK'),
            status: $value('STATUS'),
            start: $value('START'),
            end: $value('END'),
            exit: $value('EXIT'),
            error: $value('ERROR'),
            archive: $value('ARCHIVE'),
            size: $value('SIZE'),
        );
    }

    /** Newest first: the id starts with the start time in seconds. */
    public function sortKey(): int
    {
        return -(int) $this->id;
    }
}
