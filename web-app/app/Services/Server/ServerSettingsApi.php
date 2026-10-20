<?php

namespace App\Services\Server;

use App\Services\WebDomains\Step;
use App\View\WhiteLabel;
use Illuminate\Validation\ValidationException;

/**
 * The server settings of REST API v2 (E34 batch 6 spec §5.7, D58).
 *
 * Reading: each tab answers exactly the fields of its allow-list (READ),
 * built one by one from the settings page's sources; the configuration is
 * never copied as a whole, so `PHPMYADMIN_KEY`, passwords, private keys and
 * repository strings never reach an answer. A secret answers `{"set": bool}`.
 *
 * Writing: a tab takes the fields of WRITE, under the names of the page's
 * form; fields left out keep their value and only changed values run their
 * command, through the plans of ServerSettings and the checks of
 * ServerSettingsActions. The fields of REFUSED (the API switches and the
 * protection group of D58, phpMyAdmin single sign-on and the logo of D23)
 * are refused on every tab.
 */
final class ServerSettingsApi
{
    /**
     * The allow-list of each tab: field => type. `secret` answers
     * `{"set": bool}`; the list types are described in the OpenAPI document.
     */
    public const READ = [
        'general' => [
            'hostname' => 'string', 'timezone' => 'string', 'language' => 'string', 'theme' => 'string',
            'user_theme' => 'boolean', 'debug' => 'boolean', 'experimental' => 'boolean',
            'version' => 'string', 'release_branch' => 'string',
        ],
        'web' => [
            'web_system' => '?string', 'proxy_system' => '?string', 'backend' => '?string',
            'php_default' => '?string', 'php_versions' => 'php_versions',
        ],
        'dns' => ['dns_system' => 'string', 'dnssec' => 'boolean', 'hosts' => 'dns_hosts'],
        'mail' => [
            'mail_system' => 'string', 'imap_system' => '?string', 'antivirus_system' => '?string', 'antispam_system' => '?string',
            'webmail_system' => '?string', 'webmail_alias' => '?string', 'webmail_port' => '?integer', 'webmail_link' => '?string',
            'relay' => 'boolean', 'relay_host' => '?string', 'relay_port' => '?integer', 'relay_user' => '?string', 'relay_password' => 'secret',
        ],
        'databases' => [
            'db_system' => 'string', 'pma_alias' => '?string', 'pga_alias' => '?string', 'db_admin_port' => '?integer',
            'pma_sso' => 'boolean', 'pma_restrict' => 'boolean', 'hosts' => 'database_hosts',
        ],
        'backups' => [
            'directory' => 'string', 'local' => 'boolean', 'mode' => 'string', 'level' => 'integer',
            'remote' => 'boolean', 'remote_type' => '?string', 'host' => '?string', 'port' => '?integer', 'username' => '?string',
            'path' => '?string', 'bucket' => '?string', 'key_id' => '?string', 'password' => 'secret',
            'incremental' => 'boolean', 'repo' => 'secret', 'snapshots' => 'integer',
            'keep_daily' => 'integer', 'keep_weekly' => 'integer', 'keep_monthly' => 'integer', 'keep_yearly' => 'integer',
        ],
        'ssl' => [
            'certificate' => 'string', 'key' => 'secret', 'subject' => 'string', 'aliases' => 'string', 'issuer' => 'string',
            'not_before' => 'string', 'not_after' => 'string', 'signature' => 'string', 'public_key' => 'string',
        ],
        'security' => [
            'API_SYSTEM' => 'string', 'API' => 'string', 'API_V1' => 'string', 'API_ALLOWED_IP' => 'string',
            'LOGIN_STYLE' => 'string', 'INACTIVE_SESSION_TIMEOUT' => 'string', 'POLICY_CSRF_STRICTNESS' => 'string',
            'POLICY_SYSTEM_PASSWORD_RESET' => 'string',
            'POLICY_SYSTEM_PROTECTED_ADMIN' => 'string', 'POLICY_SYSTEM_HIDE_ADMIN' => 'string', 'POLICY_SYSTEM_HIDE_SERVICES' => 'string',
            'POLICY_USER_EDIT_DETAILS' => 'string', 'POLICY_USER_EDIT_WEB_TEMPLATES' => 'string', 'POLICY_USER_EDIT_DNS_TEMPLATES' => 'string',
            'POLICY_USER_VIEW_LOGS' => 'string', 'POLICY_USER_DELETE_LOGS' => 'string', 'POLICY_USER_VIEW_SUSPENDED' => 'string',
            'POLICY_BACKUP_SUSPENDED_USERS' => 'string', 'POLICY_SYNC_ERROR_DOCUMENTS' => 'string', 'POLICY_SYNC_SKELETON' => 'string',
            'ENFORCE_SUBDOMAIN_OWNERSHIP' => 'string', 'POLICY_RESELLER_PACKAGE_SHELLS' => 'string',
        ],
        'plugins' => ['app_installer' => 'boolean', 'plugins' => 'plugins'],
        'white-label' => [
            'APP_NAME' => 'string', 'TITLE' => 'string', 'SUBJECT_EMAIL' => 'string', 'FROM_NAME' => 'string',
            'FROM_EMAIL' => 'string', 'HIDE_DOCS' => 'string', 'custom_logo' => 'boolean',
        ],
    ];

    /** The fields each tab takes; `dns` is read-only. */
    public const WRITE = [
        'general' => ['hostname', 'timezone', 'language', 'language_all', 'theme', 'user_theme', 'debug', 'experimental'],
        'web' => ['php_default'],
        'mail' => ['webmail_alias', 'webmail_port', 'webmail_link', 'relay', 'relay_host', 'relay_port', 'relay_user', 'relay_password'],
        'databases' => ['pma_alias', 'pga_alias', 'db_admin_port'],
        'backups' => [
            'local', 'mode', 'level', 'remote', 'remote_type', 'host', 'port', 'username', 'password', 'path', 'bucket', 'key_id',
            'incremental', 'repo', 'snapshots', 'keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly',
        ],
        'ssl' => ['certificate', 'key'],
        'security' => [
            'LOGIN_STYLE', 'INACTIVE_SESSION_TIMEOUT', 'POLICY_CSRF_STRICTNESS', 'POLICY_SYSTEM_PASSWORD_RESET',
            'POLICY_USER_EDIT_DETAILS', 'POLICY_USER_EDIT_WEB_TEMPLATES', 'POLICY_USER_EDIT_DNS_TEMPLATES',
            'POLICY_USER_VIEW_LOGS', 'POLICY_USER_DELETE_LOGS', 'POLICY_USER_VIEW_SUSPENDED',
            'POLICY_BACKUP_SUSPENDED_USERS', 'POLICY_SYNC_ERROR_DOCUMENTS', 'POLICY_SYNC_SKELETON', 'ENFORCE_SUBDOMAIN_OWNERSHIP',
            'POLICY_RESELLER_PACKAGE_SHELLS',
        ],
        'plugins' => ['app_installer'],
        'white-label' => ['APP_NAME', 'TITLE', 'SUBJECT_EMAIL', 'FROM_NAME', 'FROM_EMAIL', 'HIDE_DOCS'],
    ];

    /**
     * Fields a key may never change (D58, D23), on any tab: `403` naming
     * the field. They stay on the panel's settings page.
     */
    public const REFUSED = [
        'API_SYSTEM' => 'api', 'API' => 'api', 'API_V1' => 'api', 'API_ALLOWED_IP' => 'api',
        'POLICY_SYSTEM_PROTECTED_ADMIN' => 'protection', 'POLICY_SYSTEM_HIDE_ADMIN' => 'protection', 'POLICY_SYSTEM_HIDE_SERVICES' => 'protection',
        'pma_sso' => 'sso', 'pma_restrict' => 'sso', 'PHPMYADMIN_KEY' => 'sso',
        'logo' => 'logo', 'custom_logo' => 'logo',
    ];

    /** Keys of arcpanel.conf with secrets: no allow-list names them (D58). */
    public const NEVER = ['PHPMYADMIN_KEY', 'SERVER_SMTP_PASSWD', 'REPOSITORY'];

    public function __construct(
        private readonly ServerSettings $settings,
        private readonly ServerSettingsActions $actions,
    ) {}

    /** The 403 message of a refused field. */
    public static function refusal(string $field): string
    {
        return sprintf(match (self::REFUSED[$field] ?? '') {
            'api' => __('%s cannot be changed through the API: a key may not switch the API off or open it to more addresses. Change it on the Security tab of the server settings.'),
            'protection' => __('%s cannot be changed through the API: a key may not hide or unprotect the administrator. Change it on the Security tab of the server settings.'),
            'sso' => __('%s cannot be changed through the API: phpMyAdmin single sign-on stays on the Databases tab of the server settings.'),
            default => __('%s cannot be changed through the API: the logo stays on the White Label tab of the server settings.'),
        }, $field);
    }

    /**
     * The fields of a tab's allow-list with their current values.
     *
     * @return array<string, mixed>
     */
    public function read(string $tab): array
    {
        $config = $this->settings->config();
        $get = fn (string $key, string $default = '') => (string) ($config[$key] ?? $default);
        $orNull = fn (string $key) => $get($key) !== '' ? $get($key) : null;

        return match ($tab) {
            'general' => [
                'hostname' => $this->settings->hostname(),
                'timezone' => $this->settings->timezone(),
                'language' => $get('LANGUAGE'),
                'theme' => $get('THEME'),
                'user_theme' => $get('POLICY_USER_CHANGE_THEME', 'yes') !== 'no',
                'debug' => $get('DEBUG_MODE') === 'true',
                'experimental' => $get('POLICY_SYSTEM_ENABLE_BACON') === 'true',
                'version' => $get('VERSION'),
                'release_branch' => $get('RELEASE_BRANCH', 'release'),
            ],
            'web' => $this->readWeb($orNull),
            'dns' => [
                'dns_system' => $get('DNS_SYSTEM'),
                'dnssec' => $get('SUPPORT_DNSSEC') === 'yes',
                'hosts' => array_map(fn (array $host) => [
                    'host' => (string) $host['host'],
                    'port' => self::int($host['PORT'] ?? ''),
                    'type' => (string) ($host['TYPE'] ?? ''),
                    'user' => (string) ($host['USER'] ?? ''),
                    'dns_user' => (string) ($host['DNS_USER'] ?? ''),
                    'suspended' => ($host['SUSPENDED'] ?? '') === 'yes',
                ], $this->settings->dnsHosts()),
            ],
            'mail' => [
                'mail_system' => $get('MAIL_SYSTEM'),
                'imap_system' => $orNull('IMAP_SYSTEM'),
                'antivirus_system' => $orNull('ANTIVIRUS_SYSTEM'),
                'antispam_system' => $orNull('ANTISPAM_SYSTEM'),
                'webmail_system' => $orNull('WEBMAIL_SYSTEM'),
                'webmail_alias' => $get('WEBMAIL_SYSTEM') !== '' ? $get('WEBMAIL_ALIAS') : null,
                'webmail_port' => $get('WEBMAIL_SYSTEM') !== '' ? self::int($get('WEBMAIL_PORT')) : null,
                'webmail_link' => $get('WEBMAIL_SYSTEM') !== '' ? ($get('WEBMAIL_LINK') ?: 'port') : null,
                'relay' => $get('SMTP_RELAY') === 'true',
                'relay_host' => $orNull('SMTP_RELAY_HOST'),
                'relay_port' => self::int($get('SMTP_RELAY_PORT')),
                'relay_user' => $orNull('SMTP_RELAY_USER'),
                // The relay is always written with its password (v-add-sys-smtp-relay)
                'relay_password' => ['set' => $get('SMTP_RELAY') === 'true'],
            ],
            'databases' => [
                'db_system' => $get('DB_SYSTEM'),
                'pma_alias' => $this->dbSystem('mysql') ? $get('DB_PMA_ALIAS') : null,
                'pga_alias' => $this->dbSystem('pgsql') ? $get('DB_PGA_ALIAS') : null,
                'db_admin_port' => self::int($get('DB_ADMIN_PORT')),
                'pma_sso' => $get('PHPMYADMIN_KEY') !== '',
                'pma_restrict' => $get('PMA_RESTRICT_ACCESS') === 'yes',
                'hosts' => array_map(fn (array $host) => [
                    'host' => $host['host'],
                    'type' => $host['type'],
                    'port' => self::int($host['port']),
                    'max_databases' => self::int($host['maxDb']),
                    'databases' => (int) $host['databases'],
                    'suspended' => $host['suspended'],
                ], $this->settings->databaseHosts()),
            ],
            'backups' => $this->readBackups($config),
            'ssl' => $this->readSsl(),
            'security' => array_combine(array_keys(self::READ['security']), array_map(
                fn (string $key) => match ($key) {
                    'API_SYSTEM' => $get($key, '0'),
                    'API_ALLOWED_IP' => $get($key),
                    'LOGIN_STYLE' => $get($key, 'default'),
                    'INACTIVE_SESSION_TIMEOUT' => $get($key, '60'),
                    'POLICY_CSRF_STRICTNESS' => $get($key, '1'),
                    'POLICY_RESELLER_PACKAGE_SHELLS' => $get($key, 'nologin'),
                    default => $get($key) === 'yes' ? 'yes' : 'no',
                },
                array_keys(self::READ['security']),
            )),
            'plugins' => [
                'app_installer' => $get('PLUGIN_APP_INSTALLER', 'true') !== 'false',
                'plugins' => array_map(fn (array $plugin) => $get($plugin[0]) === $plugin[1], ServerSettings::PLUGINS),
            ],
            'white-label' => [
                'APP_NAME' => $get('APP_NAME'),
                'TITLE' => $get('TITLE'),
                'SUBJECT_EMAIL' => $get('SUBJECT_EMAIL'),
                'FROM_NAME' => $get('FROM_NAME'),
                'FROM_EMAIL' => $get('FROM_EMAIL'),
                'HIDE_DOCS' => $get('HIDE_DOCS') === 'yes' ? 'yes' : 'no',
                'custom_logo' => WhiteLabel::customLogo(),
            ],
        };
    }

    /**
     * The steps of a change to a tab: only the fields given, only what
     * changed. Checks that need the server's state throw a validation error.
     *
     * @param  array<string, mixed>  $data  validated fields of WRITE[$tab]
     * @return list<Step>
     */
    public function plan(string $tab, array $data): array
    {
        $given = fn (string $field) => array_key_exists($field, $data);
        $bool = fn (string $field) => $given($field) ? (bool) $data[$field] : null;
        $string = fn (string $field) => $given($field) && $data[$field] !== null ? trim((string) $data[$field]) : null;

        return match ($tab) {
            'general' => $this->planGeneral($data, $bool, $string),
            'web' => $this->planWeb($string('php_default')),
            'mail' => $this->planMail($data, $given, $string),
            'databases' => $this->planDatabases($string('pma_alias'), $string('pga_alias'), $given('db_admin_port') ? self::portValue($data['db_admin_port']) : null),
            'backups' => $this->planBackups($data, $given, $string),
            'ssl' => $this->settings->planSsl((string) $data['certificate'], (string) $data['key']),
            'security' => $this->settings->planPolicies(array_map(fn ($value) => (string) $value, array_intersect_key($data, array_flip(self::WRITE['security'])))),
            'plugins' => $given('app_installer') ? $this->settings->planAppInstaller((bool) $data['app_installer']) : [],
            'white-label' => $this->settings->planWhiteLabel(array_map(fn ($value) => trim((string) $value), array_intersect_key($data, array_flip(self::WRITE['white-label'])))),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function readWeb(\Closure $orNull): array
    {
        $versions = $this->settings->phpVersions();
        $default = array_keys(array_filter($versions, fn (array $v) => $v['default']))[0] ?? null;

        return [
            'web_system' => $orNull('WEB_SYSTEM'),
            'proxy_system' => $orNull('PROXY_SYSTEM'),
            'backend' => $orNull('WEB_BACKEND'),
            'php_default' => $default !== null ? (string) $default : null,
            'php_versions' => array_map(fn (string $version, array $state) => [
                'version' => $version,
                'installed' => $state['installed'],
                'default' => $state['default'],
                'used_by' => $state['usedBy'],
            ], array_map('strval', array_keys($versions)), array_values($versions)),
        ];
    }

    /**
     * @param  array<string, string>  $config
     * @return array<string, mixed>
     */
    private function readBackups(array $config): array
    {
        $type = $this->settings->remoteBackupType($config);
        $remote = $this->settings->backupHost($type);
        $incremental = ($config['BACKUP_INCREMENTAL'] ?? '') === 'yes';
        $restic = $this->settings->restic();
        $orNull = fn (string $value) => $value !== '' ? $value : null;

        return [
            'directory' => (string) ($config['BACKUP'] ?? '/backup'),
            'local' => in_array('local', explode(',', (string) ($config['BACKUP_SYSTEM'] ?? '')), true),
            'mode' => ($config['BACKUP_MODE'] ?? 'gzip') === 'zstd' ? 'zstd' : 'gzip',
            'level' => (int) ($config['BACKUP_GZIP'] ?? 9) ?: 9,
            'remote' => $type !== '',
            'remote_type' => $orNull($type),
            'host' => $orNull($remote['host']),
            'port' => self::int($remote['port']),
            'username' => $orNull($remote['username']),
            'path' => $orNull($remote['path']),
            'bucket' => $orNull($remote['bucket']),
            'key_id' => $orNull($remote['keyId']),
            // ftp and sftp are written with a password, b2 with an application key; rclone has none
            'password' => ['set' => in_array($type, ['ftp', 'sftp', 'b2'], true)],
            'incremental' => $incremental,
            // The repository may hold credentials (D58)
            'repo' => ['set' => $incremental && $restic['repo'] !== ''],
            'snapshots' => (int) $restic['snapshots'],
            'keep_daily' => (int) $restic['daily'],
            'keep_weekly' => (int) $restic['weekly'],
            'keep_monthly' => (int) $restic['monthly'],
            'keep_yearly' => (int) $restic['yearly'],
        ];
    }

    /** @return array<string, mixed> */
    private function readSsl(): array
    {
        [$ssl, $key] = $this->settings->sslWithKeyFlag();

        return [
            'certificate' => $ssl['CRT'] ?? '',
            'key' => ['set' => $key],
            'subject' => $ssl['SUBJECT'] ?? '',
            'aliases' => $ssl['ALIASES'] ?? '',
            'issuer' => $ssl['ISSUER'] ?? '',
            'not_before' => $ssl['NOT_BEFORE'] ?? '',
            'not_after' => $ssl['NOT_AFTER'] ?? '',
            'signature' => $ssl['SIGNATURE'] ?? '',
            'public_key' => $ssl['PUB_KEY'] ?? '',
        ];
    }

    /** @return list<Step> */
    private function planGeneral(array $data, \Closure $bool, \Closure $string): array
    {
        $languageAll = (bool) ($data['language_all'] ?? false);
        $values = [
            'hostname' => $string('hostname'),
            'timezone' => $string('timezone'),
            // "Apply to all users" without a language applies the current one
            'language' => $string('language') ?? ($languageAll ? (string) ($this->settings->config()['LANGUAGE'] ?? '') : null),
            'language_all' => $languageAll,
            'theme' => $string('theme'),
            'user_theme' => $bool('user_theme'),
            'debug' => $bool('debug'),
            'experimental' => $bool('experimental'),
        ];
        $this->actions->checkGeneral(['timezone' => $values['timezone'], 'language' => $values['language'], 'theme' => $values['theme']]);

        return $this->settings->planGeneral($values);
    }

    /** @return list<Step> */
    private function planWeb(?string $default): array
    {
        if ($default === null) {
            return [];
        }
        if (! in_array($default, $this->actions->installedPhp(), true)) {
            throw ValidationException::withMessages(['php_default' => __('This PHP version is not installed.')]);
        }

        return $this->settings->planDefaultPhp($default);
    }

    /** @return list<Step> */
    private function planMail(array $data, \Closure $given, \Closure $string): array
    {
        $config = $this->settings->config();
        $alias = $string('webmail_alias');
        if (($config['WEBMAIL_SYSTEM'] ?? '') === '') {
            $errors = array_filter([
                'webmail_alias' => $alias !== null ? __('This server has no webmail.') : null,
                'webmail_port' => $given('webmail_port') ? __('This server has no webmail.') : null,
                'webmail_link' => $given('webmail_link') ? __('This server has no webmail.') : null,
            ]);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        $values = [
            'webmail_alias' => $alias,
            'webmail_port' => $given('webmail_port') ? self::portValue($data['webmail_port']) : null,
            'webmail_link' => $string('webmail_link'),
            'relay' => $given('relay') ? (bool) $data['relay'] : ($config['SMTP_RELAY'] ?? '') === 'true',
            'relay_host' => $string('relay_host') ?? (string) ($config['SMTP_RELAY_HOST'] ?? ''),
            'relay_port' => $given('relay_port') && $data['relay_port'] !== null ? (string) (int) $data['relay_port'] : (string) (($config['SMTP_RELAY_PORT'] ?? '') ?: '587'),
            'relay_user' => $given('relay_user') ? trim((string) $data['relay_user']) : (string) ($config['SMTP_RELAY_USER'] ?? ''),
            'relay_password' => $given('relay_password') && (string) $data['relay_password'] !== '' ? (string) $data['relay_password'] : null,
        ];
        if ($values['relay'] && $values['relay_host'] === '') {
            throw ValidationException::withMessages(['relay_host' => sprintf(__('Field "%s" can not be blank.'), __('Host'))]);
        }
        $this->actions->checkMail($values);

        return $this->settings->planMail($values);
    }

    /** @return list<Step> */
    private function planDatabases(?string $pma, ?string $pga, ?string $port = null): array
    {
        $errors = array_filter([
            'pma_alias' => $pma !== null && ! $this->dbSystem('mysql') ? __('This server has no MySQL or MariaDB.') : null,
            'pga_alias' => $pga !== null && ! $this->dbSystem('pgsql') ? __('This server has no PostgreSQL.') : null,
            'db_admin_port' => $port !== null && ! $this->dbSystem('mysql') && ! $this->dbSystem('pgsql') ? __('This server has no database server.') : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->settings->planDatabases(['pma_alias' => $pma, 'pga_alias' => $pga, 'pma_sso' => null, 'pma_restrict' => null, 'db_admin_port' => $port]);
    }

    /**
     * The values of the page's Backups form: the given fields over the
     * stored ones (a new remote type starts from empty fields).
     *
     * @return list<Step>
     */
    private function planBackups(array $data, \Closure $given, \Closure $string): array
    {
        $config = $this->settings->config();
        $current = $this->settings->remoteBackupType($config);
        $type = $given('remote_type') ? (string) ($data['remote_type'] ?? '') : $current;
        $stored = $type === $current ? $this->settings->backupHost($current) : ['host' => '', 'port' => '', 'username' => '', 'path' => '', 'bucket' => '', 'keyId' => ''];
        $incremental = ($config['BACKUP_INCREMENTAL'] ?? '') === 'yes';
        $restic = $this->settings->restic();
        $text = fn (string $field, string $stored) => $given($field) ? trim((string) ($data[$field] ?? '')) : $stored;
        $number = fn (string $field, string $stored) => $given($field) && $data[$field] !== null ? (string) (int) $data[$field] : $stored;
        $port = $number('port', $stored['port']);

        $values = [
            'local' => $given('local') ? (bool) $data['local'] : in_array('local', explode(',', (string) ($config['BACKUP_SYSTEM'] ?? '')), true),
            'mode' => $string('mode'),
            'level' => $given('level') ? (int) $data['level'] : null,
            'remote' => $given('remote') ? (bool) $data['remote'] : $current !== '',
            'remote_type' => $type,
            'host' => $text('host', $stored['host']),
            'port' => $port !== '' ? $port : ($type === 'ftp' ? '21' : ($type === 'sftp' ? '22' : '')),
            'username' => $text('username', $stored['username']),
            'path' => $text('path', $stored['path']),
            'bucket' => $text('bucket', $stored['bucket']),
            'key_id' => $text('key_id', $stored['keyId']),
            'remote_secret' => $given('password') && (string) $data['password'] !== '' ? (string) $data['password'] : null,
            'incremental' => $given('incremental') ? (bool) $data['incremental'] : $incremental,
            // The stored repository is used, never shown, when the request leaves it out
            'repo' => $text('repo', $incremental ? $restic['repo'] : ''),
            'snapshots' => $number('snapshots', $restic['snapshots']),
            'keep_daily' => $number('keep_daily', $restic['daily']),
            'keep_weekly' => $number('keep_weekly', $restic['weekly']),
            'keep_monthly' => $number('keep_monthly', $restic['monthly']),
            'keep_yearly' => $number('keep_yearly', $restic['yearly']),
        ];
        $errors = array_filter([
            'remote_type' => $values['remote'] && $type === '' ? sprintf(__('Field "%s" can not be blank.'), __('Protocol')) : null,
            'repo' => $values['incremental'] && $values['repo'] === '' ? sprintf(__('Field "%s" can not be blank.'), __('Repository')) : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->actions->checkBackups($values);

        return $this->settings->planBackups($values);
    }

    private function dbSystem(string $system): bool
    {
        return in_array($system, explode(',', (string) ($this->settings->config()['DB_SYSTEM'] ?? '')), true);
    }

    private static function int(string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** A tool port of the request (E46): the number, or '' (off) for null. */
    private static function portValue(mixed $value): string
    {
        return $value === null ? '' : (string) (int) $value;
    }
}
