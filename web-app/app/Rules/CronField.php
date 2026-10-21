<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One crontab field, as `is_cron_format_valid` of func/main.sh checks it
 * (E39 W5 spec §6.1): a comma list of `*`, `*`/STEP, N, N-M or N-M/STEP,
 * numbers within the field's range, at most 128 characters.
 */
final class CronField implements ValidationRule
{
    /** @var array<string, array{int, int}> lowest and highest value per field */
    private const RANGES = [
        'min' => [0, 59],
        'hour' => [0, 23],
        'day' => [1, 31],
        'month' => [1, 12],
        'wday' => [0, 7],
    ];

    public function __construct(private readonly string $field) {}

    public static function valid(string $field, string $value): bool
    {
        [$min, $max] = self::RANGES[$field];
        if ($value === '' || strlen($value) > 128 || preg_match('/^[0-9*\/,-]+$/D', $value) !== 1) {
            return false;
        }
        $items = explode(',', $value);
        if (count($items) > 64) {
            return false;
        }
        foreach ($items as $item) {
            if (preg_match('/^\*(?:\/([0-9]{1,2}))?$/D', $item, $m) === 1) {
                $step = $m[1] ?? '';
            } elseif (preg_match('/^([0-9]{1,2})(?:-([0-9]{1,2})(?:\/([0-9]{1,2}))?)?$/D', $item, $m) === 1) {
                $low = (int) $m[1];
                $high = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $low;
                if ($low < $min || $high > $max || $low > $high) {
                    return false;
                }
                $step = $m[3] ?? '';
            } else {
                return false;
            }
            if ($step !== '' && ((int) $step < 1 || (int) $step > $max)) {
                return false;
            }
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valid($this->field, $value)) {
            $fail(__('The :attribute field is not a valid crontab value.'));
        }
    }
}
