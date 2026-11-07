<?php

namespace App\View;

/** Formatting of job times for the Job Center pages (E15 spec §6.2). */
final class JobFormat
{
    /** The run time of a job ("1 h 2 min", "3 min 4 s", "5 s"); running jobs up to now. */
    public static function duration(?string $start, ?string $end): string
    {
        if ($start === null || $start === '') {
            return '';
        }
        $from = strtotime(str_replace('T', ' ', $start));
        $to = $end !== null && $end !== '' ? strtotime(str_replace('T', ' ', $end)) : time();
        if ($from === false || $to === false || $to < $from) {
            return '';
        }
        $seconds = $to - $from;
        if ($seconds >= 3600) {
            return sprintf('%d h %d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        if ($seconds >= 60) {
            return sprintf('%d min %d s', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d s', $seconds);
    }

    /** A number of minutes in words ("1 day 1 h", "1 h 30 min", "30 min"): the cooldown and FOR of alert rules. */
    public static function minutes(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($days > 0) {
            $parts[] = $days === 1 ? '1 day' : sprintf('%d days', $days);
        }
        if ($hours > 0) {
            $parts[] = sprintf('%d h', $hours);
        }
        if ($rest > 0 || $parts === []) {
            $parts[] = sprintf('%d min', $rest);
        }

        return implode(' ', $parts);
    }
}
