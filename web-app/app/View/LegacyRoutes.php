<?php

namespace App\View;

/**
 * Legacy URLs of the pages that have no panel app route yet. Navigation and
 * the command palette link here until the module is migrated (docs/design/10).
 */
final class LegacyRoutes
{
    public const URLS = [
        'web.logs.download' => '/download/web-log/',
    ];

    public static function url(string $name): ?string
    {
        return self::URLS[$name] ?? null;
    }
}
