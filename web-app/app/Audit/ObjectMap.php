<?php

namespace App\Audit;

use Illuminate\Routing\Route;

/**
 * The `object` of an audit event from the route parameters (E23 spec §3):
 * `type` from the last parameter that names an object, `user` from
 * `{user}`, `name` from the other object parameters joined with `/`
 * (`example.com/info` for a mail account). A route without object
 * parameters has no object.
 */
final class ObjectMap
{
    /** Parameters that choose an action or a part, never an object. */
    public const MODIFIERS = ['action', 'app', 'direction', 'index', 'tab', 'legacy'];

    /** Object type of a parameter, whatever the module. */
    private const TYPES = [
        'user' => 'user',
        'account' => 'mail_account',
        'alias' => 'web_alias',
        'backup' => 'backup',
        'bucket' => 'bucket',
        'chain' => 'firewall_chain',
        'database' => 'database',
        'destination' => 'backup_destination',
        'finding' => 'malware_finding',
        'host' => 'database_host',
        'ip' => 'ip',
        'job' => 'cron_job',
        'package' => 'package',
        'php' => 'php_version',
        'plugin' => 'plugin',
        'profile' => 'php_profile',
        'range' => 'port_range',
        'record' => 'dns_record',
        'rule' => 'firewall_rule',
        'sapi' => 'php_ini',
        'service' => 'service',
        'sha256' => 'malware_ignore',
        'slug' => 'system_package',
        'snapshot' => 'snapshot',
        'ticket' => 'file_download',
        'token' => 'file_upload',
    ];

    /** Object type of a parameter in one module (the first segment of the route name). */
    private const MODULE_TYPES = [
        'access-keys.key' => 'access_key',
        'app-processes.domain' => 'web_domain',
        'app-processes.name' => 'app_process',
        'backup-jobs.job' => 'backup_job',
        'backups.job' => 'backup_job',
        'cron-jobs.id' => 'cron_job',
        'dns.domain' => 'dns_domain',
        'dns-zones.domain' => 'dns_domain',
        'dns-zones.id' => 'dns_record',
        'docker.name' => 'docker_app',
        'docker-apps.name' => 'docker_app',
        'files.id' => 'file_fetch',
        'firewall.ip' => 'firewall_ban',
        'firewall.name' => 'firewall_ipset',
        'mail.domain' => 'mail_domain',
        'mail-domains.domain' => 'mail_domain',
        'malware.id' => 'malware_quarantine',
        'node.domain' => 'node_app',
        'node.name' => 'node_package',
        'node.package' => 'node_package',
        'node.version' => 'node_version',
        'node-apps.domain' => 'node_app',
        'notifications.id' => 'notification',
        'php.backup' => 'php_ini_backup',
        'php.name' => 'php_extension',
        'php.version' => 'php_version',
        's3-keys.key' => 'storage_key',
        'secrets.name' => 'secret',
        'shell.id' => 'notification',
        'settings.type' => 'database_type',
        'ssh-keys.id' => 'ssh_key',
        'storage.key' => 'storage_key',
        'updates.id' => 'update',
        'web.domain' => 'web_domain',
        'web-domains.domain' => 'web_domain',
    ];

    /**
     * The module of a route name: `api.v2.` and the `server.` / `system.`
     * area are skipped (`server.php.ini.values` → `php`).
     */
    public static function module(string $name): string
    {
        $parts = explode('.', $name);
        if (($parts[0] ?? '') === 'api' && ($parts[1] ?? '') === 'v2') {
            $parts = array_slice($parts, 2);
        }
        if (in_array($parts[0] ?? '', ['server', 'system'], true) && count($parts) > 1) {
            $parts = array_slice($parts, 1);
        }

        return $parts[0] ?? '';
    }

    /** The object type of one parameter of a route, or null when none is known. */
    public static function typeOf(string $routeName, string $parameter): ?string
    {
        return self::MODULE_TYPES[self::module($routeName).'.'.$parameter] ?? self::TYPES[$parameter] ?? null;
    }

    /**
     * The parameters of a route that name objects, in path order.
     *
     * @return list<string>
     */
    public static function objectParameters(Route $route): array
    {
        return array_values(array_filter($route->parameterNames(), fn (string $name) => ! in_array($name, self::MODIFIERS, true)));
    }

    /** @return array{type: string, user: ?string, name: ?string}|null */
    public static function of(?Route $route): ?array
    {
        if ($route === null) {
            return null;
        }
        $names = self::objectParameters($route);
        if ($names === []) {
            return null;
        }
        $values = [];
        foreach ($names as $name) {
            $value = $route->parameter($name);
            $values[$name] = is_scalar($value) ? (string) $value : null;
        }
        $last = $names[array_key_last($names)];
        $user = $values['user'] ?? null;
        $parts = array_values(array_filter(
            array_map(fn (string $name) => $name === 'user' ? null : $values[$name], $names),
            fn (?string $value) => $value !== null && $value !== '',
        ));

        return [
            'type' => self::typeOf((string) $route->getName(), $last) ?? 'unknown',
            'user' => $user,
            'name' => $parts === [] ? $user : mb_substr(implode('/', $parts), 0, 255),
        ];
    }
}
