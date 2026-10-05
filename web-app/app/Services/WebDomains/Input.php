<?php

namespace App\Services\WebDomains;

/**
 * Normalisation of list inputs, as web/edit/web does it: newlines, commas
 * and spaces separate entries.
 */
final class Input
{
    /** @return list<string> */
    public static function list(?string $value): array
    {
        $parts = preg_split('/[\s,]+/', trim((string) $value)) ?: [];

        return array_values(array_unique(array_filter($parts, fn (string $part) => $part !== '')));
    }

    /** Certificate text with Unix line endings (web/edit/web replaces \r\n). */
    public static function pem(?string $value): string
    {
        return trim(str_replace("\r\n", "\n", (string) $value));
    }
}
