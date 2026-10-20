<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Services\WebDomains\Step;
use Illuminate\Validation\ValidationException;

/**
 * The Update Center of E33 phase 1 (docs/features/specs/2026-10-20-005-e33-update-center-phase-1-design.md
 * §7–§9): the releases of the signed manifest, the update policy, the
 * pre-flight, the history, and the update and rollback tasks. The commands
 * own every rule; this class reads their JSON and plans their steps.
 */
final class UpdateCenter
{
    public const POLICIES = ['off', 'notify', 'patch'];

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** A version of the manifest, also a beta version (`2.5.0~beta.1`). */
    public const VERSION_PATTERN = '\d{1,3}\.\d{1,3}\.\d{1,3}(~[0-9A-Za-z.]{1,32})?';

    /** `HH-HH`, the end may be 24 (`v-change-sys-update-policy`). */
    public const HOURS_PATTERN = '/^([01]\d|2[0-3])-([01]\d|2[0-4])$/';

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * `v-list-sys-update-policy json`
     *
     * @return array{policy: string, windowDays: list<string>, windowHours: string, delayDays: int, paused: ?string, nextWindow: ?string, version: string, line: string, channel: string, manifestChecked: ?string, manifestGenerated: ?string, manifestError: ?string}
     */
    public function policy(): array
    {
        $row = $this->arc->json('v-list-sys-update-policy');
        $days = (string) ($row['WINDOW_DAYS'] ?? 'all');

        return [
            'policy' => (string) ($row['POLICY'] ?? 'notify'),
            'windowDays' => $days === 'all' ? self::DAYS : array_values(array_filter(explode(',', $days))),
            'windowHours' => (string) ($row['WINDOW_HOURS'] ?? ''),
            'delayDays' => (int) ($row['DELAY_DAYS'] ?? 0),
            'paused' => self::orNull($row['PAUSED'] ?? ''),
            'nextWindow' => self::orNull($row['NEXT_WINDOW'] ?? ''),
            'version' => (string) ($row['VERSION'] ?? ''),
            'line' => (string) ($row['LINE'] ?? ''),
            'channel' => (string) ($row['CHANNEL'] ?? ''),
            'manifestChecked' => self::orNull($row['MANIFEST_CHECKED'] ?? ''),
            'manifestGenerated' => self::orNull($row['MANIFEST_GENERATED'] ?? ''),
            'manifestError' => self::orNull($row['MANIFEST_ERROR'] ?? ''),
        ];
    }

    /** setUpdateSettings `auto_update` (§9): automatic updates are on with the policy `patch`. */
    public function autoUpdate(): bool
    {
        return $this->policy()['policy'] === 'patch';
    }

    /**
     * The releases newer than the installed version (`v-list-sys-arcpanel-releases json [yes]`).
     * Without a verified manifest the command fails with E_NOTEXIST and its reason.
     *
     * @return list<array<string, mixed>> the rows of the command
     */
    public function releases(bool $refresh = false): array
    {
        // json() puts FORMAT last, but REFRESH follows it
        $rows = $refresh
            ? json_decode($this->arc->run('v-list-sys-arcpanel-releases', ['json', 'yes'])->stdout, true)
            : $this->arc->json('v-list-sys-arcpanel-releases');

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /**
     * The release VERSION of the list, or null.
     *
     * @return array<string, mixed>|null
     */
    public function release(string $version): ?array
    {
        foreach ($this->releases() as $row) {
            if (($row['VERSION'] ?? null) === $version) {
                return $row;
            }
        }

        return null;
    }

    /**
     * An update runs to a release of the manifest: listed, not withdrawn,
     * and confirmed when it needs attention. The task checks the rest
     * (§7.3).
     *
     * @throws ValidationException on `version` or `confirm_attention`
     */
    public function assertRelease(string $version, bool $confirmed): void
    {
        $release = $this->release($version);
        if ($release === null) {
            throw ValidationException::withMessages(['version' => sprintf(__('ArcPanel %s is not a release newer than the installed version.'), $version)]);
        }
        if (is_array($release['WITHDRAWN'] ?? null)) {
            throw ValidationException::withMessages(['version' => sprintf(__('ArcPanel %s was withdrawn.'), $version)]);
        }
        if (($release['ATTENTION'] ?? false) === true && ! $confirmed) {
            throw ValidationException::withMessages(['confirm_attention' => sprintf(__('ArcPanel %s needs attention: read its attention items and confirm them.'), $version)]);
        }
    }

    /**
     * The pre-flight of a manual update to VERSION (`v-check-sys-arcpanel-update VERSION json manual`).
     * A refusal (E_FORBIDEN) still lists every check.
     *
     * @return array{ready: bool, checks: list<array{check: string, status: string, message: string}>}
     */
    public function preflight(string $version): array
    {
        $result = $this->arc->run('v-check-sys-arcpanel-update', [$version, 'json', 'manual'], Options::allowFailure());
        $rows = json_decode($result->stdout, true);
        if (! is_array($rows) || ! ($result->ok() || $result->exit() === ExitCode::Forbidden)) {
            throw ArcCommandException::failed($result);
        }
        $checks = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $checks[] = [
                    'check' => (string) ($row['CHECK'] ?? ''),
                    'status' => (string) ($row['STATUS'] ?? ''),
                    'message' => (string) ($row['MESSAGE'] ?? ''),
                ];
            }
        }

        return ['ready' => $result->ok(), 'checks' => $checks];
    }

    /**
     * The update runs, newest first (`v-list-sys-update-history json`); the
     * newest update has `ROLLBACK` and `ROLLBACK_REASON`.
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        return array_values(array_filter($this->arc->json('v-list-sys-update-history'), 'is_array'));
    }

    /**
     * The policy change when a value changes; a value left out (null) keeps
     * the current one.
     *
     * @param  list<string>|null  $days
     * @return list<Step>
     */
    public function planPolicy(?string $policy, ?array $days, ?string $hours, ?int $delay): array
    {
        $current = $this->policy();
        $next = [
            $policy ?? $current['policy'],
            self::daysArg($days ?? $current['windowDays']),
            $hours ?? $current['windowHours'],
            (string) ($delay ?? $current['delayDays']),
        ];
        $now = [$current['policy'], self::daysArg($current['windowDays']), $current['windowHours'], (string) $current['delayDays']];
        if ($next === $now) {
            return [];
        }

        return [new Step(__('Change the update policy'), 'v-change-sys-update-policy', $next)];
    }

    /**
     * The `auto_update` switch of setUpdateSettings when it changes (§9):
     * on sets `patch`, off sets `notify`; the window and the delay stay.
     *
     * @return list<Step>
     */
    public function planAutoUpdate(bool $on): array
    {
        if ($on === $this->autoUpdate()) {
            return [];
        }

        return $on
            ? [new Step(__('Turn on automatic updates'), 'v-change-sys-update-policy', ['patch'])]
            : [new Step(__('Turn off automatic updates'), 'v-change-sys-update-policy', ['notify'])];
    }

    public static function planResume(): Step
    {
        return new Step(__('Resume automatic updates'), 'v-resume-sys-arcpanel-autoupdate', []);
    }

    /**
     * The update to a release of the manifest as the system task
     * `arcpanel-release VERSION TRIGGER ACTOR` (§7.6).
     *
     * @param  'admin'|'api'  $trigger
     */
    public static function planRelease(string $version, string $trigger, string $actor): Step
    {
        return SystemTaskService::step(sprintf(__('Update ArcPanel to %s'), $version), 'arcpanel-release', [$version, $trigger, $actor]);
    }

    /** The rollback of update run ID as the system task `arcpanel-rollback ID`; it restarts the panel. */
    public static function planRollback(int $id): Step
    {
        return SystemTaskService::step(sprintf(__('Roll back update run %d'), $id), 'arcpanel-rollback', [(string) $id]);
    }

    /** @param  list<string>  $days */
    private static function daysArg(array $days): string
    {
        $days = array_values(array_intersect(self::DAYS, $days));

        return $days === self::DAYS ? 'all' : implode(',', $days);
    }

    private static function orNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' ? $value : null;
    }
}
