<?php

namespace App\Services\Backups;

use App\Arc\ArcCommand;
use App\Arc\Options;

/**
 * Runs of the backup jobs (E48 spec §5.3, §8.2 Activity), newest first.
 */
final class BackupRunService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /** @return list<BackupJobRun> of one job, or of every job when ID is empty */
    public function runs(string $id = ''): array
    {
        $runs = [];
        foreach ($this->arc->json('v-list-backup-job-runs', [$id], Options::allowFailure()) as $row) {
            if (is_array($row)) {
                $runs[] = BackupJobRun::fromArray($row);
            }
        }

        return $runs;
    }

    /**
     * The results per user of a job run: the users' runs of job ID that
     * started between the start and the end of RUN (now while it runs).
     *
     * @return list<BackupRun> newest first
     */
    public function userResults(string $id, BackupJobRun $run): array
    {
        $from = strtotime($run->start) ?: 0;
        $to = $run->end !== '' ? (strtotime($run->end) ?: PHP_INT_MAX) : PHP_INT_MAX;
        $results = array_filter($this->userRuns(), function (BackupRun $row) use ($id, $from, $to) {
            $start = strtotime($row->start) ?: 0;

            return $row->job === $id && $start >= $from && $start <= $to;
        });

        return array_values($results);
    }

    /** @return list<BackupRun> the backup runs of every user, newest first */
    public function userRuns(): array
    {
        $runs = [];
        $objects = $this->arc->json('v-list-sys-backup-runs', ['', ''], Options::allowFailure())['OBJECTS'] ?? [];
        foreach (is_array($objects) ? $objects : [] as $user => $rows) {
            foreach (is_array($rows) ? $rows : [] as $id => $row) {
                if (is_array($row)) {
                    $runs[] = BackupRun::fromArray((string) $user, (string) $id, $row);
                }
            }
        }
        usort($runs, fn (BackupRun $a, BackupRun $b) => $a->sortKey() <=> $b->sortKey());

        return $runs;
    }

    /** One run of job ID, or null. */
    public function find(string $id, string $run): ?BackupJobRun
    {
        foreach ($this->runs($id) as $row) {
            if ($row->run === $run) {
                return $row;
            }
        }

        return null;
    }
}
