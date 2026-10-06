<?php

namespace App\View;

/**
 * Legacy URLs of the pages that have no panel app route yet. Navigation and
 * the command palette link here until the module is migrated (docs/design/10).
 */
final class LegacyRoutes
{
    public const URLS = [
        'users.index' => '/list/user/',
        'server.updates' => '/list/updates/',
        'packages.index' => '/list/package/',
        'account.edit' => '/edit/user/',
        'users.create' => '/add/user/',
        'web.logs.download' => '/download/web-log/',
    ];

    public static function url(string $name): ?string
    {
        return self::URLS[$name] ?? null;
    }
}
