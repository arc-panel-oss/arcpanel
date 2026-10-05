<?php

namespace App\View;

use Illuminate\Support\ViewErrorBag;

/**
 * Shared wiring of the form components: ids, old input and the error of a
 * field (FormRequest errors, optionally in a named error bag per form).
 */
final class FormField
{
    /** "ftp[0][path]" → "ftp.0.path" (old() and error keys). */
    public static function key(string $name): string
    {
        return trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    }

    /** A stable id for a field name, e.g. "ftp[0][path]" → "f-ftp-0-path". */
    public static function id(string $name, ?string $prefix = null): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');

        return ($prefix ? $prefix.'-' : 'f-').$slug;
    }

    public static function error(mixed $errors, string $name, ?string $bag = null): ?string
    {
        if (! $errors instanceof ViewErrorBag) {
            return null;
        }
        $message = $errors->getBag($bag ?? 'default')->first(self::key($name));

        return $message !== '' ? $message : null;
    }

    public static function old(string $name, mixed $default = null): mixed
    {
        return old(self::key($name), $default);
    }

    /**
     * Whether the page shows a form that was just submitted and refused:
     * then an unchecked box is absent from the old input, not "default".
     */
    public static function submitted(): bool
    {
        $old = old();

        return is_array($old) && array_key_exists('_token', $old);
    }

    /** @param  list<string|null>  $ids */
    public static function describedBy(array $ids): ?string
    {
        $ids = array_values(array_filter($ids));

        return $ids === [] ? null : implode(' ', $ids);
    }
}
