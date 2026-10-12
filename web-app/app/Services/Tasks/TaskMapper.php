<?php

namespace App\Services\Tasks;

use App\Api\TaskId;
use App\Http\Resources\V2\UserResource;

/**
 * Maps a task source row to {@see TaskView} (E34 batch 3 spec §5).
 */
final class TaskMapper
{
    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromSource(TaskId $id, array $row, int $lines): TaskView
    {
        $status = self::mapStatus((string) ($row['STATUS'] ?? ''));
        $exit = (string) ($row['EXIT'] ?? '');
        $error = (string) ($row['ERROR'] ?? '');

        return new TaskView(
            id: $id->apiId(),
            kind: self::kind($id, $row),
            status: $status,
            exitCode: self::exitCode($status, $exit),
            error: $error !== '' ? $error : null,
            startedAt: self::time((string) ($row['START'] ?? '')),
            finishedAt: self::time((string) ($row['END'] ?? '')),
            queuedAt: $id->source === TaskId::SOURCE_QUEUE ? self::time((string) ($row['QUEUED'] ?? '')) : null,
            log: self::logTail((string) ($row['LOG'] ?? ''), $lines),
            args: self::args($row),
            resource: self::resource($id, $row),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function kind(TaskId $id, array $row): string
    {
        return match ($id->source) {
            TaskId::SOURCE_SYS => (string) ($row['KIND'] ?? ''),
            TaskId::SOURCE_DOCKER_ENGINE => 'docker-engine',
            TaskId::SOURCE_INSTALL => 'quick-install',
            TaskId::SOURCE_MALWARE => 'malware-scan',
            TaskId::SOURCE_NODE, TaskId::SOURCE_DOCKER => (string) ($row['ACTION'] ?? $row['KIND'] ?? ''),
            TaskId::SOURCE_QUEUE => (string) ($row['KIND'] ?? ''),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function args(array $row): ?string
    {
        $args = (string) ($row['ARGS'] ?? '');
        if ($args === '' && isset($row['APP'])) {
            $args = (string) $row['APP'];
        }

        return $args !== '' ? $args : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function resource(TaskId $id, array $row): ?string
    {
        $user = $id->user ?? '';

        return match ($id->source) {
            TaskId::SOURCE_SYS => null,
            TaskId::SOURCE_DOCKER_ENGINE => '/system/docker',
            TaskId::SOURCE_NODE => self::nodeResource($user, (string) ($row['ACTION'] ?? ''), (string) ($row['ARGS'] ?? '')),
            TaskId::SOURCE_DOCKER => '/users/'.$user.'/docker-apps/'.$id->app,
            TaskId::SOURCE_INSTALL => self::installResource($user, (string) ($row['DOMAIN'] ?? '')),
            TaskId::SOURCE_MALWARE => '/users/'.$user.'/malware/scans',
            TaskId::SOURCE_QUEUE => self::queueResource($user, (string) ($row['KIND'] ?? ''), (string) ($row['ARGS'] ?? '')),
            default => null,
        };
    }

    private static function nodeResource(string $user, string $action, string $args): string
    {
        if (str_starts_with($action, 'app-')) {
            $domain = trim(explode(' ', $args, 2)[0] ?? '');

            return $domain !== '' ? '/users/'.$user.'/node-apps/'.$domain : '/users/'.$user.'/node';
        }

        return '/users/'.$user.'/node';
    }

    private static function installResource(string $user, string $domain): ?string
    {
        return $domain !== '' ? '/users/'.$user.'/web-domains/'.$domain : null;
    }

    private static function queueResource(string $user, string $kind, string $args): ?string
    {
        $token = trim(explode(' ', $args, 2)[0] ?? $args);

        return match ($kind) {
            'letsencrypt' => str_ends_with($args, ' mail')
                ? '/users/'.$user.'/mail-domains/'.trim(str_replace(' mail', '', $args))
                : ($token !== '' ? '/users/'.$user.'/web-domains/'.$token : null),
            'backup', 'restore', 'backup-download' => $token !== '' ? '/users/'.$user.'/backups/'.$token : null,
            'snapshot', 'snapshot-restore', 'file-restore' => $token !== '' ? '/users/'.$user.'/snapshots/'.$token : null,
            default => null,
        };
    }

    public static function mapStatus(string $raw): string
    {
        return match ($raw) {
            'running' => 'running',
            'done', 'succeeded' => 'succeeded',
            'failed' => 'failed',
            'queued' => 'queued',
            default => throw new \InvalidArgumentException('Unknown task status.'),
        };
    }

    private static function exitCode(string $status, string $exit): ?int
    {
        if ($status === 'running' || $status === 'queued') {
            return null;
        }
        if ($exit === '' || ! ctype_digit($exit)) {
            return null;
        }

        return (int) $exit;
    }

    private static function time(string $stamp): ?string
    {
        if ($stamp === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $stamp, $matches) === 1) {
            return UserResource::localTime($matches[1], $matches[2]);
        }

        return null;
    }

    private static function logTail(string $log, int $lines): string
    {
        if ($log === '' || $lines <= 0) {
            return '';
        }
        $parts = explode("\n", rtrim($log, "\n"));
        if ($parts === ['']) {
            return '';
        }

        return implode("\n", array_slice($parts, -$lines));
    }

    public static function sortKey(TaskView $task): string
    {
        return $task->queuedAt ?? $task->startedAt ?? $task->finishedAt ?? '';
    }
}
