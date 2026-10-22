<?php

namespace App\Services\Backups;

use DateTimeImmutable;
use Throwable;

/**
 * One restic snapshot of `v-list-user-backups-restic USER json` (the output
 * of `restic --json snapshots`).
 */
final class ResticSnapshot
{
    /** @param  list<string>  $paths */
    public function __construct(
        public readonly string $id,
        public readonly string $shortId,
        public readonly ?DateTimeImmutable $time,
        public readonly string $hostname,
        public readonly array $paths,
        public readonly string $destination = '',
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): ?self
    {
        $id = (string) ($row['id'] ?? '');
        $short = (string) ($row['short_id'] ?? substr($id, 0, 8));
        if (preg_match('/^[0-9a-f]{8,64}$/', $short) !== 1) {
            return null;
        }
        try {
            $time = isset($row['time']) ? new DateTimeImmutable((string) $row['time']) : null;
        } catch (Throwable) {
            $time = null;
        }

        return new self(
            id: $id,
            shortId: $short,
            time: $time,
            hostname: (string) ($row['hostname'] ?? ''),
            paths: array_values(array_map('strval', array_filter((array) ($row['paths'] ?? []), 'is_scalar'))),
            // The destination of the repository (E48); one list merges them
            destination: is_scalar($row['destination'] ?? null) ? (string) $row['destination'] : '',
        );
    }

    public function label(): string
    {
        return $this->time?->format('Y-m-d H:i') ?? $this->shortId;
    }
}
