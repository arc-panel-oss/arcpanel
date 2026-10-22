<?php

namespace App\Services\Backups;

/**
 * One tar backup as `v-list-user-backup(s) USER [BACKUP] json` returns it.
 */
final class BackupArchive
{
    /** Restore slots of v-schedule-user-restore, in argument order. */
    public const TYPES = ['web', 'dns', 'mail', 'db', 'cron', 'udir'];

    /**
     * @param  array<string, list<string>>  $items  type => objects (web domains, databases …)
     * @param  list<string>  $destinations  backup destinations that hold the archive (E48)
     */
    public function __construct(
        public readonly string $user,
        public readonly string $name,
        public readonly string $type,
        public readonly int $sizeMb,
        public readonly array $items,
        public readonly int $runtime,
        public readonly string $date,
        public readonly string $time,
        public readonly string $job = '',
        public readonly array $destinations = [],
        public readonly bool $encrypted = false,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $name, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));
        $items = [];
        foreach (self::TYPES as $type) {
            $items[$type] = array_values(array_filter(array_map('trim', explode(',', $value(strtoupper($type)))), fn ($v) => $v !== ''));
        }

        return new self(
            user: $user,
            name: $name,
            type: $value('TYPE'),
            sizeMb: (int) $value('SIZE'),
            items: $items,
            runtime: (int) $value('RUNTIME'),
            date: $value('DATE'),
            time: $value('TIME'),
            // E48: the job that made it, the destinations that hold it (TYPE before E48)
            job: $value('JOB'),
            destinations: array_values(array_filter(explode(',', $value('DESTINATIONS') !== '' ? $value('DESTINATIONS') : $value('TYPE')), fn ($v) => $v !== '')),
            // ENCRYPTED lists the destinations that hold it encrypted
            encrypted: $value('ENCRYPTED') !== '',
        );
    }

    /** Content type of the download, from the archive's extension. */
    public function contentType(): string
    {
        return match (true) {
            str_ends_with($this->name, '.tar.gz'), str_ends_with($this->name, '.tgz') => 'application/gzip',
            str_ends_with($this->name, '.tar.zst') => 'application/zstd',
            default => 'application/x-tar',
        };
    }
}
