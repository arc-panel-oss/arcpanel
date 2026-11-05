<?php

namespace App\Http\Resources\V2;

/**
 * Shapes of the alert answers of REST API v2 (E16 spec §8), shared by the
 * alert resources.
 */
final class AlertResources
{
    /** @return list<string> */
    public static function ids(string $list): array
    {
        return array_values(array_filter(explode(',', $list), fn (string $id) => $id !== ''));
    }

    public static function number(string $value): int|float|null
    {
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }
}
