<?php

namespace App\Services\Tasks;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\TaskId;
use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Services\Jobs\JobIndex;
use App\Services\Jobs\JobIndexUnavailable;
use App\Services\Jobs\JobStore;
use App\Services\Jobs\JobStoreException;
use Illuminate\Database\Query\Builder;

/**
 * The tasks of API v2 (E34 batch 3 spec §5), since E15 the jobs of the Job
 * Center (docs/features/specs/2026-11-05-001-e15-job-center-design.md §7):
 * one task is read from its job file with `v-list-job`, lists come from the
 * job index (jobs_log).
 */
final class TaskService
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly JobStore $store,
        private readonly JobIndex $index,
    ) {}

    /**
     * The task of ID with the last LINES lines of its log. 404 when the job
     * does not exist (any more) or does not belong to the id's source.
     */
    public function get(TaskId $id, int $lines): TaskView
    {
        $view = $this->find($id, $lines);
        if ($view === null) {
            throw new ApiException(ErrorCode::NotExist, 'Not found.', 404);
        }

        return $view;
    }

    /** The task of ID, or null when there is none (callers answer 404). */
    public function find(TaskId $id, int $lines): ?TaskView
    {
        try {
            $job = $this->store->findWithTail($id->jobId(), $lines);
        } catch (JobStoreException) {
            throw new ApiException(ErrorCode::Server, 'The task could not be read.', 500);
        }
        if ($job === null || ! $id->matches($job)) {
            return null;
        }
        // A finished engine install changes DOCKER_SYSTEM, as on the page (E4 spec §4.4)
        if (($job['TYPE'] ?? '') === 'docker.engine' && ($job['STATUS'] ?? '') === 'succeeded') {
            $this->config->forget();
        }
        try {
            return JobMapper::fromRecord($job, $lines);
        } catch (\InvalidArgumentException) {
            throw new ApiException(ErrorCode::Server, 'The task could not be read.', 500);
        }
    }

    /**
     * The jobs of a user, newest first.
     *
     * @return list<TaskView>
     */
    public function listForUser(string $user): array
    {
        return $this->rows(fn (Builder $q) => $q->where('user', $user));
    }

    /**
     * The jobs of the server, newest first.
     *
     * @return list<TaskView>
     */
    public function listSystem(): array
    {
        return $this->rows(fn (Builder $q) => $q->where('user', 'system'));
    }

    /**
     * The jobs a principal sees (USERS null: every job, else the jobs of
     * those users and, when SYSTEM, the server's), newest first, filtered.
     *
     * @param  list<string>|null  $users
     * @param  array{status?: string|list<string>, type?: string, user?: string, since?: string}  $filters
     * @return array{items: list<TaskView>, total: int}
     */
    public function listVisible(?array $users, bool $system, array $filters, ?int $limit, int $offset): array
    {
        $this->refresh();
        $query = $this->index->query();
        if ($users !== null) {
            $query->where(function (Builder $q) use ($users, $system) {
                $q->whereIn('user', $users === [] ? [''] : $users);
                if ($system) {
                    $q->orWhere('user', 'system');
                }
            });
        }
        $status = $filters['status'] ?? '';
        if (is_array($status)) {
            $query->whereIn('status', $status === [] ? [''] : $status);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        if (($filters['type'] ?? '') !== '') {
            $type = (string) $filters['type'];
            str_ends_with($type, '.*')
                ? $query->where('type', 'like', substr($type, 0, -1).'%')
                : $query->where('type', $type);
        }
        if (($filters['user'] ?? '') !== '') {
            $query->where('user', $filters['user']);
        }
        if (($filters['since'] ?? '') !== '') {
            $query->where('queued_at', '>=', $filters['since']);
        }
        $total = (clone $query)->count();
        $query->orderByDesc('id');
        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }

        return ['items' => array_map(fn ($row) => JobMapper::fromRow($row), $query->get()->all()), 'total' => $total];
    }

    /** Sync the job index unless a list did it a moment ago. */
    public function refresh(): void
    {
        try {
            $this->index->refresh();
        } catch (JobStoreException) {
            throw new ApiException(ErrorCode::Server, 'The tasks could not be read.', 500);
        } catch (JobIndexUnavailable) {
            throw new ApiException(ErrorCode::Server, 'The job index of the panel cannot be written.', 500);
        }
    }

    /**
     * @param  callable(Builder): mixed  $scope
     * @return list<TaskView>
     */
    private function rows(callable $scope): array
    {
        $this->refresh();
        try {
            $query = $this->index->query();
        } catch (JobIndexUnavailable) {
            throw new ApiException(ErrorCode::Server, 'The job index of the panel cannot be written.', 500);
        }
        $scope($query);

        return array_map(fn ($row) => JobMapper::fromRow($row), $query->orderByDesc('id')->get()->all());
    }

    public function userExists(string $user): bool
    {
        return $this->arc->run('v-list-user', [$user, 'json'], Options::allowFailure())->ok();
    }

    /**
     * The newest queued or running task of the backup queue that blocks a
     * new request of the user (E34 batch 3 §6.1). `is_backup_scheduled
     * backup|restore` refuses while any line of the user in backup.pipe
     * holds that word, so a task of the matching group is preferred and any
     * other task of the backup queue is the fallback.
     *
     * @param  'backup'|'restore'  $check
     */
    public function blockingBackupTask(string $user, string $check): ?TaskView
    {
        $groups = [
            'backup' => ['backup', 'backup-download', 'snapshot'],
            'restore' => ['restore', 'snapshot-restore', 'file-restore'],
        ];
        $preferred = $groups[$check];
        $any = array_merge(...array_values($groups));
        $first = null;
        $fallback = null;
        $records = $this->queueTasks($user);
        krsort($records, SORT_STRING);
        foreach ($records as $recordId => $row) {
            if (! is_array($row) || ! in_array((string) ($row['STATUS'] ?? ''), ['queued', 'running'], true)) {
                continue;
            }
            $kind = (string) ($row['KIND'] ?? '');
            if ($first === null && in_array($kind, $preferred, true)) {
                $first = [(string) $recordId, $row];
            } elseif ($fallback === null && in_array($kind, $any, true)) {
                $fallback = [(string) $recordId, $row];
            }
        }
        $found = $first ?? $fallback;
        if ($found === null) {
            return null;
        }

        return TaskMapper::fromSource(new TaskId(TaskId::SOURCE_QUEUE, $found[0], $user), $found[1], 1);
    }

    public function dockerAppExists(string $user, string $app): bool
    {
        foreach ($this->dockerApps($user) as $row) {
            if (($row['NAME'] ?? '') === $app) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $command, array $args): array
    {
        try {
            return $this->arc->json($command, $args);
        } catch (ArcCommandException) {
            throw new ApiException(ErrorCode::Server, 'The task could not be read.', 500);
        }
    }

    /** @return list<array<string, mixed>> */
    private function dockerApps(string $user): array
    {
        $data = $this->readJson('v-list-docker-apps', [$user]);
        $apps = $data['APPS'] ?? [];

        return is_array($apps) ? array_values($apps) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function queueTasks(string $user): array
    {
        $data = $this->readJson('v-list-user-tasks', [$user]);

        return is_array($data) ? $data : [];
    }
}
