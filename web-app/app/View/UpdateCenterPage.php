<?php

namespace App\View;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the Updates page shows (E33 phase 1 spec §8, templates/nocturne/admin/updates*.html):
 * the line under each release from its `REASON`, the policy summary, and
 * the labels of the history. Every text is translated here.
 */
final class UpdateCenterPage
{
    /** Badge tone of a run result. */
    private const RESULT_TONES = [
        'running' => 'accent',
        'done' => 'ok',
        'failed' => 'danger',
        'rolled-back' => 'warn',
        'paused' => 'danger',
        'skipped' => null,
    ];

    /**
     * @param  array{policy: string, windowDays: list<string>, windowHours: string, delayDays: int, paused: ?string, nextWindow: ?string, version: string, line: string}  $policy
     */
    public function __construct(private readonly array $policy) {}

    public static function policyLabel(string $policy): string
    {
        return match ($policy) {
            'off' => __('Off'),
            'notify' => __('Notify only'),
            'patch' => __('Patch releases'),
            default => $policy,
        };
    }

    /** "Mon–Sun 03:00–05:00, after 2 days" */
    public function windowSummary(): string
    {
        $days = $this->policy['windowDays'];
        $names = self::dayNames();
        $dayText = count($days) === 7
            ? $names['mon'].'–'.$names['sun']
            : implode(', ', array_map(fn (string $day) => $names[$day] ?? $day, $days));
        $delay = $this->policy['delayDays'];
        $delayText = $delay === 0 ? __('no delay') : sprintf(trans_choice('after %d day|after %d days', $delay), $delay);

        return sprintf('%s %s, %s', $dayText, self::hoursText($this->policy['windowHours']), $delayText);
    }

    /** "03-05" => "03:00–05:00" */
    public static function hoursText(string $hours): string
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $hours, $m) !== 1) {
            return $hours;
        }

        return $m[1].':00–'.$m[2].':00';
    }

    /** @return array{0: int, 1: int} the first and the end hour of the window */
    public function windowHours(): array
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $this->policy['windowHours'], $m) !== 1) {
            return [2, 4];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** @return array<string, string> mon … sun => short name */
    public static function dayNames(): array
    {
        return [
            'mon' => __('Mon'), 'tue' => __('Tue'), 'wed' => __('Wed'), 'thu' => __('Thu'),
            'fri' => __('Fri'), 'sat' => __('Sat'), 'sun' => __('Sun'),
        ];
    }

    /**
     * The line under a release of `v-list-sys-arcpanel-releases`.
     *
     * @param  array<string, mixed>  $release
     * @return array{tone: ?string, icon: string, text: string}
     */
    public function reasonLine(array $release): array
    {
        $reason = is_string($release['REASON'] ?? null) ? $release['REASON'] : '';
        $next = $this->policy['nextWindow'] !== null ? self::time($this->policy['nextWindow']) : null;
        if (($release['ELIGIBLE'] ?? false) === true) {
            $text = $next !== null
                ? sprintf(__('Installs on its own in the next window, %s.'), $next)
                : __('Installs on its own in the next window.');
            if (($release['SECURITY'] ?? false) === true && $this->policy['delayDays'] > 0) {
                $text .= ' '.__('Security release, so the delay does not apply.');
            }

            return ['tone' => 'ok', 'icon' => 'check-circle', 'text' => $text];
        }
        $withdrawn = is_array($release['WITHDRAWN'] ?? null) ? $release['WITHDRAWN'] : null;
        if ($withdrawn !== null || $reason === 'withdrawn') {
            return ['tone' => 'danger', 'icon' => 'x-circle', 'text' => sprintf(
                __('Withdrawn on %s: %s. It will not be installed.'),
                self::date((string) ($withdrawn['date'] ?? '')),
                rtrim((string) ($withdrawn['reason'] ?? ''), '.'),
            )];
        }
        if (str_starts_with($reason, 'delay until ')) {
            return ['tone' => null, 'icon' => 'clock', 'text' => sprintf(
                __('Waits out the %d-day delay; installs from %s.'),
                $this->policy['delayDays'],
                self::date(substr($reason, strlen('delay until '))),
            )];
        }
        if (str_starts_with($reason, 'min_from ')) {
            $from = substr($reason, strlen('min_from '));

            return ['tone' => null, 'icon' => 'info', 'text' => sprintf(__('Needs %1$s or newer first. Update to %1$s, then to this release.'), $from)];
        }

        return match ($reason) {
            'attention' => in_array($release['KIND'] ?? '', ['minor', 'major'], true)
                ? ['tone' => 'warn', 'icon' => 'alert', 'text' => sprintf(__('A new line needs an administrator. Automatic updates stay on %s.'), $this->policy['line'])]
                : ['tone' => 'warn', 'icon' => 'alert', 'text' => __('Needs an administrator: read the items below, then install it by hand.')],
            'outside window' => ['tone' => null, 'icon' => 'calendar', 'text' => $next !== null
                ? sprintf(__('Outside the update window; installs %s.'), $next)
                : __('Outside the update window.')],
            'paused' => ['tone' => null, 'icon' => 'pause', 'text' => __('Automatic updates are paused. Resume them, or install it by hand.')],
            'not available' => ['tone' => null, 'icon' => 'package', 'text' => __('Not in the APT repository for this server yet.')],
            'rolled back' => ['tone' => 'warn', 'icon' => 'alert', 'text' => __('Rolled back on this server after a failed update. It will not be installed on its own; install it by hand.')],
            default => ['tone' => null, 'icon' => 'bell', 'text' => match ($this->policy['policy']) {
                'off' => __('Automatic updates are off. Install it by hand.'),
                'notify' => __('The policy is Notify only. Install it by hand.'),
                default => __('Automatic updates install patch releases of the installed line only. Install it by hand.'),
            }],
        };
    }

    /** @return array{tone: ?string, label: string} */
    public static function result(string $result): array
    {
        $label = match ($result) {
            'running' => __('Running'),
            'done' => __('Done'),
            'failed' => __('Failed'),
            'rolled-back' => __('Rolled back'),
            'paused' => __('Paused'),
            'skipped' => __('Skipped'),
            default => $result,
        };

        return ['tone' => self::RESULT_TONES[$result] ?? null, 'label' => $label];
    }

    public static function trigger(string $trigger): string
    {
        return match ($trigger) {
            'policy' => __('Policy'),
            'admin' => __('Admin'),
            'api' => __('API'),
            default => $trigger,
        };
    }

    /** "2 min 41 s" between two times of the history, or null. */
    public static function took(?string $start, ?string $end): ?string
    {
        if (! is_string($start) || $start === '' || ! is_string($end) || $end === '') {
            return null;
        }
        try {
            $seconds = CarbonImmutable::parse($start)->diffInSeconds(CarbonImmutable::parse($end), true);
        } catch (Throwable) {
            return null;
        }
        $seconds = (int) $seconds;

        return $seconds >= 60
            ? sprintf(__('%d min %02d s'), intdiv($seconds, 60), $seconds % 60)
            : sprintf(__('%d s'), $seconds);
    }

    /** "Oct 28, 03:12" of a time in the server's zone; the input when it does not parse. */
    public static function time(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->translatedFormat('M j, H:i');
        } catch (Throwable) {
            return $value;
        }
    }

    /** "Oct 28, 2026" of a date; the input when it does not parse. */
    public static function date(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        try {
            return CarbonImmutable::parse($value)->translatedFormat('M j, Y');
        } catch (Throwable) {
            return $value;
        }
    }
}
