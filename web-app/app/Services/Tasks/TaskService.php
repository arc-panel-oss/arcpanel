<?php

namespace App\Services\Tasks;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\TaskId;
use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;

/**
 * Reads the six task sources of the panel (E34 batch 3 spec §5).
 */
final class TaskService
{
    public function __construct(private readonly ArcCommand $arc) {}

    public function get(TaskId $id, int $lines): TaskView
    {
        return match ($id->source) {
            TaskId::SOURCE_SYS => $this->getSys($id, $lines),
            TaskId::SOURCE_DOCKER_ENGINE => $this->getDockerEngine($id, $lines),
            TaskId::SOURCE_NODE => $this->getNode($id, $lines),
            TaskId::SOURCE_DOCKER => $this->getDockerApp($id, $lines),
            TaskId::SOURCE_INSTALL => $this->getInstall($id, $lines),
            TaskId::SOURCE_QUEUE => $this->getQueue($id, $lines),
        };
    }

    /** @return list<TaskView> */
    public function listForUser(string $user, int $lines): array
    {
        $tasks = [];
        $node = $this->readJson('v-list-user-node-task', [$user, (string) $lines]);
        if ((string) ($node['ID'] ?? '') !== '') {
            $tasks[] = $this->mapRow(new TaskId(TaskId::SOURCE_NODE, (string) $node['ID'], $user), $node, $lines);
        }

        $install = $this->readJson('v-list-user-quick-install-task', [$user, (string) $lines]);
        if ((string) ($install['ID'] ?? '') !== '') {
            $tasks[] = $this->mapRow(new TaskId(TaskId::SOURCE_INSTALL, (string) $install['ID'], $user), $install, $lines);
        }

        foreach ($this->dockerApps($user) as $app) {
            $name = (string) ($app['NAME'] ?? '');
            if ($name === '') {
                continue;
            }
            $row = $this->readDockerTask('v-list-docker-app-task', [$user, $name], 1);
            if ((string) ($row['ID'] ?? '') !== '') {
                $tasks[] = $this->mapRow(new TaskId(TaskId::SOURCE_DOCKER, (string) $row['ID'], $user, $name), $row, $lines);
            }
        }

        foreach ($this->queueTasks($user) as $recordId => $row) {
            $tasks[] = $this->mapRow(new TaskId(TaskId::SOURCE_QUEUE, $recordId, $user), $row, 1);
        }

        usort($tasks, fn (TaskView $a, TaskView $b) => strcmp(TaskMapper::sortKey($b), TaskMapper::sortKey($a)));

        return $tasks;
    }

    /** @return list<TaskView> */
    public function listSystem(int $lines): array
    {
        $tasks = [];
        $sys = $this->readJson('v-list-sys-task', [(string) $lines]);
        if ((string) ($sys['ID'] ?? '') !== '') {
            $tasks[] = $this->mapRow(new TaskId(TaskId::SOURCE_SYS, (string) $sys['ID']), $sys, $lines);
        }
        $engine = $this->readDockerTask('v-list-sys-docker-task', [], $lines);
        if ((string) ($engine['ID'] ?? '') !== '') {
            $tasks[] = $this->mapRow(new TaskId(TaskId::SOURCE_DOCKER_ENGINE, (string) $engine['ID']), $engine, $lines);
        }

        return $tasks;
    }

    public function userExists(string $user): bool
    {
        return $this->arc->run('v-list-user', [$user, 'json'], Options::allowFailure())->ok();
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

    private function getSys(TaskId $id, int $lines): TaskView
    {
        $row = $this->readJson('v-list-sys-task', [(string) $lines]);
        $current = (string) ($row['ID'] ?? '');
        if ($current === '') {
            throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
        }
        if ($current !== $id->recordId) {
            throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
        }

        return $this->mapRow($id, $row, $lines);
    }

    private function getDockerEngine(TaskId $id, int $lines): TaskView
    {
        $row = $this->readDockerTask('v-list-sys-docker-task', [], $lines);
        $current = (string) ($row['ID'] ?? '');
        if ($current === '' || $current !== $id->recordId) {
            throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
        }

        return $this->mapRow($id, $row, $lines);
    }

    private function getNode(TaskId $id, int $lines): TaskView
    {
        $row = $this->readJson('v-list-user-node-task', [$id->user, (string) $lines]);
        $current = (string) ($row['ID'] ?? '');
        if ($current === '' || $current !== $id->recordId) {
            throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
        }

        return $this->mapRow($id, $row, $lines);
    }

    private function getDockerApp(TaskId $id, int $lines): TaskView
    {
        if (! $this->dockerAppExists($id->user, (string) $id->app)) {
            throw new ApiException(ErrorCode::NotExist, 'Not found.', 404);
        }
        $row = $this->readDockerTask('v-list-docker-app-task', [$id->user, (string) $id->app], $lines);
        $current = (string) ($row['ID'] ?? '');
        if ($current === '' || $current !== $id->recordId) {
            throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
        }

        return $this->mapRow($id, $row, $lines);
    }

    private function getInstall(TaskId $id, int $lines): TaskView
    {
        $row = $this->readJson('v-list-user-quick-install-task', [$id->user, (string) $lines]);
        $current = (string) ($row['ID'] ?? '');
        if ($current === '' || $current !== $id->recordId) {
            throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
        }

        return $this->mapRow($id, $row, $lines);
    }

    private function getQueue(TaskId $id, int $lines): TaskView
    {
        $result = $this->arc->run('v-list-user-tasks', [$id->user, $id->recordId, (string) $lines, 'json'], Options::allowFailure());
        if (! $result->ok()) {
            if ($result->exit() === ExitCode::NotExist) {
                throw new ApiException(ErrorCode::Gone, 'The task result is no longer kept.', 410);
            }
            throw new ApiException(ErrorCode::Server, 'The task could not be read.', 500);
        }
        $row = json_decode($result->stdout, true);
        if (! is_array($row)) {
            throw new ApiException(ErrorCode::Server, 'The task could not be read.', 500);
        }

        return $this->mapRow($id, $row, $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapRow(TaskId $id, array $row, int $lines): TaskView
    {
        try {
            return TaskMapper::fromSource($id, $row, $lines);
        } catch (\InvalidArgumentException) {
            throw new ApiException(ErrorCode::Server, 'The task could not be read.', 500);
        }
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

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function readDockerTask(string $command, array $args, int $lines): array
    {
        $offset = 0;
        $log = '';
        $row = [];
        for ($i = 0; $i < 512; $i++) {
            $chunk = $this->readJson($command, [...$args, (string) $offset]);
            $row = $chunk;
            $piece = (string) ($chunk['LOG'] ?? '');
            $log = $offset === 0 ? $piece : $log.$piece;
            $next = (int) ($chunk['OFFSET'] ?? 0);
            if ($next <= $offset) {
                break;
            }
            $offset = $next;
        }
        $parts = explode("\n", rtrim($log, "\n"));
        $row['LOG'] = $parts === [''] ? '' : implode("\n", array_slice($parts, -$lines));

        return $row;
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
