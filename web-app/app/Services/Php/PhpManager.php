<?php

namespace App\Services\Php;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * The PHP Manager of E41 (docs/features/specs/2026-10-12-001-e41-php-manager-design.md):
 * versions of the catalog, extensions, the php.ini of each version and SAPI,
 * PHP-FPM profiles and the PHP of a web domain. Every read and change is a
 * v-* command; long installs run as the system task.
 */
final class PhpManager
{
    /** The SAPIs whose php.ini the panel edits (D5), PHP-FPM first. */
    public const SAPIS = ['fpm', 'cli'];

    /** Extension groups of the catalog, in page order (§5.1). */
    public const GROUPS = ['cache', 'database', 'image', 'format', 'network', 'security', 'async', 'debug', 'other'];

    /** Directives of the php.ini form (§6.2), in form order. */
    public const FORM_DIRECTIVES = [
        'memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars', 'upload_max_filesize', 'post_max_size',
        'max_file_uploads', 'display_errors', 'error_reporting', 'log_errors', 'date.timezone', 'default_socket_timeout',
        'allow_url_fopen', 'short_open_tag', 'cgi.fix_pathinfo', 'session.gc_maxlifetime', 'opcache.enable',
        'opcache.memory_consumption', 'opcache.max_accelerated_files', 'opcache.validate_timestamps', 'opcache.revalidate_freq',
        'disable_functions',
    ];

    /** Form directives that are On/Off switches. */
    public const BOOLEAN_DIRECTIVES = [
        'display_errors', 'log_errors', 'allow_url_fopen', 'short_open_tag', 'cgi.fix_pathinfo', 'opcache.enable', 'opcache.validate_timestamps',
    ];

    /** Keys of a profile file (§7.1), without NAME and BUILTIN. */
    public const PROFILE_KEYS = [
        'DESCRIPTION', 'PM', 'MAX_CHILDREN', 'START_SERVERS', 'MIN_SPARE', 'MAX_SPARE', 'MAX_REQUESTS', 'IDLE_TIMEOUT',
        'REQUEST_TERMINATE_TIMEOUT', 'MEMORY_LIMIT', 'UPLOAD_MAX_FILESIZE', 'POST_MAX_SIZE', 'MAX_EXECUTION_TIME', 'MAX_INPUT_VARS',
    ];

    public const PROFILE_MANAGERS = ['ondemand', 'dynamic', 'static'];

    /** The shipped profiles from the smallest pool to the largest, the order of the lists */
    public const SHIPPED_PROFILE_ORDER = ['default', 'small', 'medium', 'large'];

    /** Per-domain PHP settings and their kind, in the order of the pool lines (E19 §1) */
    public const DOMAIN_SETTINGS = [
        'memory_limit' => 'size', 'upload_max_filesize' => 'size', 'post_max_size' => 'size', 'max_execution_time' => 'seconds',
        'max_input_vars' => 'count', 'display_errors' => 'flag', 'date.timezone' => 'zone',
    ];

    /** Package maximums of the per-domain PHP settings with the defaults of a package without them (E19 D7) */
    public const SETTING_MAXIMUMS = ['PHP_MAX_MEMORY' => '512M', 'PHP_MAX_UPLOAD' => '256M', 'PHP_MAX_EXECUTION_TIME' => '300', 'PHP_MAX_INPUT_VARS' => '10000'];

    /** @var array<string, array<string, mixed>>|null per request */
    private ?array $versions = null;

    /** @var array<string, array<string, mixed>>|null per request */
    private ?array $profiles = null;

    public function __construct(private readonly ArcCommand $arc) {}

    /** Read the versions and profiles again after a change. */
    public function forget(): void
    {
        $this->versions = null;
        $this->profiles = null;
    }

    public static function validVersion(string $version): bool
    {
        return preg_match('/^\d{1,2}\.\d{1,2}$/', $version) === 1;
    }

    public static function validProfileName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $name) === 1;
    }

    // ---------------------------------------------------------------- versions

    /**
     * Every version of the catalog and every installed one
     * (`v-list-sys-php-versions json`), oldest first.
     *
     * @return array<string, array{version: string, installed: bool, default: bool, usedBy: list<string>, status: string, released: string, activeUntil: string, eol: string, installable: bool, available: bool, extensions: int}>
     */
    public function versions(): array
    {
        if ($this->versions === null) {
            $this->versions = [];
            foreach ($this->arc->json('v-list-sys-php-versions', [], Options::allowFailure()) as $version => $row) {
                if (! is_array($row) || ! self::validVersion((string) $version)) {
                    continue;
                }
                $this->versions[(string) $version] = [
                    'version' => (string) $version,
                    'installed' => ($row['INSTALLED'] ?? '') === 'yes',
                    'default' => ($row['DEFAULT'] ?? '') === 'yes',
                    'usedBy' => array_values(array_filter(explode(',', (string) ($row['USED_BY'] ?? '')))),
                    'status' => (string) ($row['STATUS'] ?? 'unknown'),
                    'released' => (string) ($row['RELEASED'] ?? ''),
                    'activeUntil' => (string) ($row['ACTIVE_UNTIL'] ?? ''),
                    'eol' => (string) ($row['EOL'] ?? ''),
                    'installable' => ($row['INSTALLABLE'] ?? '') === 'yes',
                    'available' => ($row['AVAILABLE'] ?? '') === 'yes',
                    'extensions' => (int) ($row['EXTENSIONS'] ?? 0),
                ];
            }
            uksort($this->versions, 'version_compare');
        }

        return $this->versions;
    }

    /** @return array<string, mixed>|null */
    public function version(string $version): ?array
    {
        return $this->versions()[$version] ?? null;
    }

    /** @return list<string> installed versions, oldest first */
    public function installed(): array
    {
        return array_values(array_map(fn (array $v) => $v['version'], array_filter($this->versions(), fn (array $v) => $v['installed'])));
    }

    public function defaultVersion(): string
    {
        foreach ($this->versions() as $version => $state) {
            if ($state['default']) {
                return $version;
            }
        }

        return '';
    }

    /**
     * `v-start-sys-task php ADD DELETE`: versions to install and to remove.
     *
     * @param  list<string>  $add
     * @param  list<string>  $delete
     */
    public function startVersions(array $add, array $delete): CommandResult
    {
        return $this->arc->run('v-start-sys-task', ['php', self::list($add), self::list($delete)], Options::allowFailure());
    }

    /** `v-change-sys-php VERSION`: the version of the php command and of the default backend template. */
    public function setDefault(string $version): CommandResult
    {
        return $this->arc->run('v-change-sys-php', [$version], new Options(600, true));
    }

    /** `v-restart-web-backend yes VERSION` */
    public function reload(string $version): CommandResult
    {
        return $this->arc->run('v-restart-web-backend', ['yes', $version], new Options(120, true));
    }

    /** Whether the PHP-FPM service of a version runs (`v-list-sys-services json`). */
    public function serviceState(string $version): string
    {
        $services = $this->arc->json('v-list-sys-services', [], Options::allowFailure());
        $row = $services["php{$version}-fpm"] ?? null;

        return is_array($row) ? ((string) ($row['STATE'] ?? '') === 'running' ? 'running' : 'stopped') : 'unknown';
    }

    // -------------------------------------------------------------- extensions

    /**
     * The extensions of an installed version (`v-list-sys-php-extensions VERSION json`).
     *
     * @return array<string, array{name: string, package: string, module: string, group: string, required: bool, conflicts: list<string>, note: string, catalog: bool, toggleOnly: bool, installed: bool, available: bool, fpm: bool, cli: bool, builtin: bool}>
     */
    public function extensions(string $version): array
    {
        $extensions = [];
        foreach ($this->arc->json('v-list-sys-php-extensions', [$version], Options::allowFailure()) as $name => $row) {
            if (! is_array($row)) {
                continue;
            }
            $extensions[(string) $name] = [
                'name' => (string) $name,
                'package' => (string) ($row['PACKAGE'] ?? ''),
                'module' => (string) ($row['MODULE'] ?? $name),
                'group' => in_array($row['GROUP'] ?? '', self::GROUPS, true) ? (string) $row['GROUP'] : 'other',
                'required' => ($row['REQUIRED'] ?? '') === 'yes',
                'conflicts' => array_values(array_filter(explode(',', (string) ($row['CONFLICTS'] ?? '')))),
                'note' => (string) ($row['NOTE'] ?? ''),
                'catalog' => ($row['CATALOG'] ?? '') === 'yes',
                'toggleOnly' => ($row['TOGGLE_ONLY'] ?? '') === 'yes',
                'installed' => ($row['INSTALLED'] ?? '') === 'yes',
                'available' => ($row['AVAILABLE'] ?? '') === 'yes',
                'fpm' => ($row['FPM'] ?? '') === 'on',
                'cli' => ($row['CLI'] ?? '') === 'on',
                // compiled into the binary: always on (E41)
                'builtin' => ($row['BUILTIN'] ?? '') === 'yes',
            ];
        }
        ksort($extensions);

        return $extensions;
    }

    /**
     * The extensions by group, in the order of GROUPS.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupedExtensions(string $version): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach ($this->extensions($version) as $extension) {
            $groups[$extension['group']][] = $extension;
        }

        return array_filter($groups);
    }

    /**
     * `v-start-sys-task php-ext VERSION ADD DELETE`
     *
     * @param  list<string>  $add
     * @param  list<string>  $delete
     */
    public function startExtensions(string $version, array $add, array $delete): CommandResult
    {
        return $this->arc->run('v-start-sys-task', ['php-ext', $version, self::list($add), self::list($delete)], Options::allowFailure());
    }

    /** `v-change-sys-php-extension VERSION NAME on|off [SAPI]` */
    public function toggleExtension(string $version, string $name, bool $on, string $sapi = ''): CommandResult
    {
        $args = [$version, $name, $on ? 'on' : 'off'];
        if ($sapi !== '') {
            $args[] = $sapi;
        }

        return $this->arc->run('v-change-sys-php-extension', $args, new Options(120, true));
    }

    // ----------------------------------------------------------------- php.ini

    /**
     * The form values of a php.ini (`v-list-sys-php-ini VERSION SAPI json`).
     *
     * @return array{file: string, size: int, mtime: string, values: array<string, array{value: string, source: string, line: int, overrides: int}>}|null
     */
    public function ini(string $version, string $sapi): ?array
    {
        $result = $this->arc->run('v-list-sys-php-ini', [$version, $sapi, 'json'], Options::allowFailure());
        $row = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($row)) {
            return null;
        }
        $values = [];
        foreach (self::FORM_DIRECTIVES as $directive) {
            $value = $row['VALUES'][$directive] ?? [];
            $values[$directive] = [
                'value' => (string) ($value['VALUE'] ?? ''),
                'source' => (string) ($value['SOURCE'] ?? 'default'),
                'line' => (int) ($value['LINE'] ?? 0),
                'overrides' => (int) ($value['PROFILE_OVERRIDES'] ?? 0),
            ];
        }

        return [
            'file' => (string) ($row['FILE'] ?? ''),
            'size' => (int) ($row['SIZE'] ?? 0),
            'mtime' => (string) ($row['MTIME'] ?? ''),
            'values' => $values,
        ];
    }

    /** The php.ini itself (`v-list-sys-php-ini VERSION SAPI raw`). */
    public function iniContent(string $version, string $sapi): ?string
    {
        $result = $this->arc->run('v-list-sys-php-ini', [$version, $sapi, 'raw'], Options::allowFailure());

        return $result->ok() ? $result->stdout : null;
    }

    /**
     * Findings of a candidate php.ini (`v-check-sys-php-ini VERSION SAPI FILE json`),
     * or null when the check could not run.
     *
     * @return list<array{line: int, level: string, directive: string, message: string}>|null
     */
    public function checkIni(string $version, string $sapi, string $content): ?array
    {
        $result = $this->arc->run('v-check-sys-php-ini', [$version, $sapi, Secret::file(self::lf($content)), 'json'], new Options(120, true));
        $rows = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($rows)) {
            return null;
        }

        return array_values(array_map(fn ($row) => [
            'line' => (int) ($row['line'] ?? 0),
            'level' => ($row['level'] ?? '') === 'warning' ? 'warning' : 'error',
            'directive' => (string) ($row['directive'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
        ], array_filter($rows, 'is_array')));
    }

    /**
     * Findings of the php.ini that directives of the form would give
     * (`v-check-sys-php-ini VERSION SAPI FILE json values`), or null.
     *
     * @param  array<string, string>  $values  directive => value
     * @return list<array{line: int, level: string, directive: string, message: string}>|null
     */
    public function checkIniValues(string $version, string $sapi, array $values): ?array
    {
        $result = $this->arc->run('v-check-sys-php-ini', [$version, $sapi, Secret::file(self::valuesFile($values)), 'json', 'values'], new Options(120, true));
        $rows = $result->ok() ? json_decode($result->stdout, true) : null;

        return is_array($rows) ? self::findings($rows) : null;
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<array{line: int, level: string, directive: string, message: string}>
     */
    private static function findings(array $rows): array
    {
        return array_values(array_map(fn ($row) => [
            'line' => (int) ($row['line'] ?? 0),
            'level' => ($row['level'] ?? '') === 'warning' ? 'warning' : 'error',
            'directive' => (string) ($row['directive'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
        ], array_filter($rows, 'is_array')));
    }

    /** @param  array<string, string>  $values */
    public static function valuesFile(array $values): string
    {
        $lines = [];
        foreach ($values as $directive => $value) {
            $lines[] = $directive.'='.str_replace(["\r", "\n"], '', $value);
        }

        return implode("\n", $lines)."\n";
    }

    /** `v-change-sys-php-ini VERSION SAPI FILE`: the whole file. */
    public function saveIni(string $version, string $sapi, string $content): CommandResult
    {
        return $this->arc->run('v-change-sys-php-ini', [$version, $sapi, Secret::file(self::lf($content))], new Options(120, true));
    }

    /**
     * `v-change-sys-php-ini-values VERSION SAPI FILE`: directives of the form.
     *
     * @param  array<string, string>  $values  directive => value
     */
    public function saveIniValues(string $version, string $sapi, array $values): CommandResult
    {
        $lines = [];
        foreach ($values as $directive => $value) {
            $lines[] = $directive.'='.str_replace(["\r", "\n"], '', $value);
        }

        return $this->arc->run('v-change-sys-php-ini-values', [$version, $sapi, Secret::file(implode("\n", $lines)."\n")], new Options(120, true));
    }

    /**
     * Backups of a php.ini, newest first (`v-list-sys-php-ini-backups VERSION SAPI json`).
     *
     * @return list<array{id: string, date: string, size: int}>
     */
    public function iniBackups(string $version, string $sapi): array
    {
        $backups = [];
        foreach ($this->arc->json('v-list-sys-php-ini-backups', [$version, $sapi], Options::allowFailure()) as $id => $row) {
            if (is_array($row)) {
                $backups[] = ['id' => (string) $id, 'date' => (string) ($row['DATE'] ?? ''), 'size' => (int) ($row['SIZE'] ?? 0)];
            }
        }
        usort($backups, fn (array $a, array $b) => strnatcmp($b['id'], $a['id']));

        return $backups;
    }

    /** `v-rollback-sys-php-ini VERSION SAPI ID` */
    public function restoreIni(string $version, string $sapi, string $id): CommandResult
    {
        return $this->arc->run('v-rollback-sys-php-ini', [$version, $sapi, $id], new Options(120, true));
    }

    // ---------------------------------------------------------------- profiles

    /**
     * The PHP-FPM profiles (`v-list-sys-php-profiles json`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function profiles(): array
    {
        if ($this->profiles === null) {
            $this->profiles = [];
            foreach ($this->arc->json('v-list-sys-php-profiles', [], Options::allowFailure()) as $name => $row) {
                if (is_array($row) && self::validProfileName((string) $name)) {
                    $this->profiles[(string) $name] = self::profileRow((string) $name, $row);
                }
            }
            // default, the shipped sizes from small to large, then the others by name
            $rank = function (string $name): array {
                $position = array_search($name, self::SHIPPED_PROFILE_ORDER, true);

                return [$position === false ? count(self::SHIPPED_PROFILE_ORDER) : $position, $name];
            };
            uksort($this->profiles, fn (string $a, string $b) => $rank($a) <=> $rank($b));
        }

        return $this->profiles;
    }

    /** @return array<string, mixed>|null */
    public function profile(string $name): ?array
    {
        return self::validProfileName($name) ? ($this->profiles()[$name] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $row  of v-list-sys-php-profiles
     * @return array<string, mixed>
     */
    public static function profileRow(string $name, array $row): array
    {
        $values = [];
        foreach (self::PROFILE_KEYS as $key) {
            $values[$key] = (string) ($row[$key] ?? '');
        }

        return [
            'name' => $name,
            'values' => $values,
            'builtin' => ($row['BUILTIN'] ?? '') === 'yes',
            'domains' => (int) ($row['DOMAINS'] ?? 0),
            'packages' => array_values(array_filter(explode(',', (string) ($row['PACKAGES'] ?? '')))),
        ];
    }

    /** @param  array<string, string>  $values  PROFILE_KEYS => value */
    public function addProfile(string $name, array $values): CommandResult
    {
        return $this->arc->run('v-add-sys-php-profile', [$name, Secret::file(self::profileFile($values))], Options::allowFailure());
    }

    /** @param  array<string, string>  $values  PROFILE_KEYS => value */
    public function changeProfile(string $name, array $values): CommandResult
    {
        return $this->arc->run('v-change-sys-php-profile', [$name, Secret::file(self::profileFile($values))], new Options(300, true));
    }

    public function deleteProfile(string $name, bool $force): CommandResult
    {
        return $this->arc->run('v-delete-sys-php-profile', $force ? [$name, 'yes'] : [$name], new Options(300, true));
    }

    /**
     * A profile file: KEY='value' lines of PROFILE_KEYS.
     *
     * @param  array<string, string>  $values
     */
    public static function profileFile(array $values): string
    {
        $lines = [];
        foreach (self::PROFILE_KEYS as $key) {
            $lines[] = $key."='".str_replace(["'", "\n", "\r", '\\'], '', (string) ($values[$key] ?? ''))."'";
        }

        return implode("\n", $lines)."\n";
    }

    // ----------------------------------------------------------------- domains

    /**
     * The PHP of a web domain (`v-list-web-domain-php USER DOMAIN json`), or
     * null without a PHP-FPM backend.
     *
     * @return array{version: string, backend: string, profile: string, profileApplied: bool, versionAllowed: bool, profileAllowed: bool, allowed: bool, package: string, defaultProfile: string, versions: array<string, array{status: string, eol: string}>, profiles: list<string>, effective: array<string, array{value: string, source: string}>}|null
     */
    public function domain(string $user, string $domain): ?array
    {
        $result = $this->arc->run('v-list-web-domain-php', [$user, $domain, 'json'], Options::allowFailure());
        $row = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($row)) {
            return null;
        }
        $versions = [];
        foreach ((array) ($row['VERSIONS'] ?? []) as $version => $state) {
            $versions[(string) $version] = ['status' => (string) ($state['STATUS'] ?? ''), 'eol' => (string) ($state['EOL'] ?? '')];
        }
        $effective = [];
        foreach ((array) ($row['EFFECTIVE'] ?? []) as $directive => $value) {
            $effective[(string) $directive] = ['value' => (string) ($value['VALUE'] ?? ''), 'source' => (string) ($value['SOURCE'] ?? '')];
        }

        return [
            'version' => (string) ($row['VERSION'] ?? ''),
            'backend' => (string) ($row['BACKEND'] ?? ''),
            'profile' => (string) ($row['PROFILE'] ?? 'default'),
            'profileApplied' => ($row['PROFILE_APPLIED'] ?? '') === 'yes',
            'versionAllowed' => ($row['VERSION_ALLOWED'] ?? '') !== 'no',
            'profileAllowed' => ($row['PROFILE_ALLOWED'] ?? '') !== 'no',
            'allowed' => ($row['ALLOWED'] ?? '') !== 'no',
            'package' => (string) ($row['PACKAGE'] ?? ''),
            'defaultProfile' => (string) ($row['DEFAULT_PROFILE'] ?? 'default'),
            'versions' => $versions,
            'profiles' => array_values(array_map('strval', (array) ($row['PROFILES'] ?? []))),
            'effective' => $effective,
        ];
    }

    /** `v-change-web-domain-php USER DOMAIN VERSION PROFILE` */
    public function setDomain(string $user, string $domain, string $version, string $profile): CommandResult
    {
        return $this->arc->run('v-change-web-domain-php', [$user, $domain, $version, $profile], new Options(300, true));
    }

    /**
     * The per-domain PHP settings of a web domain (E19 §5,
     * `v-list-web-domain-php-settings USER DOMAIN json`), or null when the
     * command fails.
     *
     * @return array{pool: string, supported: bool, nginxMaxBody: string, settings: array<string, array{value: string, max: string, ini: string, profile: string, effective: string, source: string}>, warnings: list<string>}|null
     */
    public function settings(string $user, string $domain): ?array
    {
        $result = $this->arc->run('v-list-web-domain-php-settings', [$user, $domain, 'json'], Options::allowFailure());
        $row = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($row)) {
            return null;
        }
        $settings = [];
        foreach (array_keys(self::DOMAIN_SETTINGS) as $directive) {
            $value = (array) ($row['SETTINGS'][$directive] ?? []);
            $settings[$directive] = [
                'value' => (string) ($value['VALUE'] ?? ''), 'max' => (string) ($value['MAX'] ?? ''), 'ini' => (string) ($value['INI'] ?? ''),
                'profile' => (string) ($value['PROFILE'] ?? ''), 'effective' => (string) ($value['EFFECTIVE'] ?? ''), 'source' => (string) ($value['SOURCE'] ?? ''),
            ];
        }

        return [
            'pool' => (string) ($row['POOL'] ?? 'domain'),
            'supported' => ($row['SUPPORTED'] ?? '') === 'yes',
            'nginxMaxBody' => (string) ($row['NGINX_MAX_BODY'] ?? ''),
            'settings' => $settings,
            'warnings' => array_values(array_map('strval', (array) ($row['WARNINGS'] ?? []))),
        ];
    }

    /** `v-change-web-domain-php-setting USER DOMAIN DIRECTIVE VALUE RESTART` */
    public function setSetting(string $user, string $domain, string $directive, string $value, bool $reload = true): CommandResult
    {
        return $this->arc->run('v-change-web-domain-php-setting', [$user, $domain, $directive, $value, $reload ? 'yes' : 'no'], new Options(120, true));
    }

    /** `v-delete-web-domain-php-setting USER DOMAIN DIRECTIVE|all [RESTART]`; all resets every setting with one reload */
    public function resetSetting(string $user, string $domain, string $directive, bool $reload = true): CommandResult
    {
        $args = $directive === 'all' ? [$user, $domain, 'all'] : [$user, $domain, $directive, $reload ? 'yes' : 'no'];

        return $this->arc->run('v-delete-web-domain-php-setting', $args, new Options(120, true));
    }

    /**
     * Errors of wanted settings against the package maximums and the pair
     * rule (E19 §5.1): post_max_size below upload_max_filesize when the
     * domain sets both. An empty value resets and is never an error.
     *
     * @param  array<string, array{value: string, max: string}>  $settings  of settings()
     * @param  array<string, string>  $wanted  directive => value
     * @return array<string, string> directive => message
     */
    public static function settingErrors(array $settings, array $wanted): array
    {
        $errors = [];
        foreach ($wanted as $directive => $value) {
            $max = $settings[$directive]['max'] ?? '';
            if ($value === '' || $max === '' || $max === 'unlimited') {
                continue;
            }
            $above = self::DOMAIN_SETTINGS[$directive] === 'size'
                ? (self::sizeBytes($value) ?? 0) > (self::sizeBytes($max) ?? PHP_INT_MAX)
                : (int) $value > (int) $max;
            if ($above) {
                $errors[$directive] = sprintf(__('At most %s on the package of this domain.'), $max);
            }
        }
        $upload = $wanted['upload_max_filesize'] ?? ($settings['upload_max_filesize']['value'] ?? '');
        $post = $wanted['post_max_size'] ?? ($settings['post_max_size']['value'] ?? '');
        if ($upload !== '' && $post !== '' && (self::sizeBytes($post) ?? 0) < (self::sizeBytes($upload) ?? 0)) {
            $errors['post_max_size'] ??= sprintf(__('At least upload_max_filesize (%s).'), $upload);
        }

        return $errors;
    }

    /**
     * The commands that bring a domain from its settings to the wanted ones:
     * [directive, value] to set, [directive, null] to reset, in the order of
     * the pool lines, except that post_max_size goes first when
     * upload_max_filesize grows beyond the domain's post_max_size, so the
     * pair rule holds after each command.
     *
     * @param  array<string, array{value: string}>  $settings  of settings()
     * @param  array<string, string>  $wanted  directive => value, '' resets
     * @return list<array{0: string, 1: string|null}>
     */
    public static function settingsPlan(array $settings, array $wanted): array
    {
        $plan = [];
        foreach (array_keys(self::DOMAIN_SETTINGS) as $directive) {
            if (! array_key_exists($directive, $wanted) || $wanted[$directive] === ($settings[$directive]['value'] ?? '')) {
                continue;
            }
            $plan[$directive] = $wanted[$directive] === '' ? null : $wanted[$directive];
        }
        $post = $settings['post_max_size']['value'] ?? '';
        $upload = $plan['upload_max_filesize'] ?? null;
        if ($upload !== null && $post !== '' && array_key_exists('post_max_size', $plan)
            && (self::sizeBytes($upload) ?? 0) > (self::sizeBytes($post) ?? 0)) {
            $ordered = [];
            foreach ($plan as $directive => $value) {
                if ($directive === 'upload_max_filesize') {
                    $ordered['post_max_size'] = $plan['post_max_size'];
                }
                $ordered[$directive] = $value;
            }
            $plan = $ordered;
        }

        return array_map(fn (string $directive) => [$directive, $plan[$directive]], array_keys($plan));
    }

    /**
     * Run a plan of settingsPlan(), the last command with the PHP-FPM
     * reload; stops at the first failure.
     *
     * @param  list<array{0: string, 1: string|null}>  $plan
     */
    public function applySettings(string $user, string $domain, array $plan): CommandResult
    {
        $result = null;
        foreach ($plan as $i => [$directive, $value]) {
            $reload = $i === count($plan) - 1;
            $result = $value === null ? $this->resetSetting($user, $domain, $directive, $reload) : $this->setSetting($user, $domain, $directive, $value, $reload);
            if (! $result->ok()) {
                break;
            }
        }

        return $result ?? new CommandResult('v-change-web-domain-php-setting', [], 0, '');
    }

    /** Bytes of a PHP size such as 128M, or null when it is not one. */
    public static function sizeBytes(string $value): ?int
    {
        if (preg_match('/^(\d{1,12})([KMGkmg]?)$/D', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1024 ** ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3][strtoupper($m[2])];
    }

    // ---------------------------------------------------------------- packages

    /**
     * The PHP keys of a package sidecar (§7.5) with their defaults.
     *
     * @param  array<string, mixed>  $row  of v-list-package-tenancy
     * @return array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string, PHP_MAX_MEMORY: string, PHP_MAX_UPLOAD: string, PHP_MAX_EXECUTION_TIME: string, PHP_MAX_INPUT_VARS: string}
     */
    public static function packageKeys(array $row): array
    {
        $keys = [
            'PHP_VERSIONS' => (string) (($row['PHP_VERSIONS'] ?? '') ?: '*'),
            'PHP_PROFILES' => (string) (($row['PHP_PROFILES'] ?? '') ?: 'default'),
            'PHP_DEFAULT_PROFILE' => (string) (($row['PHP_DEFAULT_PROFILE'] ?? '') ?: 'default'),
        ];
        foreach (self::SETTING_MAXIMUMS as $key => $default) {
            $keys[$key] = (string) (($row[$key] ?? '') ?: $default);
        }

        return $keys;
    }

    /**
     * The PHP keys of a package (`v-list-package-tenancy PACKAGE json`).
     *
     * @return array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string, PHP_MAX_MEMORY: string, PHP_MAX_UPLOAD: string, PHP_MAX_EXECUTION_TIME: string, PHP_MAX_INPUT_VARS: string}
     */
    public function packageOf(string $package): array
    {
        $rows = $package === '' ? [] : $this->arc->json('v-list-package-tenancy', [$package], Options::allowFailure());

        return self::packageKeys(is_array($rows[$package] ?? null) ? $rows[$package] : []);
    }

    /**
     * Whether a package allows a version (§7.5): `*` means every installed
     * version that has not reached its end of life; a list names versions.
     *
     * @param  array{PHP_VERSIONS: string}  $keys
     */
    public function packageAllowsVersion(array $keys, string $version): bool
    {
        if ($keys['PHP_VERSIONS'] !== '*') {
            return self::allows($keys['PHP_VERSIONS'], $version);
        }
        $state = $this->version($version);

        return $state !== null && $state['installed'] && $state['status'] !== 'eol';
    }

    /**
     * Whether a backend template is allowed by a package: a template that
     * names a version (PHP-X_Y, also at the end of a custom name) only for an
     * allowed version; any other template is not about the PHP version.
     *
     * @param  array{PHP_VERSIONS: string}  $keys
     */
    public function templateAllowed(string $template, array $keys): bool
    {
        if (preg_match('/PHP-(\d{1,2})_(\d{1,2})$/', $template, $m) !== 1) {
            return true;
        }

        return $this->packageAllowsVersion($keys, $m[1].'.'.$m[2]);
    }

    /** Whether a package value allows an item: `*` or a comma list. */
    public static function allows(string $list, string $item): bool
    {
        return $list === '*' || in_array($item, explode(',', $list), true);
    }

    /** @param  list<string>  $items */
    private static function list(array $items): string
    {
        return $items === [] ? '-' : implode(',', $items);
    }

    private static function lf(string $content): string
    {
        return str_replace("\r\n", "\n", $content);
    }
}
