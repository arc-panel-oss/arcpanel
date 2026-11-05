<?php

namespace App\Services\Jobs;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Options;

/**
 * The job files of the CLI (E15, docs/features/specs/2026-11-05-001-e15-job-center-design.md
 * §3.2, §4.1): one job with its log from `v-list-job`, every job updated
 * since a time from `v-list-jobs`, cancel and retry. Root owns the files; the
 * panel only reads them through these commands.
 */
final class JobStore
{
    /** At most this many bytes per v-list-job call (the command's limit). */
    public const CHUNK = 262144;

    public function __construct(private readonly ArcCommand $arc) {}

    public static function validId(string $id): bool
    {
        return preg_match('/^\d{14}$/', $id) === 1;
    }

    /**
     * One job and its log from byte OFFSET (LOG, OFFSET of the next call,
     * SIZE); null when the job does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, int $offset = 0): ?array
    {
        if (! self::validId($id)) {
            return null;
        }
        $result = $this->arc->run('v-list-job', [$id, (string) max(0, $offset), 'json'], Options::allowFailure());
        if (! $result->ok()) {
            if ($result->exit() === ExitCode::NotExist || $result->exit() === ExitCode::Invalid) {
                return null;
            }
            throw new JobStoreException('The job could not be read.');
        }
        $job = json_decode($result->stdout, true);
        if (! is_array($job) || ($job['ID'] ?? '') !== $id) {
            throw new JobStoreException('The job could not be read.');
        }

        return $job;
    }

    /**
     * One job with the last LINES lines of its log in LOG.
     *
     * @return array<string, mixed>|null
     */
    public function findWithTail(string $id, int $lines): ?array
    {
        $job = $this->find($id);
        if ($job === null) {
            return null;
        }
        $size = (int) ($job['SIZE'] ?? 0);
        if ($size > (int) ($job['OFFSET'] ?? 0)) {
            $last = $this->find($id, max(0, $size - self::CHUNK));
            if ($last !== null) {
                $job = $last;
            }
        }
        $job['LOG'] = self::tail((string) ($job['LOG'] ?? ''), $lines);

        return $job;
    }

    /**
     * Every job whose UPDATED is at or after SINCE (YYYY-MM-DD HH:MM:SS), or
     * every job when SINCE is null, keyed by ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function since(?string $since): array
    {
        $result = $this->arc->run('v-list-jobs', [$since ?? 'all', 'json'], Options::allowFailure());
        if (! $result->ok()) {
            throw new JobStoreException('The jobs could not be listed.');
        }
        $jobs = json_decode($result->stdout, true);
        if (! is_array($jobs)) {
            throw new JobStoreException('The jobs could not be listed.');
        }

        return array_filter($jobs, 'is_array');
    }

    /** `v-cancel-job ID ACTOR` */
    public function cancel(string $id, string $actor): CommandResult
    {
        return $this->arc->run('v-cancel-job', [$id, self::actor($actor)], Options::allowFailure());
    }

    /** `v-retry-job ID ACTOR json`; the new ID is in the JSON answer */
    public function retry(string $id, string $actor): CommandResult
    {
        return $this->arc->run('v-retry-job', [$id, self::actor($actor), 'json'], Options::allowFailure());
    }

    /** The new job ID of a successful retry */
    public static function retriedId(CommandResult $result): ?string
    {
        $data = json_decode($result->stdout, true);
        $id = is_array($data) ? (string) ($data['ID'] ?? '') : trim($result->stdout);

        return self::validId($id) ? $id : null;
    }

    /** An ACTOR the commands accept (spec §4.3) */
    public static function actor(string $actor): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.:@-]/', '', $actor) ?? '';

        return $clean !== '' ? substr($clean, 0, 64) : 'panel';
    }

    public static function tail(string $log, int $lines): string
    {
        if ($log === '' || $lines <= 0) {
            return '';
        }
        $parts = explode("\n", rtrim($log, "\n"));

        return $parts === [''] ? '' : implode("\n", array_slice($parts, -$lines));
    }
}
