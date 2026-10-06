<?php

namespace App\Services\Server;

/**
 * The config editors of web/edit/server/<service> (E7 batch 3 spec §9.1).
 */
final class ServiceConfigs
{
    /** Editor names, as the legacy directories under web/edit/server/. */
    public const EDITORS = [
        'nginx', 'apache2', 'httpd', 'php', 'php-fpm', 'php5-fpm', 'mysql', 'mariadb', 'mysqld', 'postgresql',
        'bind9', 'named', 'dovecot', 'exim', 'exim4', 'clamav-daemon', 'spamassassin', 'spamd', 'proftpd',
        'vsftpd', 'fail2ban', 'ssh', 'hestiaweb', 'cron', 'crond',
    ];

    /**
     * The editor of a service of v-list-sys-services: every PHP-FPM pool
     * opens the PHP editor, as web/list/server does; null when the service
     * has none.
     */
    public static function editorFor(string $service): ?string
    {
        if (preg_match('/^php\d+\.\d+-fpm$/', $service) === 1) {
            return 'php';
        }

        return in_array($service, self::EDITORS, true) ? $service : null;
    }
}
