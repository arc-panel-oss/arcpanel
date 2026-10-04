<?php

namespace App\Auth;

/**
 * The legacy page a user lands on after login (web/login/index.php, 367-390).
 */
final class Landing
{
    private const MODULES = [
        'WEB_DOMAINS' => '/list/web/',
        'DNS_DOMAINS' => '/list/dns/',
        'MAIL_DOMAINS' => '/list/mail/',
        'DATABASES' => '/list/db/',
        'CRON_JOBS' => '/list/cron/',
        'BACKUPS' => '/list/backup/',
    ];

    /** @param  array<string, string>  $user  one record of `v-list-user USER json` */
    public static function for(array $user): string
    {
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
