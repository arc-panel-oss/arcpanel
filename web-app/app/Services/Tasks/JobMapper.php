<?php

namespace App\Services\Tasks;

use App\Api\TaskId;
use App\Http\Resources\V2\UserResource;

/**
 * Maps a job (a record of `v-list-job`, or a row of `jobs_log`) to
 * {@see TaskView} (E15 spec §7.1). `kind` keeps the names of the task
 * sources of E34 batch 3 for the job types that replaced them; a newer type
 * is its own kind.
 */
final class JobMapper
{
    /** Job types whose jobs can be retried (E15 spec §3.4, D6), for rows of the index */
    private const RETRYABLE = [
        'backup.user', 'backup.restic', 'backup.download', 'restore.user', 'restore.restic', 'restore.file',
        'letsencrypt.domain', 'deploy.run', 'deploy.rollback', 'docker.install', 'docker.update',
        'node.build', 'node.npm', 'quick-install', 'malware.scan', 'backup.job', 'doctor.run',
    ];

    /**
     * @param  array<string, mixed>  $job  a record of v-list-job (upper-case keys)
     */
    public static function fromRecord(array $job, int $lines = 0): TaskView
    {
        $value = fn (string $key): string => is_scalar($job[$key] ?? null) ? (string) $job[$key] : '';
        $retryable = $value('RETRYABLE') !== '' ? $value('RETRYABLE') === 'yes' : in_array($value('TYPE'), self::RETRYABLE, true);

        return self::view([
            'id' => $value('ID'), 'type' => $value('TYPE'), 'user' => $value('USER'), 'actor' => $value('ACTOR'),
            'object' => $value('OBJECT'), 'args' => $value('ARGS'), 'status' => $value('STATUS'), 'exit' => $value('EXIT'),
            'error' => $value('ERROR'), 'retry_of' => $value('RETRY_OF'), 'resource' => $value('RESOURCE'),
            'queued' => $value('QUEUED'), 'start' => $value('START'), 'end' => $value('END'),
        ], self::tail($value('LOG'), $lines), $retryable);
    }

    /**
     * @param  object|array<string, mixed>  $row  a row of jobs_log
     */
    public static function fromRow(object|array $row): TaskView
    {
        $row = (array) $row;
        $value = fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return self::view([
            'id' => $value('id'), 'type' => $value('type'), 'user' => $value('user'), 'actor' => $value('actor'),
            'object' => $value('object'), 'args' => $value('args'), 'status' => $value('status'), 'exit' => $value('exit_code'),
            'error' => $value('error'), 'retry_of' => $value('retry_of'), 'resource' => $value('resource'),
            'queued' => $value('queued_at'), 'start' => $value('started_at'), 'end' => $value('finished_at'),
        ], '', in_array($value('type'), self::RETRYABLE, true));
    }

    /**
     * @param  array<string, string>  $f
     */
    private static function view(array $f, string $log, bool $retryable): TaskView
    {
        $status = self::status($f['status']);
        $words = self::words($f['args']);

        return new TaskView(
            id: TaskId::job($f['id'])->apiId(),
            kind: self::kind($f['type'], $words),
            status: $status,
            exitCode: in_array($status, ['queued', 'running'], true) || ! ctype_digit($f['exit']) ? null : (int) $f['exit'],
            error: $f['error'] !== '' ? $f['error'] : null,
            startedAt: self::time($f['start']),
            finishedAt: self::time($f['end']),
            queuedAt: self::time($f['queued']),
            log: $log,
            args: count($words) > 1 ? implode(' ', array_slice($words, 1)) : null,
            resource: $f['resource'] !== '' ? $f['resource'] : self::resource($f['type'], $f['user'], $f['object'], $words),
            object: $f['object'] !== '' ? $f['object'] : null,
            actor: $f['actor'] !== '' ? $f['actor'] : null,
            retryOf: $f['retry_of'] !== '' ? TaskId::job($f['retry_of'])->apiId() : null,
            type: $f['type'],
            user: $f['user'],
            retryable: $retryable,
        );
    }

    public static function status(string $raw): string
    {
        return match ($raw) {
            'queued', 'running', 'succeeded', 'failed', 'cancelled' => $raw,
            default => throw new \InvalidArgumentException('Unknown job status.'),
        };
    }

    /**
     * The kind of a job: the task kind of E34 batch 3 for the types that
     * replaced a task source, the type itself for the others.
     *
     * @param  list<string>  $words  the command and its arguments
     */
    public static function kind(string $type, array $words): string
    {
        return match (true) {
            $type === 'docker.engine' => 'docker-engine',
            str_starts_with($type, 'sys.'), str_starts_with($type, 'docker.') => substr($type, strpos($type, '.') + 1),
            str_starts_with($type, 'node.') => $words[2] ?? $type,
            $type === 'quick-install' => 'quick-install',
            $type === 'malware.scan' => 'malware-scan',
            str_starts_with($type, 'app-process.') => 'app-process',
            $type === 'backup.user' => 'backup',
            $type === 'backup.download' => 'backup-download',
            $type === 'backup.restic' => 'snapshot',
            $type === 'restore.user' => 'restore',
            $type === 'restore.file' => 'file-restore',
            $type === 'restore.restic' => 'snapshot-restore',
            $type === 'letsencrypt.domain' => 'letsencrypt',
            str_starts_with($type, 'deploy.') => 'deploy',
            default => $type,
        };
    }

    /**
     * The API v2 path of the object a job changed, when the job record names
     * none.
     *
     * @param  list<string>  $words
     */
    private static function resource(string $type, string $user, string $object, array $words): ?string
    {
        $parts = explode(':', $object);
        $users = '/users/'.$user;

        return match (true) {
            $type === 'docker.engine' => '/system/docker',
            str_starts_with($type, 'docker.') && count($parts) === 3 => $users.'/docker-apps/'.$parts[2],
            str_starts_with($type, 'node.') => str_starts_with($words[2] ?? '', 'app-') && ($words[3] ?? '') !== ''
                ? $users.'/node-apps/'.$words[3] : $users.'/node',
            $type === 'quick-install' && $parts[0] === 'web' => $users.'/web-domains/'.($parts[1] ?? ''),
            $type === 'malware.scan' => $users.'/malware/scans',
            str_starts_with($type, 'app-process.') && count($parts) === 4 => $users.'/app-processes/'.$parts[2].'/'.$parts[3],
            in_array($type, ['backup.download', 'restore.user'], true) && ($words[2] ?? '') !== '' => $users.'/backups/'.$words[2],
            in_array($type, ['restore.restic', 'restore.file'], true) && ($words[2] ?? '') !== '' => $users.'/snapshots/'.$words[2],
            $type === 'letsencrypt.domain' && $parts[0] === 'web' => $users.'/web-domains/'.($parts[1] ?? ''),
            $type === 'letsencrypt.domain' && $parts[0] === 'mail' => $users.'/mail-domains/'.($parts[1] ?? ''),
            str_starts_with($type, 'deploy.') && $parts[0] === 'deploy' => $users.'/web-domains/'.($parts[1] ?? '').'/deploy',
            default => null,
        };
    }

    /**
     * The words of ARGS (space-separated, "quoted" where needed).
     *
     * @return list<string>
     */
    public static function words(string $args): array
    {
        if (trim($args) === '') {
            return [];
        }
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|(\S+)/', $args, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => isset($m[2]) && $m[2] !== '' ? $m[2] : stripcslashes($m[1]), $matches);
    }

    private static function time(string $stamp): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $stamp, $matches) === 1) {
            return UserResource::localTime($matches[1], $matches[2]);
        }

        return null;
    }

    private static function tail(string $log, int $lines): string
    {
        if ($log === '' || $lines <= 0) {
            return '';
        }
        $parts = explode("\n", rtrim($log, "\n"));

        return $parts === [''] ? '' : implode("\n", array_slice($parts, -$lines));
    }
}
