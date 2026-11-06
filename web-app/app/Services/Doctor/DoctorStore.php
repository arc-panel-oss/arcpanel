<?php

namespace App\Services\Doctor;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Services\Jobs\JobStore;
use App\Services\WebDomains\Step;

/**
 * The Doctor (E17, docs/features/specs/2026-11-05-003-e17-doctor-design.md)
 * through its commands: the stored results of `v-list-sys-doctor`, runs
 * (`v-run-sys-doctor`, a doctor.run job) and fixes (`v-fix-sys-doctor`, a
 * doctor.fix job). Root writes the results; the panel only reads them.
 */
final class DoctorStore
{
    public const STATUSES = ['fail', 'warn', 'ok', 'skip'];

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * The server result: checked_at, score, checks.
     *
     * @return array{checked_at: ?string, score: ?int, checks: list<array<string, mixed>>}
     */
    public function server(): array
    {
        return self::result($this->arc->json('v-list-sys-doctor', ['system']));
    }

    /**
     * The result of DOMAIN of USER.
     *
     * @return array{checked_at: ?string, score: ?int, checks: list<array<string, mixed>>}
     */
    public function domain(string $user, string $domain): array
    {
        return self::result($this->arc->json('v-list-sys-doctor', [$user, $domain]));
    }

    /**
     * The summary of the domains of the users given, or of every user with
     * null: user, domain, checked_at, score, fail, warn, failing; worst score
     * first.
     *
     * @param  list<string>|null  $users
     * @return list<array{user: string, domain: string, checked_at: ?string, score: ?int, fail: int, warn: int, failing: list<string>}>
     */
    public function summary(?array $users): array
    {
        $rows = [];
        if ($users === null) {
            $rows = self::rows($this->arc->json('v-list-sys-doctor', ['all']));
        }
        foreach ($users ?? [] as $user) {
            array_push($rows, ...self::rows($this->arc->json('v-list-sys-doctor', [$user])));
        }
        usort($rows, fn (array $a, array $b) => [$a['score'] ?? 101, $a['user'], $a['domain']] <=> [$b['score'] ?? 101, $b['user'], $b['domain']]);

        return $rows;
    }

    /** `v-run-sys-doctor SCOPE DOMAIN ACTOR`: starts a doctor.run job, stdout is its ID. */
    public function run(string $scope, string $domain, string $actor): CommandResult
    {
        return $this->arc->run('v-run-sys-doctor', self::runArgs($scope, $domain, $actor), Options::allowFailure());
    }

    /** The start of a run as a plan step (API v2 dry runs). */
    public static function runStep(string $scope, string $domain, string $actor): Step
    {
        $label = match (true) {
            $scope === 'system' => __('Run the server checks'),
            $domain !== '' => sprintf(__('Run the checks of %s'), $domain),
            default => sprintf(__('Run the checks of every domain of %s'), $scope),
        };

        return new Step($label, 'v-run-sys-doctor', self::runArgs($scope, $domain, $actor));
    }

    /** `v-fix-sys-doctor SCOPE CHECK DOMAIN ACTOR`: starts a doctor.fix job, stdout is its ID. */
    public function fix(string $scope, string $check, string $domain, string $actor): CommandResult
    {
        return $this->arc->run('v-fix-sys-doctor', [$scope, $check, $domain, JobStore::actor($actor)], Options::allowFailure());
    }

    /** The start of a fix as a plan step (API v2 dry runs). */
    public static function fixStep(string $scope, string $check, string $domain, string $actor, string $command): Step
    {
        return new Step(sprintf(__('Fix %s: %s'), $check, $command), 'v-fix-sys-doctor', [$scope, $check, $domain, JobStore::actor($actor)]);
    }

    /** The job ID a start printed, or null. */
    public static function jobId(CommandResult $result): ?string
    {
        $id = trim($result->stdout);

        return JobStore::validId($id) ? $id : null;
    }

    /** The message of a refused run or fix. */
    public static function refusal(CommandResult $result): string
    {
        return match ($result->exit()) {
            ExitCode::InUse => __('The Doctor is already running for this scope. Wait until it has finished.'),
            ExitCode::Exists => __('The check passes or has no fix; run the Doctor again to see its current result.'),
            default => $result->errorMessage(),
        };
    }

    /** A check of a result by id, or null. */
    public static function check(array $result, string $id): ?array
    {
        foreach ($result['checks'] as $check) {
            if (($check['id'] ?? '') === $id) {
                return $check;
            }
        }

        return null;
    }

    /** Whether ID is a check id of the scope: server.* for system, web.* or mail.* for a domain. */
    public static function validCheck(string $id, bool $server): bool
    {
        return preg_match($server ? '/^server\.[a-z0-9-]{1,64}$/' : '/^(web|mail)\.[a-z0-9-]{1,64}$/', $id) === 1;
    }

    /**
     * The checks grouped by status, fail first.
     *
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, list<array<string, mixed>>>
     */
    public static function grouped(array $checks): array
    {
        $groups = array_fill_keys(self::STATUSES, []);
        foreach ($checks as $check) {
            $groups[in_array($check['status'] ?? '', self::STATUSES, true) ? $check['status'] : 'skip'][] = $check;
        }

        return array_filter($groups);
    }

    /** @return list<string> */
    private static function runArgs(string $scope, string $domain, string $actor): array
    {
        return [$scope, $domain, JobStore::actor($actor)];
    }

    /**
     * @param  array<mixed>  $data
     * @return array{checked_at: ?string, score: ?int, checks: list<array<string, mixed>>}
     */
    private static function result(array $data): array
    {
        $checks = [];
        foreach (is_array($data['checks'] ?? null) ? $data['checks'] : [] as $check) {
            if (! is_array($check) || ! is_string($check['id'] ?? null)) {
                continue;
            }
            $fix = is_array($check['fix'] ?? null) ? $check['fix'] : null;
            $checks[] = [
                'id' => $check['id'],
                'status' => (string) ($check['status'] ?? 'skip'),
                'title' => (string) ($check['title'] ?? ''),
                'message' => (string) ($check['message'] ?? ''),
                'value' => (string) ($check['value'] ?? ''),
                'docs' => (string) ($check['docs'] ?? ''),
                'link' => (string) ($check['link'] ?? ''),
                'fix' => $fix !== null ? ['label' => (string) ($fix['label'] ?? ''), 'command' => (string) ($fix['command'] ?? '')] : null,
            ];
        }

        return [
            'checked_at' => is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null,
            'score' => is_numeric($data['score'] ?? null) ? (int) $data['score'] : null,
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array{user: string, domain: string, checked_at: ?string, score: ?int, fail: int, warn: int, failing: list<string>}>
     */
    private static function rows(array $data): array
    {
        $rows = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! is_string($row['domain'] ?? null)) {
                continue;
            }
            $rows[] = [
                'user' => (string) ($row['user'] ?? ''),
                'domain' => $row['domain'],
                'checked_at' => is_string($row['checked_at'] ?? null) ? $row['checked_at'] : null,
                'score' => is_numeric($row['score'] ?? null) ? (int) $row['score'] : null,
                'fail' => (int) ($row['fail'] ?? 0),
                'warn' => (int) ($row['warn'] ?? 0),
                'failing' => array_values(array_filter((array) ($row['failing'] ?? []), 'is_string')),
            ];
        }

        return $rows;
    }
}
