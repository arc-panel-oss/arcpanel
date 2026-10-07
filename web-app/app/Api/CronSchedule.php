<?php

namespace App\Api;

use Illuminate\Validation\ValidationException;

/**
 * One cron `schedule` of five crontab fields (E34 batch 2 spec D28).
 * Each field follows the rule of the page's five inputs. A macro such as
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
        foreach ($fields as $field) {
            if (strlen($field) > 128 || preg_match('/[\r\n\x00]/', $field) === 1) {
                throw ValidationException::withMessages(['schedule' => __('A schedule is five crontab fields.')]);
            }
        }

        return [
            'min' => $fields[0],
            'hour' => $fields[1],
            'day' => $fields[2],
            'month' => $fields[3],
            'wday' => $fields[4],
        ];
    }

    /** @param  array{min: string, hour: string, day: string, month: string, wday: string}  $fields */
    public static function format(array $fields): string
    {
        return $fields['min'].' '.$fields['hour'].' '.$fields['day'].' '.$fields['month'].' '.$fields['wday'];
    }
}
