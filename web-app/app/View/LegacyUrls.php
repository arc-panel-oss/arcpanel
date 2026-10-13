<?php

namespace App\View;

use App\Auth\Principal;

/**
 * Panel routes for the page URLs of the ArcPanel 1.x interface (E8 spec
 * §5.3), so bookmarks and old links keep working (still in 2.1). Ported
 * from arc_route() and arc_redirect_if_migrated() of the removed
 * web/inc/arcpanel-routes.php, plus the Users, Packages, file manager and
 * download pages.
 */
final class LegacyUrls
{
    /** Status reports of web/list/server (?cpu, ?mem …). */
    private const REPORTS = ['cpu', 'mem', 'disk', 'net', 'web', 'dns', 'mail', 'db'];

    /** Pages that need no owner. */
    private const STATIC = [
        '/list/web/' => 'web.domains.index',
        '/add/web/' => 'web.domains.create',
        '/list/db/' => 'db.index',
        '/add/db/' => 'db.create',
        '/list/cron/' => 'cron.index',
        '/add/cron/' => 'cron.create',
        '/list/user/' => 'users.index',
        '/add/user/' => 'users.create',
        '/list/package/' => 'packages.index',
        '/add/package/' => 'packages.create',
        '/list/firewall/' => 'server.firewall',
        '/add/firewall/' => 'server.firewall.create',
        '/list/firewall/ipset/' => 'server.firewall.ipsets',
        '/add/firewall/ipset/' => 'server.firewall.ipsets.create',
        '/list/firewall/banlist/' => 'server.firewall.bans',
        '/add/firewall/banlist/' => 'server.firewall.bans.create',
        '/list/ip/' => 'server.ips',
        '/add/ip/' => 'server.ips.create',
        '/list/terminal/' => 'terminal.index',
        '/edit/server/' => 'server.settings',
        '/list/updates/' => 'server.updates',
        '/fm/' => 'files.index',
    ];

    /**
     * The panel URL for a legacy path and its query string; null when the
     * path is unknown or its parameters are invalid. $principal is null for
     * guests: pages that need an owner then send them to the login page.
     *
     * @param  array<string, mixed>  $query
     */
    public static function target(string $path, array $query, ?Principal $principal): ?string
    {
        $path = self::normalize($path);
        $query = array_map(fn ($value) => is_string($value) ? $value : '', $query);

        if (isset(self::STATIC[$path])) {
            return route(self::STATIC[$path]);
        }

        // E7 batch 3: the config editors of the services
        if (preg_match('#^/edit/server/([a-z0-9-]{2,32})/$#', $path, $editor) === 1) {
            return $editor[1] === 'whitelabel'
                ? route('server.settings.tab', ['tab' => 'white-label'])
                : route('server.services.config', ['service' => $editor[1]]);
        }

        $url = self::withoutOwner($path, $query, $principal);
        if ($url !== false) {
            return $url;
        }

        $owner = self::owner($query, $principal);
        if ($owner === null) {
            return self::needsOwner($path) ? route('login') : null;
        }

        return self::withOwner($path, $query, $owner, $principal);
    }

    /**
     * Pages whose target does not depend on the owner; false for the others.
     *
     * @param  array<string, string>  $query
     */
    private static function withoutOwner(string $path, array $query, ?Principal $principal): string|false|null
    {
        switch ($path) {
            case '/edit/firewall/':
                return self::isId($query['rule'] ?? '') ? route('server.firewall.edit', ['rule' => $query['rule']]) : null;
            case '/edit/ip/':
                $ip = $query['ip'] ?? '';

                return preg_match('/^[0-9A-Fa-f:.]{2,45}$/', $ip) === 1 ? route('server.ips.edit', ['ip' => $ip]) : null;
            case '/edit/package/':
                $package = $query['package'] ?? '';

                return preg_match('/^[[:alnum:]][-.\w]{0,99}$/', $package) === 1 ? route('packages.edit', ['package' => $package]) : null;
            case '/list/stats/':
                // An administrator may name the user, as on the legacy page.
                $user = $query['user'] ?? '';

                return $user !== '' && self::actsAsAdmin($principal)
                    ? route('stats.index', ['owner' => $user])
                    : route('stats.index');
            case '/list/rrd/':
                $period = $query['period'] ?? '';

                return preg_match('/^(daily|weekly|monthly|yearly|biennially|triennially)$/', $period) === 1
                    ? route('server.rrd', ['period' => $period])
                    : route('server.rrd');
            case '/list/server/':
                foreach (self::REPORTS as $kind) {
                    if (array_key_exists($kind, $query)) {
                        return route('server.services.status', ['kind' => $kind]);
                    }
                }

                return route('server.services');
        }

        return false;
    }

    /** @param  array<string, string>  $query */
    private static function withOwner(string $path, array $query, string $owner, ?Principal $principal): ?string
    {
        $domain = $query['domain'] ?? '';

        switch ($path) {
            case '/edit/web/':
                return $domain !== '' ? route('web.domains.edit', ['user' => $owner, 'domain' => $domain]) : null;
            case '/list/web-log/':
            case '/download/web-log/':
                if ($domain === '') {
                    return null;
                }
                $type = ($query['type'] ?? '') === 'error' ? 'error' : 'access';
                $name = $path === '/list/web-log/' ? 'web.domains.logs' : 'web.domains.logs.download';

                return route($name, ['user' => $owner, 'domain' => $domain, 'type' => $type]);
            case '/add/webapp/':
                if ($domain === '') {
                    return null;
                }
                $app = $query['app'] ?? '';
                if ($app === '') {
                    return route('web.domains.install', ['user' => $owner, 'domain' => $domain]);
                }

                return preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $app) === 1
                    ? route('web.domains.install.form', ['user' => $owner, 'domain' => $domain, 'app' => $app])
                    : null;
            case '/generate/ssl/':
                if ($domain === '') {
                    return null;
                }
                if (self::actsAsAdmin($principal) && $domain === gethostname()) {
                    return route('server.settings.tab', ['tab' => 'ssl']);
                }

                return route('web.domains.edit', ['user' => $owner, 'domain' => $domain]).'#ssl';
            case '/edit/db/':
                $database = $query['database'] ?? '';

                return $database !== '' ? route('db.edit', ['user' => $owner, 'database' => $database]) : null;
            case '/edit/cron/':
                return self::isId($query['job'] ?? '') ? route('cron.edit', ['user' => $owner, 'job' => $query['job']]) : null;
            case '/list/dns/':
                if ($domain === '') {
                    return route('dns.index');
                }

                return route(($query['action'] ?? '') !== '' ? 'dns.dnssec' : 'dns.records.index', ['user' => $owner, 'domain' => $domain]);
            case '/add/dns/':
                return $domain === ''
                    ? route('dns.create')
                    : route('dns.records.index', ['user' => $owner, 'domain' => $domain, 'add' => 1]);
            case '/edit/dns/':
                if ($domain === '') {
                    return null;
                }
                $record = $query['record_id'] ?? '';
                if ($record === '') {
                    return route('dns.edit', ['user' => $owner, 'domain' => $domain]);
                }

                return self::isId($record) ? route('dns.records.index', ['user' => $owner, 'domain' => $domain, 'edit' => $record]) : null;
            case '/list/mail/':
                if ($domain === '') {
                    return route('mail.index');
                }

                return route(($query['dns'] ?? '') !== '' ? 'mail.dns' : 'mail.accounts.index', ['user' => $owner, 'domain' => $domain]);
            case '/add/mail/':
                return $domain === ''
                    ? route('mail.create')
                    : route('mail.accounts.create', ['user' => $owner, 'domain' => $domain]);
            case '/edit/mail/':
                if ($domain === '') {
                    return null;
                }
                $account = $query['account'] ?? '';
                if ($account === '') {
                    return route('mail.edit', ['user' => $owner, 'domain' => $domain]);
                }

                return preg_match('/^[\w.-]{1,64}$/', $account) === 1
                    ? route('mail.accounts.edit', ['user' => $owner, 'domain' => $domain, 'account' => $account])
                    : null;
            case '/list/backup/':
                $backup = $query['backup'] ?? '';
                if ($backup === '') {
                    return route('backups.archives.index', ['user' => $owner]);
                }

                return self::isBackup($backup) ? route('backups.archives.show', ['user' => $owner, 'backup' => $backup]) : null;
            case '/download/backup/':
                $backup = $query['backup'] ?? '';

                return self::isBackup($backup) ? route('backups.archives.download', ['user' => $owner, 'backup' => $backup]) : null;
            case '/list/backup/incremental/':
                $snapshot = $query['snapshot'] ?? '';
                if ($snapshot === '') {
                    return route('backups.snapshots.index', ['user' => $owner]);
                }
                if (preg_match('/^[0-9a-f]{8,64}$/', $snapshot) !== 1) {
                    return null;
                }

                return ($query['browse'] ?? '') !== ''
                    ? route('files.show', ['user' => $owner, 'snapshot' => $snapshot])
                    : route('backups.snapshots.show', ['user' => $owner, 'snapshot' => $snapshot]);
            case '/list/backup/exclusions/':
            case '/edit/backup/exclusions/':
                return route('backups.exclusions.edit', ['user' => $owner]);
            case '/list/access-key/':
                $key = $query['key'] ?? '';
                if ($key === '') {
                    return route('access-keys.list', ['user' => $owner]);
                }

                return preg_match('/^[A-Za-z0-9]{20}$/', $key) === 1 ? route('access-keys.show', ['user' => $owner, 'key' => $key]) : null;
            case '/add/access-key/':
                return route('access-keys.create', ['user' => $owner]);
            case '/list/key/':
            case '/add/key/':
                return route('ssh-keys.list', ['user' => $owner]);
            case '/list/log/':
                return $owner === 'system' ? route('logs.index') : route('logs.show', ['user' => $owner]);
            case '/list/log/auth/':
                return route('logs.auth', ['user' => $owner]);
            case '/edit/user/':
                return $owner === $principal?->effectiveUser()
                    ? route('account.edit')
                    : route('users.edit', ['user' => $owner]);
        }

        return null;
    }

    /**
     * Whether the redirect may be permanent (301): its target depends
     * neither on who is logged in nor on their role. Browsers cache a 301,
     * so owner-dependent targets and the login page answer 302.
     */
    public static function permanent(string $path): bool
    {
        $path = self::normalize($path);

        return ! self::needsOwner($path) && $path !== '/list/stats/';
    }

    /** Paths handled by withOwner(). */
    private static function needsOwner(string $path): bool
    {
        return in_array($path, [
            '/edit/web/', '/list/web-log/', '/download/web-log/', '/add/webapp/', '/generate/ssl/',
            '/edit/db/', '/edit/cron/', '/list/dns/', '/add/dns/', '/edit/dns/',
            '/list/mail/', '/add/mail/', '/edit/mail/',
            '/list/backup/', '/download/backup/', '/list/backup/incremental/',
            '/list/backup/exclusions/', '/edit/backup/exclusions/',
            '/list/access-key/', '/add/access-key/', '/list/key/', '/add/key/',
            '/list/log/', '/list/log/auth/', '/edit/user/',
        ], true);
    }

    /**
     * The owner of the object: an administrator who is not impersonating may
     * name it (?user=), everyone else acts as the effective user.
     *
     * @param  array<string, string>  $query
     */
    private static function owner(array $query, ?Principal $principal): ?string
    {
        if ($principal === null) {
            return null;
        }
        $user = $query['user'] ?? '';
        if ($user !== '' && self::actsAsAdmin($principal) && preg_match('/^[[:alnum:]][-.\w]{0,28}[[:alnum:]]$/', $user) === 1) {
            return $user;
        }

        return $principal->effectiveUser();
    }

    /** "list/web/index.php" and "list/web" → "/list/web/". */
    private static function normalize(string $path): string
    {
        $path = preg_replace('#/index\.php$#', '', '/'.trim($path, '/')) ?? $path;

        return '/'.trim($path, '/').'/';
    }

    private static function actsAsAdmin(?Principal $principal): bool
    {
        return $principal !== null && $principal->isAdmin() && ! $principal->isImpersonating();
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^\d{1,9}$/', $value) === 1;
    }

    private static function isBackup(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,250}$/', $value) === 1;
    }
}
