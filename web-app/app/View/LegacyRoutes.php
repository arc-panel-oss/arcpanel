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
        'dns.index' => '/list/dns/',
        'mail.index' => '/list/mail/',
        'backups.index' => '/list/backup/',
        'terminal.index' => '/list/terminal/',
        'server.services' => '/list/server/',
        'server.settings' => '/edit/server/',
        'server.ips' => '/list/ip/',
        'server.firewall' => '/list/firewall/',
        'server.updates' => '/list/updates/',
        'server.rrd' => '/list/rrd/',
        'packages.index' => '/list/package/',
        'logs.index' => '/list/log/',
        'stats.index' => '/list/stats/',
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
