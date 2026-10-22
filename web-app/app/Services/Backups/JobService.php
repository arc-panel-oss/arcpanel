<?php

namespace App\Services\Backups;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backup jobs through their v-* commands (E48 spec §5): list, add, change,
 * delete, pause, start, and the preview of a schedule computed by the same
 * code as the dispatcher.
 */
final class JobService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /** @return array<string, BackupJob> by ID, oldest first */
    public function all(): array
    {
        $list = [];
        foreach ($this->arc->json('v-list-backup-jobs', [], Options::allowFailure()) as $id => $row) {
            if (is_array($row)) {
                $list[(string) $id] = BackupJob::fromArray((string) $id, $row);
            }
        }
        ksort($list);

        return $list;
    }

    /** One job; 404 when there is none of that ID. */
    public function find(string $id): BackupJob
    {
        $job = $this->all()[$id] ?? null;
        if ($job === null) {
            throw new NotFoundHttpException;
        }

        return $job;
    }

    /**
     * The enabled jobs that back up USER of PACKAGE (E48 spec §8.1).
     *
     * @return list<BackupJob>
     */
    public function covering(string $user, string $package): array
    {
        return array_values(array_filter($this->all(), fn (BackupJob $job) => $job->enabled && $job->covers($user, $package)));
    }

    /** @param  array{name: string, engine: string, scope: string, schedule: string, targets: string, exclude: string}  $values */
    public function add(array $values, bool $enabled = true): CommandResult
    {
        return $this->arc->run('v-add-backup-job', [$values['name'], $values['engine'], $values['scope'], $values['schedule'],
            $values['targets'], $values['exclude'], $enabled ? 'enabled' : 'paused'], Options::allowFailure());
    }

    /** @param  array{name: string, engine: string, scope: string, schedule: string, targets: string, exclude: string}  $values */
    public function change(string $id, array $values): CommandResult
    {
        return $this->arc->run('v-change-backup-job', [$id, $values['name'], $values['engine'], $values['scope'], $values['schedule'],
            $values['targets'], $values['exclude']], Options::allowFailure());
    }

    public function delete(string $id): CommandResult
    {
        return $this->arc->run('v-delete-backup-job', [$id], Options::allowFailure());
    }

    public function setEnabled(string $id, bool $enabled): CommandResult
    {
        return $this->arc->run($enabled ? 'v-unsuspend-backup-job' : 'v-suspend-backup-job', [$id], Options::allowFailure());
    }

    /** Start a whole run in the background. */
    public function start(string $id): CommandResult
    {
        return $this->arc->run('v-start-backup-job', [$id], Options::allowFailure());
    }

    /** @return list<string> the packages a scope may name */
    public function packageNames(): array
    {
        $names = array_map('strval', array_keys($this->arc->json('v-list-user-packages', [], Options::allowFailure())));
        sort($names);

        return $names;
    }

    /**
     * The next times of a schedule in the server's time zone, or null when
     * it is not valid.
     *
     * @return list<string>|null
     */
    public function preview(string $schedule, int $count = 5): ?array
    {
        $result = $this->arc->run('v-list-backup-schedule', [$schedule, (string) $count, 'json'], Options::allowFailure());
        if (! $result->ok()) {
            return null;
        }
        $rows = json_decode($result->stdout, true);

        return is_array($rows) ? array_values(array_map(fn ($row) => (string) ($row['TIME'] ?? ''), array_filter($rows, 'is_array'))) : null;
    }
}
