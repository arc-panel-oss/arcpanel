<?php

namespace App\Services\Backups;

/**
 * The schedule of a backup job (E48 spec §5.1): hourly:MM, daily:HH:MM,
 * weekly:D:HH:MM (Monday 1), monthly:DD:HH:MM (1-28) or cron:M H DOM MON
 * DOW. The panel renders it in words; the next times come from
 * `v-list-backup-schedule`, the same code as the dispatcher.
 */
final class BackupSchedule
{
    public const PRESETS = ['hourly', 'daily', 'weekly', 'monthly', 'cron'];

    private function __construct(
        public readonly string $preset,
        public readonly int $minute,
        public readonly int $hour,
        public readonly int $weekday,
        public readonly int $day,
        public readonly string $cron,
    ) {}

    public static function parse(string $schedule): self
    {
        $n = fn (string $v): int => (int) $v;
        if (preg_match('/^hourly:(\d{1,2})$/', $schedule, $m)) {
            return new self('hourly', $n($m[1]), 0, 1, 1, '');
        }
        if (preg_match('/^daily:(\d{1,2}):(\d{1,2})$/', $schedule, $m)) {
            return new self('daily', $n($m[2]), $n($m[1]), 1, 1, '');
        }
        if (preg_match('/^weekly:([1-7]):(\d{1,2}):(\d{1,2})$/', $schedule, $m)) {
            return new self('weekly', $n($m[3]), $n($m[2]), $n($m[1]), 1, '');
        }
        if (preg_match('/^monthly:(\d{1,2}):(\d{1,2}):(\d{1,2})$/', $schedule, $m)) {
            return new self('monthly', $n($m[3]), $n($m[2]), 1, $n($m[1]), '');
        }
        if (str_starts_with($schedule, 'cron:')) {
            return new self('cron', 0, 0, 1, 1, substr($schedule, 5));
        }

        return new self('daily', 10, 5, 1, 1, '');
    }

    /** The schedule from the fields of the job form. */
    public static function fromForm(string $preset, int $hour, int $minute, int $weekday, int $day, string $cron): string
    {
        return match ($preset) {
            'hourly' => sprintf('hourly:%02d', $minute),
            'weekly' => sprintf('weekly:%d:%02d:%02d', $weekday, $hour, $minute),
            'monthly' => sprintf('monthly:%02d:%02d:%02d', $day, $hour, $minute),
            'cron' => 'cron:'.preg_replace('/\s+/', ' ', trim($cron)),
            default => sprintf('daily:%02d:%02d', $hour, $minute),
        };
    }

    public function value(): string
    {
        return self::fromForm($this->preset, $this->hour, $this->minute, $this->weekday, $this->day, $this->cron);
    }

    /** "Daily at 05:10", "Every Monday at 02:30", "Cron 0 *\/6 * * *". */
    public function describe(): string
    {
        $at = sprintf('%02d:%02d', $this->hour, $this->minute);
        $weekdays = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')];

        return match ($this->preset) {
            'hourly' => sprintf(__('Every hour at minute %02d'), $this->minute),
            'weekly' => sprintf(__('Every %s at %s'), $weekdays[$this->weekday] ?? '', $at),
            'monthly' => sprintf(__('Monthly on day %d at %s'), $this->day, $at),
            'cron' => sprintf(__('Cron %s'), $this->cron),
            default => sprintf(__('Daily at %s'), $at),
        };
    }
}
