<?php

namespace App\Services\Docker;

use App\Arc\ArcCommand;
use App\Arc\SysConfig;

/**
 * The Docker engine of the server (E4 spec §5.6, §6.1): its state with the
 * last report of v-check-sys-docker, and the "Install Docker engine" task.
 */
final class DockerEngineService
{
    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $data = $this->arc->json('v-list-sys-docker');
        $report = is_array($data['REPORT'] ?? null) ? $data['REPORT'] : [];
        $findings = [];
        foreach ((array) ($report['FINDINGS'] ?? []) as $row) {
            if (is_array($row)) {
                $findings[] = [
                    'user' => (string) ($row['USER'] ?? ''),
                    'app' => (string) ($row['APP'] ?? ''),
                    'type' => (string) ($row['TYPE'] ?? ''),
                    'detail' => (string) ($row['DETAIL'] ?? ''),
                    'repaired' => (string) ($row['REPAIRED'] ?? ''),
                ];
            }
        }

        return [
            'installed' => ($data['SYSTEM'] ?? '') === 'yes',
            'running' => ($data['RUNNING'] ?? '') === 'yes',
            'version' => (string) ($data['VERSION'] ?? ''),
            'composeVersion' => (string) ($data['COMPOSE_VERSION'] ?? ''),
            'dataRoot' => (string) ($data['DATA_ROOT'] ?? ''),
            'diskUsed' => (int) ($data['DISK_USED'] ?? 0),
            'diskFree' => (int) ($data['DISK_FREE'] ?? 0),
            'images' => (int) ($data['IMAGES'] ?? 0),
            'containers' => (int) ($data['CONTAINERS'] ?? 0),
            'apps' => (int) ($data['APPS'] ?? 0),
            'users' => (int) ($data['USERS'] ?? 0),
            'report' => [
                'time' => (string) ($report['TIME'] ?? ''),
                'findings' => $findings,
            ],
        ];
    }

    /** Start the installation of the engine; returns the task ID. */
    public function startInstall(): string
    {
        $id = trim($this->arc->run('v-start-sys-docker-task')->stdout);
        // DOCKER_SYSTEM changes when the task ends
        $this->config->forget();

        return $id;
    }

    public function task(int $offset = 0): DockerTask
    {
        $task = DockerTask::from($this->arc->json('v-list-sys-docker-task', [(string) $offset]));
        if ($task->status === 'done' && ! $this->config->is('DOCKER_SYSTEM')) {
            $this->config->forget();
        }

        return $task;
    }
}
