<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Validation\ValidationException;

/**
 * The server settings of web/edit/server, one tab at a time (E7 batch 3 spec
 * §8). Every plan compares the form with a fresh `v-list-sys-config` and
 * holds only the commands of changed values, in the legacy order.
 */
final class ServerSettings
{
    public const TABS = ['general', 'web', 'dns', 'mail', 'databases', 'backups', 'ssl', 'security', 'plugins', 'white-label'];

    /**
     * Plugin => [arcpanel.conf key, value when installed]; installed and
     * removed by the system task. The firewall is installed with nftables, or
     * with iptables on a server whose 2.3 upgrade could not move its rules.
     *
     * @var array<string, array{0: string, 1: string|list<string>}>
     */
    public const PLUGINS = [
        'filemanager' => ['FILE_MANAGER', 'true'],
        'web-terminal' => ['WEB_TERMINAL', 'true'],
        'cgroups' => ['RESOURCES_LIMIT', 'yes'],
        'quota' => ['DISK_QUOTA', 'yes'],
        'firewall' => ['FIREWALL_SYSTEM', ['nftables', 'iptables']],
        'malware-scan' => ['MALWARE_SCAN', 'yes'],
        'silo' => ['OBJECT_STORAGE_SYSTEM', 'silo'],
    ];

    /** yes/no policies of the Security tab, by group. */
    public const SECURITY_POLICIES = [
        'login' => ['POLICY_SYSTEM_PASSWORD_RESET'],
        'protection' => ['POLICY_SYSTEM_PROTECTED_ADMIN', 'POLICY_SYSTEM_HIDE_ADMIN', 'POLICY_SYSTEM_HIDE_SERVICES'],
        'users' => [
            'POLICY_USER_EDIT_DETAILS', 'POLICY_USER_EDIT_WEB_TEMPLATES', 'POLICY_USER_EDIT_DNS_TEMPLATES',
            'POLICY_USER_VIEW_LOGS', 'POLICY_USER_DELETE_LOGS', 'POLICY_USER_VIEW_SUSPENDED',
            'POLICY_BACKUP_SUSPENDED_USERS', 'POLICY_SYNC_ERROR_DOCUMENTS', 'POLICY_SYNC_SKELETON',
        ],
        'domains' => ['ENFORCE_SUBDOMAIN_OWNERSHIP'],
    ];

    public const WHITE_LABEL_KEYS = ['APP_NAME', 'TITLE', 'SUBJECT_EMAIL', 'FROM_NAME', 'FROM_EMAIL', 'HIDE_DOCS'];

    public const REMOTE_BACKUPS = ['ftp', 'sftp', 'b2', 'rclone'];

    /** The restic repository of an S3 backup host, the rclone remote of v-add-backup-host-s3 (E44 spec §9). */
    public const S3_REPO = 'rclone:arcpanel-s3:';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly PlanRunner $runner,
    ) {}

    /** arcpanel.conf as it is now (not the cached copy). */
    public function config(): array
    {
        $this->config->forget();

        return $this->config->all();
    }

    /** Whether a tab applies to this server (DNS, mail and databases need their system). */
    public function available(string $tab): bool
    {
        return match ($tab) {
            'dns' => $this->config->get('DNS_SYSTEM') !== '',
            'mail' => $this->config->get('MAIL_SYSTEM') !== '',
            'databases' => $this->config->get('DB_SYSTEM') !== '',
            default => in_array($tab, self::TABS, true),
        };
    }

    /** @return list<string> */
    public function tabs(): array
    {
        return array_values(array_filter(self::TABS, fn (string $tab) => $this->available($tab)));
    }

    // Read side

    public function hostname(): string
    {
        return (string) ($this->arc->json('v-list-sys-info')['sysinfo']['HOSTNAME'] ?? '');
    }

    public function timezone(): string
    {
        return trim($this->arc->run('v-get-sys-timezone')->stdout);
    }

    /** @return list<string> */
    public function timezones(): array
    {
        return array_values(array_filter($this->arc->json('v-get-sys-timezones'), 'is_string'));
    }

    /** @return list<string> */
    public function languages(): array
    {
        return array_values(array_filter($this->arc->json('v-list-sys-languages'), 'is_string'));
    }

    /** @return list<string> */
    public function themes(): array
    {
        return array_values(array_filter($this->arc->json('v-list-sys-themes'), 'is_string'));
    }

    /**
     * Supported PHP versions with their state (`v-list-sys-php-versions`).
     *
     * @return array<string, array{installed: bool, default: bool, usedBy: list<string>}>
     */
    public function phpVersions(): array
    {
        $versions = [];
        foreach ($this->arc->json('v-list-sys-php-versions') as $version => $row) {
            // Versions at their end of life stay listed in the catalog (E41 D3);
            // only installed ones are versions of this server
            if (is_array($row) && (($row['INSTALLED'] ?? '') === 'yes' || ($row['INSTALLABLE'] ?? 'yes') !== 'no')) {
                $versions[(string) $version] = [
                    'installed' => ($row['INSTALLED'] ?? '') === 'yes',
                    'default' => ($row['DEFAULT'] ?? '') === 'yes',
                    'usedBy' => array_values(array_filter(explode(',', (string) ($row['USED_BY'] ?? '')))),
                ];
            }
        }

        return $versions;
    }

    /** @return list<array<string, string>> */
    public function dnsHosts(): array
    {
        $hosts = [];
        foreach ($this->jsonOrEmpty('v-list-remote-dns-hosts') as $host => $row) {
            if (is_array($row)) {
                $hosts[] = ['host' => (string) $host] + array_map('strval', $row);
            }
        }

        return $hosts;
    }

    /** @return list<array{host: string, type: string, port: string, maxDb: string, databases: string, suspended: bool}> */
    public function databaseHosts(): array
    {
        $hosts = [];
        foreach ($this->arc->json('v-list-database-hosts', [], Options::allowFailure()) as $row) {
            if (is_array($row)) {
                $hosts[] = [
                    'host' => (string) ($row['HOST'] ?? ''),
                    'type' => (string) ($row['TYPE'] ?? ''),
                    'port' => (string) ($row['PORT'] ?? ''),
                    'maxDb' => (string) ($row['MAX_DB'] ?? ''),
                    'databases' => (string) ($row['U_DB_BASES'] ?? ''),
                    'suspended' => ($row['SUSPENDED'] ?? '') === 'yes',
                ];
            }
        }

        return $hosts;
    }

    /** The configured remote backup type (ftp, sftp, b2, rclone) or ''. */
    public function remoteBackupType(array $config): string
    {
        foreach (explode(',', $config['BACKUP_SYSTEM'] ?? '') as $type) {
            if (in_array($type, self::REMOTE_BACKUPS, true)) {
                return $type;
            }
        }

        return '';
    }

    /**
     * The remote backup host without its secret (`v-list-backup-host TYPE json`).
     *
     * @return array{type: string, host: string, port: string, username: string, path: string, bucket: string, keyId: string}
     */
    public function backupHost(string $type): array
    {
        $row = $type === '' ? [] : ($this->jsonOrEmpty('v-list-backup-host', [$type])[$type] ?? []);

        return [
            'type' => $type,
            'host' => (string) ($row['HOST'] ?? ''),
            'port' => (string) ($row['PORT'] ?? ''),
            'username' => (string) ($row['USERNAME'] ?? ''),
            'path' => (string) ($row['BPATH'] ?? ''),
            'bucket' => (string) ($row['BUCKET'] ?? ''),
            'keyId' => (string) ($row['B2_KEY_ID'] ?? ''),
        ];
    }

    /**
     * The restic repository and its retention; for an S3 backup host (E44
     * spec §9) also the endpoint, region and access key ID, never the secret.
     *
     * @return array{repo: string, snapshots: string, daily: string, weekly: string, monthly: string, yearly: string, s3: array{endpoint: string, bucket: string, region: string, keyId: string}|null}
     */
    public function restic(): array
    {
        $row = $this->jsonOrEmpty('v-list-backup-host-restic')['restic'] ?? [];
        $repo = (string) ($row['REPO'] ?? '');

        return [
            'repo' => $repo,
            'snapshots' => (string) ($row['SNAPSHOTS'] ?? '30'),
            'daily' => (string) ($row['KEEP_DAILY'] ?? '-1'),
            'weekly' => (string) ($row['KEEP_WEEKLY'] ?? '-1'),
            'monthly' => (string) ($row['KEEP_MONTHLY'] ?? '-1'),
            'yearly' => (string) ($row['KEEP_YEARLY'] ?? '-1'),
            's3' => str_starts_with($repo, self::S3_REPO) ? [
                'endpoint' => (string) ($row['S3_ENDPOINT'] ?? ''),
                'bucket' => substr($repo, strlen(self::S3_REPO)),
                'region' => (string) ($row['S3_REGION'] ?? '') ?: 'us-east-1',
                'keyId' => (string) ($row['S3_KEY_ID'] ?? ''),
            ] : null,
        ];
    }

    /**
     * Whether the panel certificate signs itself (issuer = subject), so that
     * the Security tab can tell that HSTS needs another certificate first;
     * v-change-sys-arcpanel-hsts checks the trust itself (E39 W5 §9.1).
     */
    public function panelCertificateSelfSigned(): bool
    {
        $ssl = $this->ssl();

        return ($ssl['ISSUER'] ?? '') !== '' && ($ssl['ISSUER'] ?? '') === ($ssl['SUBJECT'] ?? '');
    }

    /** The panel certificate without its key (`v-list-sys-arcpanel-ssl json`). @return array<string, string> */
    public function ssl(): array
    {
        return $this->sslWithKeyFlag()[0];
    }

    /**
     * The panel certificate without its key, and whether a key is stored.
     *
     * @return array{0: array<string, string>, 1: bool}
     */
    public function sslWithKeyFlag(): array
    {
        $row = $this->arc->json('v-list-sys-arcpanel-ssl', [], Options::allowFailure())['ARCPANEL'] ?? [];
        $row = is_array($row) ? $row : [];
        $key = trim((string) ($row['KEY'] ?? '')) !== '';
        unset($row['KEY']);

        return [array_map('strval', $row), $key];
    }

    // Plans

    /**
     * A null value was not given (API v2) and changes nothing.
     *
     * @param  array{hostname: ?string, timezone: ?string, language: ?string, language_all: bool, theme: ?string, user_theme: ?bool, debug: ?bool, experimental: ?bool}  $values
     * @return list<Step>
     */
    public function planGeneral(array $values): array
    {
        $config = $this->config();
        $steps = [];
        if ($values['hostname'] !== null && $values['hostname'] !== $this->hostname()) {
            $steps[] = new Step(__('Change the host name'), 'v-change-sys-hostname', [$values['hostname']]);
        }
        if ($values['timezone'] !== null && $values['timezone'] !== $this->timezone()) {
            $steps[] = new Step(__('Change the time zone'), 'v-change-sys-timezone', [$values['timezone']]);
        }
        if ($values['language'] !== null && ($values['language'] !== ($config['LANGUAGE'] ?? '') || $values['language_all'])) {
            $steps[] = new Step(__('Change the language'), 'v-change-sys-language', $values['language_all'] ? [$values['language'], 'yes'] : [$values['language']]);
        }
        $flag = fn (?bool $on, string $yes, string $no) => $on === null ? null : ($on ? $yes : $no);
        $steps = [...$steps, ...self::configSteps($config, [
            'THEME' => $values['theme'],
            'POLICY_USER_CHANGE_THEME' => $flag($values['user_theme'], 'yes', 'no'),
            'DEBUG_MODE' => $flag($values['debug'], 'true', 'false'),
            'POLICY_SYSTEM_ENABLE_BACON' => $flag($values['experimental'], 'true', 'false'),
        ])];
        // Turning the experimental features off hides suspended objects again (legacy L506)
        if ($values['experimental'] === false && ($config['POLICY_SYSTEM_ENABLE_BACON'] ?? '') === 'true' && ($config['POLICY_USER_VIEW_SUSPENDED'] ?? '') !== 'no') {
            $steps[] = self::configStep('POLICY_USER_VIEW_SUSPENDED', 'no');
        }

        return $steps;
    }

    /** @return list<Step> */
    public function planDefaultPhp(string $version): array
    {
        $default = array_keys(array_filter($this->phpVersions(), fn ($v) => $v['default']))[0] ?? '';

        return $version === $default ? [] : [new Step(__('Change the default PHP version'), 'v-change-sys-php', [$version])];
    }

    /**
     * The PHP versions to install and to remove for the checked versions; locked
     * versions (default or in use) are never removed.
     *
     * @param  list<string>  $checked
     * @return array{add: list<string>, delete: list<string>}
     */
    public function phpChanges(array $checked): array
    {
        $add = [];
        $delete = [];
        foreach ($this->phpVersions() as $version => $state) {
            $wanted = in_array($version, $checked, true);
            if ($wanted && ! $state['installed']) {
                $add[] = $version;
            } elseif (! $wanted && $state['installed'] && ! $state['default'] && $state['usedBy'] === []) {
                $delete[] = $version;
            }
        }

        return ['add' => $add, 'delete' => $delete];
    }

    /**
     * @param  array{webmail_alias: ?string, webmail_port?: ?string, webmail_link?: ?string, relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: ?string}  $values  webmail_port '' turns the port off (E46)
     * @return list<Step>
     */
    public function planMail(array $values): array
    {
        $config = $this->config();
        $steps = [];
        $webmail = ($config['WEBMAIL_SYSTEM'] ?? '') !== '';
        if ($values['webmail_alias'] !== null && $webmail && $values['webmail_alias'] !== ($config['WEBMAIL_ALIAS'] ?? '')) {
            $steps[] = new Step(__('Change the webmail alias'), 'v-change-sys-webmail', [$values['webmail_alias']]);
        }
        $port = $values['webmail_port'] ?? null;
        if ($port !== null && $webmail && $port !== ($config['WEBMAIL_PORT'] ?? '')) {
            $steps[] = new Step(__('Change the webmail port'), 'v-change-sys-webmail-port', [$port !== '' ? $port : 'none']);
        }
        $link = $values['webmail_link'] ?? null;
        if ($link !== null && $webmail && $link !== (($config['WEBMAIL_LINK'] ?? '') ?: 'port')) {
            $steps[] = new Step(__('Change the webmail link'), 'v-change-sys-webmail-link', [$link]);
        }
        $relayOn = ($config['SMTP_RELAY'] ?? '') === 'true';
        if ($values['relay'] && $values['relay_password'] !== null) {
            $steps[] = new Step(__('Change the SMTP relay'), 'v-add-sys-smtp-relay', [
                $values['relay_host'], $values['relay_user'], Secret::file($values['relay_password']), $values['relay_port'],
            ]);
        } elseif (! $values['relay'] && $relayOn) {
            $steps[] = new Step(__('Turn off the SMTP relay'), 'v-delete-sys-smtp-relay', []);
        }

        return $steps;
    }

    /** Whether the relay must be written again (on, or host, port or user changed): the password is then required. */
    public function relayNeedsPassword(bool $relay, string $host, string $port, string $user): bool
    {
        $config = $this->config();
        if (! $relay) {
            return false;
        }

        return ($config['SMTP_RELAY'] ?? '') !== 'true'
            || $host !== ($config['SMTP_RELAY_HOST'] ?? '')
            || $port !== ($config['SMTP_RELAY_PORT'] ?? '')
            || $user !== ($config['SMTP_RELAY_USER'] ?? '');
    }

    /**
     * @param  array{pma_alias: ?string, pga_alias: ?string, pma_sso: ?bool, pma_restrict: ?bool, db_admin_port?: ?string}  $values  null: not shown on this server; db_admin_port '' turns the port off (E46)
     * @return list<Step>
     */
    public function planDatabases(array $values): array
    {
        $config = $this->config();
        $steps = [];
        $port = $values['db_admin_port'] ?? null;
        if ($port !== null && $port !== ($config['DB_ADMIN_PORT'] ?? '')) {
            $steps[] = new Step(__('Change the database tools port'), 'v-change-sys-db-admin-port', [$port !== '' ? $port : 'none']);
        }
        if ($values['pma_alias'] !== null && $values['pma_alias'] !== ($config['DB_PMA_ALIAS'] ?? '')) {
            $steps[] = new Step(__('Change the phpMyAdmin alias'), 'v-change-sys-db-alias', ['pma', $values['pma_alias']]);
        }
        if ($values['pga_alias'] !== null && $values['pga_alias'] !== ($config['DB_PGA_ALIAS'] ?? '')) {
            $steps[] = new Step(__('Change the phpPgAdmin alias'), 'v-change-sys-db-alias', ['pga', $values['pga_alias']]);
        }
        $sso = ($config['PHPMYADMIN_KEY'] ?? '') !== '';
        if ($values['pma_sso'] !== null && $values['pma_sso'] !== $sso) {
            $steps[] = $values['pma_sso']
                ? new Step(__('Turn on phpMyAdmin single sign-on'), 'v-add-sys-pma-sso', ['quiet'])
                : new Step(__('Turn off phpMyAdmin single sign-on'), 'v-delete-sys-pma-sso', ['quiet']);
        }
        $restrict = ($config['PMA_RESTRICT_ACCESS'] ?? '') === 'yes';
        if ($values['pma_restrict'] !== null && $values['pma_restrict'] !== $restrict) {
            $steps[] = $values['pma_restrict']
                ? new Step(__('Restrict phpMyAdmin access'), 'v-add-sys-pma-restrict', ['quiet'])
                : new Step(__('Lift the phpMyAdmin restriction'), 'v-delete-sys-pma-restrict', ['quiet']);
        }

        return $steps;
    }

    /** @return list<Step> */
    public function planDatabaseHostPassword(string $type, string $host, string $user, string $password): array
    {
        return [new Step(sprintf(__('Change the password of %s'), $user.'@'.$host), 'v-change-database-host-password', [$type, $host, $user, Secret::file($password)])];
    }

    /**
     * @param  array<string, mixed>  $values  BackupSettingsRequest::values()
     * @return list<Step>
     */
    public function planBackups(array $values): array
    {
        $config = $this->config();
        $systems = array_filter(explode(',', $config['BACKUP_SYSTEM'] ?? ''));
        $steps = [];

        $local = in_array('local', $systems, true);
        if ($values['local'] && ! $local) {
            $steps[] = new Step(__('Turn on local backups'), 'v-add-backup-host', ['local']);
        } elseif (! $values['local'] && $local) {
            $steps[] = new Step(__('Turn off local backups'), 'v-delete-backup-host', ['local']);
        }
        // gzip always uses level 9, as legacy does; a null mode or level was not given (API v2)
        $mode = $values['mode'] ?? (($config['BACKUP_MODE'] ?? '') === 'zstd' ? 'zstd' : 'gzip');
        $level = $values['level'] ?? null;
        $steps = [...$steps, ...self::configSteps($config, [
            'BACKUP_MODE' => $values['mode'] ?? null,
            'BACKUP_GZIP' => ($values['mode'] ?? null) === null && $level === null ? null : ($mode === 'gzip' ? '9' : ($level === null ? null : (string) $level)),
        ])];

        $current = $this->remoteBackupType($config);
        if (! $values['remote']) {
            if ($current !== '') {
                $steps[] = new Step(__('Turn off remote backups'), 'v-delete-backup-host', [$current]);
            }
        } elseif ($this->remoteChanged($current, $values)) {
            if ($current !== '' && $current !== $values['remote_type']) {
                $steps[] = new Step(__('Turn off remote backups'), 'v-delete-backup-host', [$current]);
            }
            $steps[] = new Step(__('Change the remote backup host'), 'v-add-backup-host', self::remoteArgs($values));
        }

        return [...$steps, ...$this->incrementalSteps($config, $values)];
    }

    /**
     * Steps of the incremental backup host: a repository path or URL, or an
     * S3 backup host (E44 spec §9), whose connection is written again only
     * when it changes, and then with its secret.
     *
     * @param  array<string, string>  $config
     * @param  array<string, mixed>  $values
     * @return list<Step>
     *
     * @throws ValidationException
     */
    private function incrementalSteps(array $config, array $values): array
    {
        $incremental = ($config['BACKUP_INCREMENTAL'] ?? '') === 'yes';
        $stored = $incremental ? $this->restic() : null;
        $off = new Step(__('Turn off incremental backups'), ($stored['s3'] ?? null) !== null ? 'v-delete-backup-host-s3' : 'v-delete-backup-host-restic', []);
        if (! $values['incremental']) {
            return $incremental ? [$off] : [];
        }
        $retention = [$values['snapshots'], $values['keep_daily'], $values['keep_weekly'], $values['keep_monthly'], $values['keep_yearly']];
        $storedRetention = $stored !== null ? [$stored['snapshots'], $stored['daily'], $stored['weekly'], $stored['monthly'], $stored['yearly']] : null;

        if (($values['repo_type'] ?? 'path') === 's3') {
            $s3 = ['endpoint' => $values['s3_endpoint'], 'bucket' => $values['s3_bucket'], 'region' => $values['s3_region'], 'keyId' => $values['s3_key_id']];
            if ($values['s3_secret'] === null && ($stored['s3'] ?? null) === $s3) {
                // The same connection: only the retention may change, and v-add-backup-host-restic keeps the connection
                return $storedRetention === $retention ? [] : [new Step(__('Change the incremental backup repository'), 'v-add-backup-host-restic',
                    [self::S3_REPO.$s3['bucket'], ...$retention])];
            }
            if ($values['s3_secret'] === null) {
                throw ValidationException::withMessages(['s3_secret' => __('Enter the secret access key: the connection changes.')]);
            }
            $credentials = sprintf("AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\n", $s3['keyId'], $values['s3_secret']);

            return [
                ...($incremental && ($stored['s3'] ?? null) === null ? [$off] : []),
                new Step(__('Send incremental backups to S3'), 'v-add-backup-host-s3',
                    [$s3['endpoint'], $s3['bucket'], Secret::file($credentials), $s3['region'], ...$retention]),
            ];
        }

        if ($stored !== null && $stored['repo'] === $values['repo'] && $storedRetention === $retention) {
            return [];
        }
        // The repository may hold credentials: masked in every log
        $add = new Step(__('Change the incremental backup repository'), 'v-add-backup-host-restic', [Secret::inline($values['repo']), ...$retention]);
        if ($stored !== null && $stored['s3'] !== null && $stored['repo'] === $values['repo']) {
            // The S3 repository given as a URL (API v2): its connection stays
            return [$add];
        }

        return [...($incremental ? [$off] : []), $add];
    }

    /** Whether the remote backup must be written again; a secret is then required (except rclone). */
    public function remoteChanged(string $current, array $values): bool
    {
        if ($values['remote_secret'] !== null) {
            return true;
        }
        if ($current !== $values['remote_type']) {
            return true;
        }
        $stored = $this->backupHost($current);

        return match ($current) {
            'b2' => $values['bucket'] !== $stored['bucket'] || $values['key_id'] !== $stored['keyId'],
            'rclone' => $values['host'] !== $stored['host'] || $values['path'] !== $stored['path'],
            default => $values['host'] !== $stored['host'] || $values['port'] !== $stored['port']
                || $values['username'] !== $stored['username'] || $values['path'] !== $stored['path'],
        };
    }

    /** @return list<string|Secret> */
    private static function remoteArgs(array $values): array
    {
        return match ($values['remote_type']) {
            'b2' => ['b2', $values['bucket'], $values['key_id'], Secret::file((string) $values['remote_secret'])],
            'rclone' => ['rclone', $values['host'], '', '', $values['path']],
            default => [$values['remote_type'], $values['host'], $values['username'], Secret::file((string) $values['remote_secret']), $values['path'], $values['port']],
        };
    }

    /** @return list<Step> */
    public function planSsl(string $certificate, string $key): array
    {
        return [new Step(__('Change the panel certificate'), 'v-change-sys-arcpanel-ssl', [Secret::directory([
            'certificate.crt' => self::pem($certificate),
            'certificate.key' => self::pem($key),
        ])])];
    }

    /**
     * @param  array<string, string>  $values  SecuritySettingsRequest::values()
     * @return list<Step>
     */
    public function planSecurity(array $values, bool $rootUser): array
    {
        $config = $this->config();
        $steps = [];

        // A form without API_ALLOWED_IP keeps the current list
        $api = ['API_SYSTEM' => $values['API_SYSTEM'], 'API' => $values['API'], 'API_V1' => $values['API_V1'] ?? $config['API_V1'] ?? 'no',
            'API_ALLOWED_IP' => $values['API_ALLOWED_IP'] ?? $config['API_ALLOWED_IP'] ?? ''];
        $apiChanged = array_filter($api, fn ($value, $key) => $value !== ($config[$key] ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($apiChanged !== []) {
            if ($api['API_SYSTEM'] === '0' && $api['API'] === 'no' && $api['API_V1'] === 'no') {
                // disable turns API, API_V1, API_SYSTEM and API_ALLOWED_IP off at once
                $steps[] = new Step(__('Turn off the API'), 'v-change-sys-api', ['disable']);
            } else {
                $steps[] = new Step(__('Turn on the API'), 'v-change-sys-api', ['enable']);
                $steps = [...$steps, ...self::configSteps($config, $api)];
            }
        }

        $keys = ['LOGIN_STYLE', 'INACTIVE_SESSION_TIMEOUT', 'POLICY_CSRF_STRICTNESS', 'POLICY_RESELLER_PACKAGE_SHELLS', ...self::SECURITY_POLICIES['login'],
            ...self::SECURITY_POLICIES['users'], ...self::SECURITY_POLICIES['domains']];
        if ($rootUser) {
            $keys = [...$keys, ...self::SECURITY_POLICIES['protection']];
            // HSTS of the panel: its own command, which refuses an untrusted certificate (E39 W5 §9.1)
            $hsts = $values['PANEL_HSTS'] ?? null;
            if ($hsts !== null && $hsts !== (($config['PANEL_HSTS'] ?? '') === 'yes' ? 'yes' : 'no')) {
                $steps[] = new Step($hsts === 'yes' ? __('Turn HSTS of the panel on') : __('Turn HSTS of the panel off'), 'v-change-sys-arcpanel-hsts', [$hsts]);
            }
        }
        $wanted = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $wanted[$key] = $values[$key];
            }
        }

        return [...$steps, ...self::configSteps($config, $wanted)];
    }

    /**
     * Security keys of API v2 (E34 batch 6 D58): only the given keys, never
     * the API switches or the protection group, which the API refuses.
     *
     * @param  array<string, string>  $values
     * @return list<Step>
     */
    public function planPolicies(array $values): array
    {
        $refused = ['API_SYSTEM', 'API', 'API_V1', 'API_ALLOWED_IP', ...self::SECURITY_POLICIES['protection']];

        return self::configSteps($this->config(), array_diff_key($values, array_flip($refused)));
    }

    /** The upgrade e-mails of the Updates page (spec D11); null leaves a value alone (API v2). @return list<Step> */
    public function planUpdateNotifications(?bool $email, ?bool $log): array
    {
        return self::configSteps($this->config(), [
            'UPGRADE_SEND_EMAIL' => $email === null ? null : ($email ? 'true' : 'false'),
            'UPGRADE_SEND_EMAIL_LOG' => $log === null ? null : ($log ? 'true' : 'false'),
        ]);
    }

    /** @return list<Step> */
    public function planAppInstaller(bool $on): array
    {
        return self::configSteps($this->config(), ['PLUGIN_APP_INSTALLER' => $on ? 'true' : 'false']);
    }

    /**
     * @param  array<string, string>  $values  WHITE_LABEL_KEYS => value
     * @return list<Step>
     */
    public function planWhiteLabel(array $values): array
    {
        return self::configSteps($this->config(), $values);
    }

    /** Whether a plugin is installed now. */
    public function pluginInstalled(string $plugin): bool
    {
        return self::pluginOn($plugin, $this->config->get(self::PLUGINS[$plugin][0]));
    }

    /** Whether a value of the plugin's arcpanel.conf key means installed. */
    public static function pluginOn(string $plugin, ?string $value): bool
    {
        return in_array($value, (array) self::PLUGINS[$plugin][1], true);
    }

    /** `v-update-white-label-logo` (apply the custom logos) or `… yes yes` (restore the defaults). */
    public function updateLogo(bool $restore): ApplyResult
    {
        return $this->apply([$restore
            ? new Step(__('Restore the default logo'), 'v-update-white-label-logo', ['yes', 'yes'])
            : new Step(__('Apply the custom logo'), 'v-update-white-label-logo', [])]);
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $result = $this->runner->apply($steps);
        $this->config->forget();

        return $result;
    }

    /**
     * One `v-change-sys-config-value` step per changed key; a null value
     * was not given (API v2 changes only the fields it receives).
     *
     * @param  array<string, string>  $config
     * @param  array<string, string|null>  $values
     * @return list<Step>
     */
    private static function configSteps(array $config, array $values): array
    {
        $steps = [];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== ($config[$key] ?? '')) {
                $steps[] = self::configStep($key, $value);
            }
        }

        return $steps;
    }

    private static function configStep(string $key, string $value): Step
    {
        return new Step(sprintf(__('Change %s'), $key), 'v-change-sys-config-value', [$key, $value]);
    }

    /**
     * JSON of a list command that prints nothing when there is nothing to list
     * (remote DNS hosts, backup hosts).
     *
     * @param  list<string>  $args
     * @return array<mixed>
     */
    private function jsonOrEmpty(string $command, array $args = []): array
    {
        $result = $this->arc->run($command, [...$args, 'json'], Options::allowFailure());
        if (! $result->ok() || trim($result->stdout) === '') {
            return [];
        }
        $data = json_decode($result->stdout, true);

        return is_array($data) ? $data : [];
    }

    /** PEM text with LF line ends and one final line break. */
    private static function pem(string $text): string
    {
        return rtrim(str_replace("\r\n", "\n", $text))."\n";
    }
}
