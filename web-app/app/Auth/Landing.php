<?php

namespace App\Auth;

/**
 * Where a user lands after login. With ARCPANEL_APP_PAGES='yes' every role
 * lands on the panel app dashboard (/app/); otherwise the legacy page of
 * web/login/index.php (367-390).
 */
final class Landing
{
    public const APP = '/app/';

    private const MODULES = [
        'WEB_DOMAINS' => '/list/web/',
        'DNS_DOMAINS' => '/list/dns/',
        'MAIL_DOMAINS' => '/list/mail/',
        'DATABASES' => '/list/db/',
        'CRON_JOBS' => '/list/cron/',
        'BACKUPS' => '/list/backup/',
    ];

    /** @param  array<string, string>  $user  one record of `v-list-user USER json` */
    public static function for(array $user, bool $appPages = false): string
    {
        if ($appPages) {
            return self::APP;
        }

        if (($user['ROLE'] ?? '') === Role::Admin->value) {
            return '/list/user/';
        }

        foreach (self::MODULES as $key => $url) {
            if (($user[$key] ?? '0') !== '0') {
                return $url;
            }
        }

        return '/error/';
    }
}
