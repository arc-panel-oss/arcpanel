<?php

namespace App\View;

/**
 * Translations a script needs (resources/js/i18n-keys.json), for <x-ap-i18n>.
 * No full catalog is sent to the browser (docs/design/05 §7).
 */
final class ScriptTranslations
{
    /** @return array<string, string> */
    public static function current(): array
    {
        $file = resource_path('js/i18n-keys.json');
        $keys = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];

        $strings = [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key)) {
                $strings[$key] = __($key);
            }
        }

        return $strings;
    }
}
