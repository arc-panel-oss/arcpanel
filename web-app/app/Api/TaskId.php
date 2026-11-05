<?php

namespace App\Api;

/**
 * Parsed task id of API v2 (E34 batch 3 spec D31). User names may contain
 * dots; segments are separated by colons.
 *
 * Since E15 every task is a job (docs/features/specs/2026-11-05-001-e15-job-center-design.md
 * D8, D10): `job:ID` names it, and every id of the older sources resolves to
 * the job with the same 14 digits. Answers name tasks `job:ID`.
 */
final class TaskId
{
    /** A job of the Job Center (E15). */
    public const SOURCE_JOB = 'job';

    public const SOURCE_SYS = 'sys';

    public const SOURCE_DOCKER_ENGINE = 'docker-engine';

    public const SOURCE_NODE = 'node';

    public const SOURCE_DOCKER = 'docker';

    public const SOURCE_INSTALL = 'install';

    public const SOURCE_QUEUE = 'queue';

    /** A background malware scan of a user (E25 batch 1 spec D10). */
    public const SOURCE_MALWARE = 'malware';

    /** The one-off task of a user's app processes (E42; Fleet PD4). */
    public const SOURCE_APP_PROCESS = 'app-process';

    public function __construct(
        public readonly string $source,
        public readonly string $recordId,
        public readonly ?string $user = null,
        public readonly ?string $app = null,
    ) {}

    /** The id answers carry: `job:ID` (E15 D8). */
    public function apiId(): string
    {
        return self::SOURCE_JOB.':'.$this->recordId;
    }

    /** The job ID: the 14 digits of every form. */
    public function jobId(): string
    {
        return $this->recordId;
    }

    public static function job(string $jobId): self
    {
        return new self(self::SOURCE_JOB, $jobId);
    }

    public static function queue(string $user, string $recordId): self
    {
        return new self(self::SOURCE_QUEUE, $recordId, $user);
    }

    /**
     * Returns null when the id is malformed (D32 → 404). Every source
     * numbers its tasks with the date in seconds and four random digits,
     * so a record id is exactly 14 digits; anything else would reach the
     * list commands as another argument.
     */
    public static function parse(string $id): ?self
    {
        $parts = explode(':', $id);
        if (preg_match('/^\d{14}$/', (string) end($parts)) !== 1) {
            return null;
        }

        return match ($parts[0]) {
            self::SOURCE_JOB, self::SOURCE_SYS, self::SOURCE_DOCKER_ENGINE => count($parts) === 2 && $parts[1] !== ''
                ? new self($parts[0], $parts[1])
                : null,
            self::SOURCE_NODE, self::SOURCE_INSTALL, self::SOURCE_QUEUE, self::SOURCE_MALWARE, self::SOURCE_APP_PROCESS => count($parts) === 3
                && $parts[1] !== '' && $parts[2] !== ''
                ? new self($parts[0], $parts[2], $parts[1])
                : null,
            self::SOURCE_DOCKER => count($parts) === 4
                && $parts[1] !== '' && $parts[2] !== '' && $parts[3] !== ''
                ? new self($parts[0], $parts[3], $parts[1], $parts[2])
                : null,
            default => null,
        };
    }

    /**
     * Whether the job this id resolves to belongs to the id's source: the
     * user of a user-scoped id, `system` for the server sources, the app of a
     * Docker id. A `job:` id matches every job.
     *
     * @param  array<string, mixed>  $job  a record of v-list-job
     */
    public function matches(array $job): bool
    {
        $user = (string) ($job['USER'] ?? '');
        $type = (string) ($job['TYPE'] ?? '');

        return match ($this->source) {
            self::SOURCE_JOB => true,
            self::SOURCE_SYS => $user === 'system' && str_starts_with($type, 'sys.'),
            self::SOURCE_DOCKER_ENGINE => $user === 'system' && $type === 'docker.engine',
            self::SOURCE_DOCKER => $user === $this->user && (string) ($job['OBJECT'] ?? '') === 'docker:'.$this->user.':'.$this->app,
            default => $user === $this->user,
        };
    }
}
