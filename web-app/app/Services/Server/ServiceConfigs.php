<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * The config editors of web/edit/server/<service> (E7 batch 3 spec §9): a
 * whitelist of services, where their files are, and the service argument of
 * `v-change-sys-service-config` per file. Content is read with
 * `v-open-fs-config PATH` and written from a temporary file.
 */
final class ServiceConfigs
{
    /**
     * Editor => definition. files: list of [source, save argument, label]
     * where source is "json:COMMAND:KEY" (CONFIG key of `COMMAND json`, the
     * first path when it lists several), "plain:COMMAND" (first line of
     * `COMMAND plain`) or an absolute path. options: the basic options kind.
     */
    private const EDITORS = [
        'nginx' => ['files' => [['json:v-list-sys-nginx-config:config_path', 'nginx']], 'options' => 'nginx', 'language' => 'nginx'],
        'apache2' => ['files' => [['/etc/apache2/apache2.conf', 'apache2']], 'language' => 'apache'],
        'httpd' => ['files' => [['/etc/httpd/conf/httpd.conf', 'httpd']], 'language' => 'apache'],
        'php' => ['files' => [['json:v-list-sys-php-config:config_path', 'php']], 'options' => 'php', 'language' => 'ini'],
        'php-fpm' => ['alias' => 'php'],
        'php5-fpm' => ['alias' => 'php'],
        'mysql' => ['files' => [['json:v-list-sys-mysql-config:config_path', 'mysql']], 'options' => 'mysql', 'language' => 'ini'],
        'mariadb' => ['files' => [['json:v-list-sys-mysql-config:config_path', 'mariadb']], 'options' => 'mysql', 'language' => 'ini'],
        'mysqld' => ['files' => [['json:v-list-sys-mysql-config:config_path', 'mysqld']], 'options' => 'mysql', 'language' => 'ini'],
        'postgresql' => ['files' => [
            ['json:v-list-sys-pgsql-config:config_path', 'postgresql', 'postgresql.conf'],
            ['json:v-list-sys-pgsql-config:pg_hba_path', 'postgresql-hba', 'pg_hba.conf'],
        ], 'language' => 'ini'],
        'bind9' => ['files' => [['/etc/bind/named.conf', 'bind9'], ['/etc/bind/named.conf.options', 'bind9-opt']]],
        'named' => ['files' => [['/etc/named.conf', 'named']]],
        'dovecot' => ['files' => [
            ['json:v-list-sys-dovecot-config:config_path', 'dovecot'],
            ['json:v-list-sys-dovecot-config:config_path1', 'dovecot-1'],
            ['json:v-list-sys-dovecot-config:config_path2', 'dovecot-2'],
            ['json:v-list-sys-dovecot-config:config_path3', 'dovecot-3'],
            ['json:v-list-sys-dovecot-config:config_path4', 'dovecot-4'],
            ['json:v-list-sys-dovecot-config:config_path5', 'dovecot-5'],
            ['json:v-list-sys-dovecot-config:config_path6', 'dovecot-6'],
            ['json:v-list-sys-dovecot-config:config_path7', 'dovecot-7'],
            ['json:v-list-sys-dovecot-config:config_path8', 'dovecot-8'],
        ]],
        'exim' => ['files' => [['/etc/exim/exim.conf', 'exim']]],
        'exim4' => ['files' => [['/etc/exim4/exim4.conf.template', 'exim4']]],
        'clamav-daemon' => ['files' => [['plain:v-list-sys-clamd-config', 'clamd']]],
        'spamassassin' => ['files' => [['plain:v-list-sys-spamd-config', 'spamassassin']]],
        'spamd' => ['files' => [['plain:v-list-sys-spamd-config', 'spamd']]],
        'proftpd' => ['files' => [['plain:v-list-sys-proftpd-config', 'proftpd']]],
        'vsftpd' => ['files' => [['plain:v-list-sys-vsftpd-config', 'vsftpd']]],
        'fail2ban' => ['files' => [['/etc/fail2ban/jail.local', 'fail2ban']], 'language' => 'ini'],
        'ssh' => ['files' => [['/etc/ssh/sshd_config', 'ssh']]],
        // The crontab of the panel user: always reloaded, as legacy does
        'arcpanelweb' => ['files' => [['/var/spool/cron/crontabs/arcpanelweb', 'arcpanelweb']], 'language' => 'shell', 'restart' => 'always'],
        // Legacy shows the system crontab without a save handler
        'cron' => ['files' => [['/etc/crontab', null]], 'language' => 'shell'],
        'crond' => ['files' => [['/etc/crontab', null]], 'language' => 'shell'],
    ];

    /** Editor names, as the legacy directories under web/edit/server/. */
    public const NAMES = [
        'nginx', 'apache2', 'httpd', 'php', 'php-fpm', 'php5-fpm', 'mysql', 'mariadb', 'mysqld', 'postgresql',
        'bind9', 'named', 'dovecot', 'exim', 'exim4', 'clamav-daemon', 'spamassassin', 'spamd', 'proftpd',
        'vsftpd', 'fail2ban', 'ssh', 'arcpanelweb', 'cron', 'crond',
    ];

    /** @var array<string, array<string, mixed>> per-request cache of JSON configs */
    private array $lists = [];

    public function __construct(private readonly ArcCommand $arc) {}

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

        return in_array($service, self::NAMES, true) ? $service : null;
    }

    public static function exists(string $editor): bool
    {
        return isset(self::EDITORS[$editor]);
    }

    /**
     * The editor with its files and their content; null when the service has
     * no file on this server.
     *
     * @return array{name: string, options: ?string, language: string, alwaysRestart: bool, files: list<array{label: string, path: string, arg: ?string, content: string}>}|null
     */
    public function load(string $editor): ?array
    {
        $definition = self::definition($editor);
        $files = [];
        foreach ($definition['files'] as $index => $file) {
            $path = $this->resolve($file[0]);
            if ($path === '') {
                continue;
            }
            $result = $this->arc->run('v-open-fs-config', [$path], Options::allowFailure());
            if (! $result->ok() && ! self::openedDespiteLogError($result)) {
                continue;
            }
            $files[] = [
                'index' => $index,
                'label' => $file[2] ?? basename($path),
                'path' => $path,
                'arg' => $file[1],
                'content' => $result->stdout,
            ];
        }
        if ($files === []) {
            return null;
        }

        return [
            'name' => $editor,
            'options' => $definition['options'] ?? null,
            'language' => $definition['language'] ?? '',
            'alwaysRestart' => ($definition['restart'] ?? null) === 'always',
            'files' => $files,
        ];
    }

    /**
     * `v-change-sys-service-config <file> ARG yes|no` for one file of the
     * editor; the content goes through a temporary file.
     */
    public function save(string $editor, int $index, string $content, bool $restart): CommandResult
    {
        $definition = self::definition($editor);
        $arg = $definition['files'][$index][1] ?? null;
        if ($arg === null) {
            throw new \InvalidArgumentException("File {$index} of {$editor} cannot be saved.");
        }
        if (($definition['restart'] ?? null) === 'always') {
            $restart = true;
        }
        // The services read LF files; the editor may send CRLF from Windows
        $content = str_replace("\r\n", "\n", $content);

        return $this->arc->run('v-change-sys-service-config', [Secret::file($content), $arg, $restart ? 'yes' : 'no'], new Options(120, true));
    }

    /**
     * Upstream v-open-fs-config does not source func/main.sh, so its last line
     * (`$BIN/v-log-action …`) fails under sudo and the command exits 127 after
     * printing the whole file. Legacy reads it with shell_exec() and ignores
     * the status; the panel accepts exactly that failure.
     */
    private static function openedDespiteLogError(CommandResult $result): bool
    {
        return $result->exitCode === 127 && $result->stdout !== '' && str_contains($result->stderr, '/v-log-action: No such file');
    }

    /** Whether file $index of the editor can be saved. */
    public static function writable(string $editor, int $index): bool
    {
        return (self::definition($editor)['files'][$index][1] ?? null) !== null;
    }

    /** @return array<string, mixed> */
    private static function definition(string $editor): array
    {
        $definition = self::EDITORS[$editor] ?? throw new \InvalidArgumentException("Unknown editor {$editor}");

        return isset($definition['alias']) ? self::EDITORS[$definition['alias']] : $definition;
    }

    private function resolve(string $source): string
    {
        if (str_starts_with($source, 'json:')) {
            [, $command, $key] = explode(':', $source, 3);
            $this->lists[$command] ??= $this->arc->json($command, [], Options::allowFailure());
            $value = trim((string) ($this->lists[$command]['CONFIG'][$key] ?? ''));

            // v-list-sys-php-config lists the php.ini of every version; the
            // command writes them all, the editor shows the first
            return explode(' ', $value)[0];
        }
        if (str_starts_with($source, 'plain:')) {
            $output = $this->arc->run(substr($source, 6), ['plain'], Options::allowFailure());

            return $output->ok() ? trim(strtok($output->stdout, "\n") ?: '') : '';
        }

        return $source;
    }
}
