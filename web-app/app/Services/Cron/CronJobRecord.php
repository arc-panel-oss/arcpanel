<?php

namespace App\Services\Cron;

/**
 * One cron job as `v-list-cron-job(s) USER [JOB] json` returns it.
 */
final class CronJobRecord
{
    public function __construct(
        public readonly string $user,
        public readonly string $job,
        public readonly string $min,
        public readonly string $hour,
        public readonly string $day,
        public readonly string $month,
        public readonly string $wday,
        public readonly string $command,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $job, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            user: $user,
            job: $value('JOB') !== '' ? $value('JOB') : $job,
            min: $value('MIN'),
            hour: $value('HOUR'),
            day: $value('DAY'),
            month: $value('MONTH'),
            wday: $value('WDAY'),
            command: (string) ($row['CMD'] ?? ''),
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }

    /**
     * The five schedule fields, as the forms and the cron builder name them.
     *
     * @return array{min: string, hour: string, day: string, month: string, wday: string}
     */
    public function schedule(): array
    {
        return ['min' => $this->min, 'hour' => $this->hour, 'day' => $this->day, 'month' => $this->month, 'wday' => $this->wday];
    }

    /** "MIN HOUR DAY MONTH WDAY" */
    public function expression(): string
    {
        return implode(' ', $this->schedule());
    }
}
