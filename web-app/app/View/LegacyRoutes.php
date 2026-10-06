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
        'terminal.index' => '/list/terminal/',
        'server.services' => '/list/server/',
        'server.settings' => '/edit/server/',
        'server.updates' => '/list/updates/',
        'server.rrd' => '/list/rrd/',
        'packages.index' => '/list/package/',
        'logs.index' => '/list/log/',
        'stats.index' => '/list/stats/',
        'access-keys.index' => '/list/access-key/',
        'ssh-keys.index' => '/list/key/',
        'account.edit' => '/edit/user/',
        'users.create' => '/add/user/',
        'web.apps.install' => '/add/webapp/',
        'web.logs.download' => '/download/web-log/',
    ];

    public static function url(string $name): ?string
    {
        return self::URLS[$name] ?? null;
    }
}
