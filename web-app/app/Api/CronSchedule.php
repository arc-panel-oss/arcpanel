<?php

namespace App\Api;

use App\Rules\CronField;
use Illuminate\Validation\ValidationException;

/**
 * One cron `schedule` of five crontab fields (E34 batch 2 spec D28).
 * Each field follows the rule of the page's five inputs (CronField). A macro such as
 * `@daily` is refused: the commands store five fields.
 */
final class CronSchedule
{
    /**
     * @return array{min: string, hour: string, day: string, month: string, wday: string}
     *
     * @throws ValidationException
     */
    public static function parse(string $schedule): array
    {
        $fields = explode(' ', $schedule);
        if (count($fields) !== 5 || in_array('', $fields, true)) {
            throw ValidationException::withMessages(['schedule' => __('A schedule is five crontab fields.')]);
        }
        $named = array_combine(['min', 'hour', 'day', 'month', 'wday'], $fields);
        foreach ($named as $name => $field) {
            if (! CronField::valid($name, $field)) {
                throw ValidationException::withMessages(['schedule' => __('A schedule is five crontab fields.')]);
            }
        }

        /** @var array{min: string, hour: string, day: string, month: string, wday: string} $named */
        return $named;
    }

    /** @param  array{min: string, hour: string, day: string, month: string, wday: string}  $fields */
    public static function format(array $fields): string
    {
        return $fields['min'].' '.$fields['hour'].' '.$fields['day'].' '.$fields['month'].' '.$fields['wday'];
    }
}
