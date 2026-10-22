<?php

use App\Arc\ExitCode;
use App\Services\Server\ServerSettingsApi;

// REST API v2, server settings (E34 batch 6 spec §5.7, D58). Every secret of
// the fixtures holds "SECRET-", so an answer that leaks one is caught by a
// plain search of its JSON. Nothing here changes a server: the fake answers.

/** arcpanel.conf of these tests: every secret is recognisable. */
function settingsApiConfig(array $overrides = []): array
{
    return array_merge([
        'SMTP_RELAY' => 'true', 'SMTP_RELAY_HOST' => 'smtp.example.net', 'SMTP_RELAY_PORT' => '587', 'SMTP_RELAY_USER' => 'relay-user',
        'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail', 'IMAP_SYSTEM' => 'dovecot', 'ANTISPAM_SYSTEM' => 'spamassassin', 'ANTIVIRUS_SYSTEM' => '',
        'DB_SYSTEM' => 'mysql,pgsql', 'DB_PMA_ALIAS' => 'phpmyadmin', 'DB_PGA_ALIAS' => 'phppgadmin', 'PMA_RESTRICT_ACCESS' => 'no',
        'PHPMYADMIN_KEY' => 'SECRET-PMA-KEY-7781',
        'SERVER_SMTP_PASSWD' => 'SECRET-SMTP-PASSWD-4410',
        'REPOSITORY' => 'https://mirror:SECRET-REPO-CRED-1188@apt.example.net',
        'SOME_FUTURE_SECRET' => 'SECRET-UNLISTED-0001',
        'BACKUP' => '/backup', 'BACKUP_SYSTEM' => 'local,sftp', 'BACKUP_MODE' => 'zstd', 'BACKUP_GZIP' => '4', 'BACKUP_INCREMENTAL' => 'yes',
        'API_SYSTEM' => '2', 'API' => 'no', 'API_ALLOWED_IP' => 'allow-all', 'LOGIN_STYLE' => 'default', 'POLICY_CSRF_STRICTNESS' => '1',
        'POLICY_USER_EDIT_DETAILS' => 'yes', 'POLICY_USER_VIEW_LOGS' => 'yes', 'POLICY_USER_DELETE_LOGS' => 'yes',
        'POLICY_SYSTEM_HIDE_ADMIN' => 'no', 'POLICY_SYSTEM_ENABLE_BACON' => 'false', 'ENFORCE_SUBDOMAIN_OWNERSHIP' => 'yes',
        'RESOURCES_LIMIT' => 'no', 'DISK_QUOTA' => 'no', 'SUPPORT_DNSSEC' => 'yes', 'RELEASE_BRANCH' => 'release',
        'TITLE' => '{{page}} - {{hostname}} - {{appname}}', 'SUBJECT_EMAIL' => '{{subject}}', 'HIDE_DOCS' => 'no', 'FROM_NAME' => '', 'FROM_EMAIL' => '',
    ], $overrides);
}

const SETTINGS_TASK = '17286400004321';

beforeEach(function () {
    $this->fake = fakeApi(settingsApiConfig());
    fakeResellers($this->fake);
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net', 'UPTIME' => '10', 'LOADAVERAGE' => '0 / 0 / 0']]);
    $this->fake->on('v-get-sys-timezone')->returns("Europe/Berlin\n");
    $this->fake->on('v-get-sys-timezones')->returnsJson(['Asia/Bangkok', 'Europe/Berlin', 'UTC']);
    $this->fake->on('v-list-sys-languages')->returnsJson(['en', 'de', 'vi']);
    $this->fake->on('v-list-sys-themes')->returnsJson(['dark', 'default']);
    $this->fake->on('v-list-sys-php-versions')->returnsJson([
        '8.2' => ['INSTALLED' => 'no', 'DEFAULT' => 'no', 'USED_BY' => ''],
        '8.3' => ['INSTALLED' => 'yes', 'DEFAULT' => 'no', 'USED_BY' => ''],
        '8.4' => ['INSTALLED' => 'yes', 'DEFAULT' => 'no', 'USED_BY' => 'bob/shop.example'],
        '8.5' => ['INSTALLED' => 'yes', 'DEFAULT' => 'yes', 'USED_BY' => ''],
    ]);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    // The list commands do not print these passwords; the fixtures do, to prove the allow-lists drop them
    $this->fake->on('v-list-remote-dns-hosts')->returnsJson(['ns2.example.net' => [
        'PORT' => '8083', 'TYPE' => 'api', 'USER' => 'cluster', 'DNS_USER' => 'dns-sync', 'PASSWORD' => 'SECRET-DNS-PASS-8814', 'SUSPENDED' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-01',
    ]]);
    $this->fake->on('v-list-database-hosts')->returnsJson([
        ['HOST' => 'localhost', 'PORT' => '3306', 'TYPE' => 'mysql', 'MAX_DB' => '500', 'U_DB_BASES' => '3', 'SUSPENDED' => 'no', 'PASSWORD' => 'SECRET-DBHOST-PASS-6620'],
        ['HOST' => 'pg.example.net', 'PORT' => '5432', 'TYPE' => 'pgsql', 'MAX_DB' => '100', 'U_DB_BASES' => '0', 'SUSPENDED' => 'no'],
    ]);
    $this->fake->on('v-list-backup-host', ['sftp', 'json'])->returnsJson(['sftp' => [
        'HOST' => 'backup.example.net', 'USERNAME' => 'arc', 'PORT' => '22', 'TYPE' => 'sftp', 'BPATH' => '/srv/backup', 'PASSWORD' => 'SECRET-BACKUP-PASS-3307',
    ]]);
    $this->fake->on('v-list-backup-host-restic')->returnsJson(['restic' => [
        'REPO' => 'rest:https://backup:SECRET-RESTIC-9902@restic.example.net/arc', 'SNAPSHOTS' => '30',
        'KEEP_DAILY' => '7', 'KEEP_WEEKLY' => '-1', 'KEEP_MONTHLY' => '-1', 'KEEP_YEARLY' => '-1',
    ]]);
    $this->fake->on('v-list-sys-arcpanel-ssl')->returnsJson(['ARCPANEL' => [
        'CRT' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", 'KEY' => "-----BEGIN PRIVATE KEY-----\nSECRET-SSL-KEY-5531\n-----END PRIVATE KEY-----",
        'SUBJECT' => 'srv1.example.net', 'ALIASES' => 'srv1.example.net', 'NOT_BEFORE' => 'Oct 4 2026', 'NOT_AFTER' => 'Oct 4 2027',
        'SIGNATURE' => 'ecdsa-with-SHA256', 'PUB_KEY' => '256 bit', 'ISSUER' => 'Example CA',
    ]]);
    $this->fake->on('v-start-sys-task')->returns(SETTINGS_TASK."\n");
    foreach ([
        'v-change-sys-config-value', 'v-change-sys-hostname', 'v-change-sys-timezone', 'v-change-sys-language', 'v-change-sys-php',
        'v-change-sys-webmail', 'v-add-sys-smtp-relay', 'v-delete-sys-smtp-relay', 'v-change-sys-db-alias', 'v-change-database-host-password',
        'v-add-backup-host', 'v-delete-backup-host', 'v-add-backup-host-restic', 'v-delete-backup-host-restic', 'v-change-sys-arcpanel-ssl',
        'v-change-sys-api', 'v-add-sys-pma-sso', 'v-delete-sys-pma-sso', 'v-update-white-label-logo',
    ] as $command) {
        $this->fake->on($command)->returns();
    }
});

/** Commands that change the server, none of which may run after a refusal. */
function settingsWriteCommands(): array
{
    return ['v-change-sys-config-value', 'v-change-sys-hostname', 'v-change-sys-api', 'v-add-sys-smtp-relay', 'v-add-backup-host',
        'v-change-sys-arcpanel-ssl', 'v-start-sys-task', 'v-change-database-host-password', 'v-add-sys-pma-sso', 'v-update-white-label-logo'];
}

function assertNoSettingsChange($fake): void
{
    foreach (settingsWriteCommands() as $command) {
        $fake->assertNotRan($command);
    }
}

// Reading (D58)

it('answers the settings to administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/settings', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        expect(array_keys($response->json('data')))->toBe(['general', 'web', 'dns', 'mail', 'databases', 'backups', 'ssl', 'security', 'plugins', 'white-label']);
    } else {
        $response->assertJsonPath('error.code', 'E_FORBIDDEN');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('answers only the allow-list of a tab, without any secret or PHPMYADMIN_KEY (D58)', function (string $tab) {
    $response = $this->getJson('/api/v2/system/settings', apiAs($this->fake, 'arcadmin', 'admin', area: 'server'))->assertOk();
    $json = (string) json_encode($response->json('data.'.$tab));

    expect(array_keys($response->json('data.'.$tab)))->toBe(array_keys(ServerSettingsApi::READ[$tab]))
        ->and($json)->not->toContain('SECRET-')
        ->and($json)->not->toContain('PHPMYADMIN_KEY')
        ->and($json)->not->toContain('PRIVATE KEY')
        ->and($response->getContent())->not->toContain('SECRET-')
        ->and($response->getContent())->not->toContain('PHPMYADMIN_KEY');
})->with(['general', 'web', 'dns', 'mail', 'databases', 'backups', 'ssl', 'security', 'plugins', 'white-label']);

it('answers the values of every tab, and set flags for the secrets', function () {
    $response = $this->getJson('/api/v2/system/settings', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();

    $response->assertJsonPath('data.general', [
        'hostname' => 'srv1.example.net', 'timezone' => 'Europe/Berlin', 'language' => 'en', 'theme' => 'dark', 'user_theme' => true,
        'debug' => false, 'experimental' => false, 'version' => '2.0.0', 'release_branch' => 'release',
    ])
        ->assertJsonPath('data.web.php_default', '8.5')
        ->assertJsonPath('data.web.php_versions.2', ['version' => '8.4', 'installed' => true, 'default' => false, 'used_by' => ['bob/shop.example']])
        ->assertJsonPath('data.dns.hosts', [['host' => 'ns2.example.net', 'port' => 8083, 'type' => 'api', 'user' => 'cluster', 'dns_user' => 'dns-sync', 'suspended' => false]])
        ->assertJsonPath('data.mail.relay', true)->assertJsonPath('data.mail.relay_port', 587)->assertJsonPath('data.mail.antivirus_system', null)
        ->assertJsonPath('data.mail.relay_password', ['set' => true])
        ->assertJsonPath('data.databases.pma_sso', true)->assertJsonPath('data.databases.pga_alias', 'phppgadmin')
        ->assertJsonPath('data.databases.hosts.0', ['host' => 'localhost', 'type' => 'mysql', 'port' => 3306, 'max_databases' => 500, 'databases' => 3, 'suspended' => false])
        ->assertJsonPath('data.backups.remote_type', 'sftp')->assertJsonPath('data.backups.port', 22)->assertJsonPath('data.backups.mode', 'zstd')
        ->assertJsonPath('data.backups.password', ['set' => true])->assertJsonPath('data.backups.repo', ['set' => true])
        ->assertJsonPath('data.backups.keep_daily', 7)
        ->assertJsonPath('data.ssl.key', ['set' => true])->assertJsonPath('data.ssl.issuer', 'Example CA')
        ->assertJsonPath('data.security.API_SYSTEM', '2')->assertJsonPath('data.security.POLICY_SYSTEM_HIDE_SERVICES', 'no')
        ->assertJsonPath('data.plugins', ['app_installer' => true, 'plugins' => ['filemanager' => true, 'web-terminal' => false, 'cgroups' => false, 'quota' => false, 'firewall' => true, 'malware-scan' => false, 'silo' => false]])
        ->assertJsonPath('data.white-label.TITLE', '{{page}} - {{hostname}} - {{appname}}');
});

it('answers false set flags without the secrets', function () {
    withSysConfig($this->fake, settingsApiConfig(['SMTP_RELAY' => 'false', 'BACKUP_SYSTEM' => 'local', 'BACKUP_INCREMENTAL' => 'no', 'PHPMYADMIN_KEY' => '']));
    $this->fake->on('v-list-sys-arcpanel-ssl')->returnsJson(['ARCPANEL' => ['CRT' => '', 'KEY' => '']]);

    $this->getJson('/api/v2/system/settings', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.mail.relay_password', ['set' => false])
        ->assertJsonPath('data.backups.password', ['set' => false])
        ->assertJsonPath('data.backups.remote_type', null)
        ->assertJsonPath('data.backups.repo', ['set' => false])
        ->assertJsonPath('data.ssl.key', ['set' => false])
        ->assertJsonPath('data.databases.pma_sso', false);
});

it('leaves out the tabs of systems the server does not run and answers 404 for them', function () {
    withSysConfig($this->fake, settingsApiConfig(['DNS_SYSTEM' => '', 'MAIL_SYSTEM' => '', 'DB_SYSTEM' => '']));
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    expect(array_keys($this->getJson('/api/v2/system/settings', $headers)->assertOk()->json('data')))
        ->toBe(['general', 'web', 'backups', 'ssl', 'security', 'plugins', 'white-label']);
    $this->patchJson('/api/v2/system/settings/mail', ['relay' => false], $headers)->assertNotFound();
    $this->patchJson('/api/v2/system/settings/databases', ['pma_alias' => 'pma'], $headers)->assertNotFound();
    $this->patchJson('/api/v2/system/settings/dns', [], $headers)->assertNotFound();
    $this->putJson('/api/v2/system/settings/databases/hosts/mysql/localhost/password', ['user' => 'root', 'password' => 'N3w-pass-word'], $headers)->assertNotFound();
    $this->patchJson('/api/v2/system/settings/nope', [], $headers)->assertNotFound();
    assertNoSettingsChange($this->fake);
});

// Refusals (D58, D23)

it('refuses the fields a key may never change with 403 naming the field (D58)', function (string $tab, string $field, mixed $value) {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson("/api/v2/system/settings/{$tab}", [$field => $value, 'LOGIN_STYLE' => 'old'], $headers)
        ->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.message', fn (string $m) => str_starts_with($m, $field.' cannot be changed through the API'));
    $this->patchJson("/api/v2/system/settings/{$tab}?dry_run=1", [$field => $value], $headers)->assertForbidden();
    // On any tab
    $this->patchJson('/api/v2/system/settings/general', [$field => $value], $headers)->assertForbidden();
    assertNoSettingsChange($this->fake);
    $this->fake->assertNotRan('v-delete-sys-pma-sso');
})->with([
    'API_SYSTEM' => ['security', 'API_SYSTEM', '0'],
    'API' => ['security', 'API', 'yes'],
    'API_V1' => ['security', 'API_V1', 'yes'],
    'API_ALLOWED_IP' => ['security', 'API_ALLOWED_IP', '203.0.113.9'],
    'POLICY_SYSTEM_PROTECTED_ADMIN' => ['security', 'POLICY_SYSTEM_PROTECTED_ADMIN', 'no'],
    'POLICY_SYSTEM_HIDE_ADMIN' => ['security', 'POLICY_SYSTEM_HIDE_ADMIN', 'yes'],
    'POLICY_SYSTEM_HIDE_SERVICES' => ['security', 'POLICY_SYSTEM_HIDE_SERVICES', 'yes'],
    'phpMyAdmin single sign-on' => ['databases', 'pma_sso', false],
    'phpMyAdmin restriction' => ['databases', 'pma_restrict', true],
    'phpMyAdmin key' => ['databases', 'PHPMYADMIN_KEY', 'x'],
    'white-label logo' => ['white-label', 'logo', 'restore'],
]);

it('refuses a field the tab does not take and the read-only DNS tab', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->patchJson('/api/v2/system/settings/general', ['version' => '9.9.9', 'theme' => 'default'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.version.0', 'This field cannot be changed on the general tab.');
    $this->patchJson('/api/v2/system/settings/mail', ['SERVER_SMTP_PASSWD' => 'x'], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/system/settings/plugins', ['quota' => true], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/system/settings/dns', ['dnssec' => false], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'This tab of the server settings is read-only.');
    assertNoSettingsChange($this->fake);
});

it('refuses every change to non-administrators before any command', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->patchJson('/api/v2/system/settings/general', ['theme' => 'default'], $headers)->assertForbidden();
    $this->patchJson('/api/v2/system/settings/security', ['API_SYSTEM' => '0'], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.3', '8.4', '8.5']], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/settings/plugins/quota', ['enabled' => true], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/settings/databases/hosts/mysql/localhost/password', ['user' => 'root', 'password' => 'N3w-pass-word'], $headers)->assertForbidden();
    assertNoSettingsChange($this->fake);
    $this->fake->assertNotRan('v-list-sys-php-versions');
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('refuses the changes to a v2-read key before any command, and reads with it', function () {
    $headers = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);

    $this->getJson('/api/v2/system/settings', $headers)->assertOk();
    $this->patchJson('/api/v2/system/settings/general', ['theme' => 'default', 'hostname' => 'srv2.example.net'], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-change-sys-hostname', 'v-change-sys-config-value']);
    $this->patchJson('/api/v2/system/settings/mail', ['relay_password' => 'SECRET-NEW-RELAY-2'], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-add-sys-smtp-relay']);
    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.2', '8.4', '8.5']], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-start-sys-task']);
    $this->putJson('/api/v2/system/settings/plugins/quota', ['enabled' => true], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/settings/databases/hosts/mysql/localhost/password', ['user' => 'root', 'password' => 'N3w-pass-word'], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-change-database-host-password']);
    assertNoSettingsChange($this->fake);
});

// Changing

it('changes only the given values that changed, with a key holding only v2-server', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson('/api/v2/system/settings/general', ['hostname' => 'srv1.example.net', 'theme' => 'dark'], $headers)->assertOk()
        ->assertJsonPath('data.hostname', 'srv1.example.net');
    $this->patchJson('/api/v2/system/settings/general', [], $headers)->assertOk();
    assertNoSettingsChange($this->fake);

    $this->patchJson('/api/v2/system/settings/general', ['theme' => 'default', 'debug' => true, 'language' => 'de'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['THEME', 'default']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['DEBUG_MODE', 'true']);
    $this->fake->assertRan('v-change-sys-language', fn ($a) => $a === ['de']);
    // Fields left out are not written, even when the stored value is missing
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => in_array($a[0], ['POLICY_USER_CHANGE_THEME', 'POLICY_SYSTEM_ENABLE_BACON'], true), 0);
    $this->fake->assertNotRan('v-change-sys-hostname');

    $this->patchJson('/api/v2/system/settings/general', ['timezone' => 'Mars/Base', 'theme' => 'pink'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.timezone.0', 'Please select a valid time zone.')
        ->assertJsonPath('error.fields.theme.0', 'Please select a valid theme.');
    $this->fake->assertNotRan('v-change-sys-timezone');

    $this->patchJson('/api/v2/system/settings/security', ['POLICY_USER_VIEW_LOGS' => 'no', 'INACTIVE_SESSION_TIMEOUT' => 30, 'LOGIN_STYLE' => 'default'], $headers)->assertOk()
        ->assertJsonPath('data.POLICY_USER_VIEW_LOGS', 'yes');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_USER_VIEW_LOGS', 'no']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['INACTIVE_SESSION_TIMEOUT', '30']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'LOGIN_STYLE', 0);

    $this->patchJson('/api/v2/system/settings/white-label', ['APP_NAME' => 'HostCo', 'HIDE_DOCS' => 'yes'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['APP_NAME', 'HostCo']);
    $this->patchJson('/api/v2/system/settings/plugins', ['app_installer' => false], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['PLUGIN_APP_INSTALLER', 'false']);
    $this->patchJson('/api/v2/system/settings/web', ['php_default' => '8.3'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-php', fn ($a) => $a === ['8.3']);
    $this->patchJson('/api/v2/system/settings/web', ['php_default' => '8.2'], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/system/settings/databases', ['pga_alias' => 'pgadmin'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-db-alias', fn ($a) => $a === ['pga', 'pgadmin']);
    $this->fake->assertNotRan('v-change-sys-api');
});

it('names the failed step and the steps applied before it', function () {
    $this->fake->on('v-change-sys-timezone')->returnsExit(ExitCode::Invalid, 'Error: invalid timezone');

    $this->patchJson('/api/v2/system/settings/general', ['hostname' => 'srv2.example.net', 'timezone' => 'UTC', 'theme' => 'default'], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertStatus(422)
        ->assertJsonPath('error.step.command', 'v-change-sys-timezone')
        ->assertJsonPath('error.applied', [['label' => 'Change the host name', 'command' => 'v-change-sys-hostname']]);
    $this->fake->assertNotRan('v-change-sys-config-value');
});

it('accepts the SMTP relay password and never answers it, also not in a dry run', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $dry = $this->patchJson('/api/v2/system/settings/mail?dry_run=1', ['relay_password' => 'SECRET-NEW-RELAY-1'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Change the SMTP relay', 'command' => 'v-add-sys-smtp-relay', 'args' => ['smtp.example.net', 'relay-user', '******', '587']]);
    expect($dry->getContent())->not->toContain('SECRET-');
    $this->fake->assertNotRan('v-add-sys-smtp-relay');

    $response = $this->patchJson('/api/v2/system/settings/mail', ['relay_password' => 'SECRET-NEW-RELAY-1'], $headers)->assertOk()
        ->assertJsonPath('data.relay_password', ['set' => true]);
    expect($response->getContent())->not->toContain('SECRET-');
    $this->fake->assertRan('v-add-sys-smtp-relay', fn ($a) => $a === ['smtp.example.net', 'relay-user', 'SECRET-NEW-RELAY-1', '587']);

    // A new host needs the password again, as on the page
    $this->patchJson('/api/v2/system/settings/mail', ['relay_host' => 'smtp2.example.net'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.relay_password.0', 'Field "Password" can not be blank.');
    $this->patchJson('/api/v2/system/settings/mail', ['relay' => false], $headers)->assertOk();
    $this->fake->assertRan('v-delete-sys-smtp-relay');
});

it('changes and turns off an S3 backup host with a key of the server set (E44)', function () {
    $this->fake->on('v-list-backup-host-restic')->returnsJson(['restic' => ['REPO' => 'rclone:arcpanel-s3:backups/server1', 'SNAPSHOTS' => '10', 'KEEP_DAILY' => '-1',
        'KEEP_WEEKLY' => '-1', 'KEEP_MONTHLY' => '-1', 'KEEP_YEARLY' => '-1', 'S3_ENDPOINT' => 'https://s3.example.net', 'S3_REGION' => '', 'S3_KEY_ID' => 'AKIDEXAMPLE']]);
    $this->fake->on('v-delete-backup-host-s3')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson('/api/v2/system/settings/backups', ['keep_daily' => 14], $headers)->assertOk();
    $this->fake->assertRan('v-add-backup-host-restic', fn ($a) => $a === ['rclone:arcpanel-s3:backups/server1', '10', '14', '-1', '-1', '-1']);
    $this->patchJson('/api/v2/system/settings/backups', ['incremental' => false], $headers)->assertOk();
    $this->fake->assertRan('v-delete-backup-host-s3');
    $this->patchJson('/api/v2/system/settings/backups', ['incremental' => false], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    expect(apiSetCommands('v2-write'))->toContain('v-delete-backup-host-s3');
});

it('answers the S3 backup host without its secret (E48 M0)', function () {
    $this->fake->on('v-list-backup-host-restic')->returnsJson(['restic' => ['REPO' => 'rclone:arcpanel-s3:backups/server1', 'SNAPSHOTS' => '10', 'KEEP_DAILY' => '-1',
        'KEEP_WEEKLY' => '-1', 'KEEP_MONTHLY' => '-1', 'KEEP_YEARLY' => '-1', 'S3_ENDPOINT' => 'https://s3.example.net', 'S3_REGION' => 'eu-central-1', 'S3_KEY_ID' => 'AKIDEXAMPLE']]);

    $this->getJson('/api/v2/system/settings', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.backups.repo_type', 's3')->assertJsonPath('data.backups.s3_endpoint', 'https://s3.example.net')
        ->assertJsonPath('data.backups.s3_bucket', 'backups/server1')->assertJsonPath('data.backups.s3_region', 'eu-central-1')
        ->assertJsonPath('data.backups.s3_key_id', 'AKIDEXAMPLE')->assertJsonPath('data.backups.s3_secret', ['set' => true]);
});

it('sends incremental backups to a new S3 host with a key of the server set (E48 M0)', function () {
    withSysConfig($this->fake, settingsApiConfig(['BACKUP_INCREMENTAL' => 'no']));
    $this->fake->on('v-add-backup-host-s3')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');
    $s3 = ['incremental' => true, 'repo_type' => 's3', 's3_endpoint' => 'https://s3.example.net/', 's3_bucket' => 'backups/server1', 's3_key_id' => 'AKIDEXAMPLE'];

    // A new connection needs the secret access key
    $this->patchJson('/api/v2/system/settings/backups', $s3, $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.s3_secret.0', 'Enter the secret access key: the connection changes.');
    $this->patchJson('/api/v2/system/settings/backups', ['incremental' => true, 'repo_type' => 's3'], $headers)->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['s3_endpoint', 's3_bucket', 's3_key_id']]]);
    $this->fake->assertNotRan('v-add-backup-host-s3');

    $response = $this->patchJson('/api/v2/system/settings/backups', [...$s3, 's3_secret' => 'SECRET-S3-KEY-2210'], $headers)->assertOk();
    expect($response->getContent())->not->toContain('SECRET-');
    $this->fake->assertRan('v-add-backup-host-s3', fn ($a) => array_slice($a, 0, 2) === ['https://s3.example.net', 'backups/server1']
        && $a[3] === 'us-east-1' && array_slice($a, 4) === ['30', '7', '-1', '-1', '-1']);
    expect(apiSetCommands('v2-server'))->toContain('v-add-backup-host-s3');
    expect(apiSetCommands('v2-write'))->toContain('v-add-backup-host-s3');
});

it('accepts the backup secrets and keeps the stored repository without showing it', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    // Only a retention value: restic is written again with the stored repository, masked
    $dry = $this->patchJson('/api/v2/system/settings/backups?dry_run=1', ['keep_daily' => 14], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-backup-host-restic')
        ->assertJsonPath('data.steps.1', ['label' => 'Change the incremental backup repository', 'command' => 'v-add-backup-host-restic', 'args' => ['******', '30', '14', '-1', '-1', '-1']]);
    expect($dry->getContent())->not->toContain('SECRET-');
    $this->fake->assertNotRan('v-add-backup-host-restic');

    $this->patchJson('/api/v2/system/settings/backups', ['keep_daily' => 14], $headers)->assertOk();
    $this->fake->assertRan('v-add-backup-host-restic', fn ($a) => $a === ['rest:https://backup:SECRET-RESTIC-9902@restic.example.net/arc', '30', '14', '-1', '-1', '-1']);
    $this->fake->assertNotRan('v-add-backup-host');

    // A new remote password writes the remote again, with the stored fields
    $response = $this->patchJson('/api/v2/system/settings/backups', ['password' => 'SECRET-NEW-BACKUP-5'], $headers)->assertOk()
        ->assertJsonPath('data.password', ['set' => true]);
    expect($response->getContent())->not->toContain('SECRET-');
    $this->fake->assertRan('v-add-backup-host', fn ($a) => $a === ['sftp', 'backup.example.net', 'arc', 'SECRET-NEW-BACKUP-5', '/srv/backup', '22']);

    // Another type needs its fields and secret, as on the page
    $this->patchJson('/api/v2/system/settings/backups', ['remote_type' => 'b2', 'bucket' => 'arc-backups'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.key_id.0', 'Field "Application Key ID" can not be blank.')
        ->assertJsonPath('error.fields.password.0', 'Field "Application Key" can not be blank.');
    $this->patchJson('/api/v2/system/settings/backups', ['mode' => 'gzip'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['BACKUP_MODE', 'gzip']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['BACKUP_GZIP', '9']);
});

it('accepts the panel certificate and key and never answers the key', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $body = ['certificate' => "-----BEGIN CERTIFICATE-----\nNEW\n-----END CERTIFICATE-----", 'key' => "-----BEGIN PRIVATE KEY-----\nSECRET-NEW-KEY-9\n-----END PRIVATE KEY-----"];

    $dry = $this->patchJson('/api/v2/system/settings/ssl?dry_run=1', $body, $headers)->assertOk()->assertJsonPath('data.steps.0.args', ['******']);
    expect($dry->getContent())->not->toContain('SECRET-');
    $response = $this->patchJson('/api/v2/system/settings/ssl', $body, $headers)->assertOk()->assertJsonPath('data.key', ['set' => true]);
    expect($response->getContent())->not->toContain('SECRET-');
    $this->fake->assertRan('v-change-sys-arcpanel-ssl', fn ($a) => json_decode($a[0], true)['certificate.key'] === "-----BEGIN PRIVATE KEY-----\nSECRET-NEW-KEY-9\n-----END PRIVATE KEY-----\n");

    $this->patchJson('/api/v2/system/settings/ssl', ['certificate' => $body['certificate']], $headers)->assertStatus(422)->assertJsonPath('error.fields.key.0', fn ($m) => is_string($m));
});

// System tasks

it('sets the PHP versions as the system task and never removes a locked one', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.2', '8.4', '8.5']], $headers)->assertStatus(202)->assertDocumentedHeaders('setPhpVersions')
        ->assertHeader('Link', '</api/v2/tasks/sys:'.SETTINGS_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.id', 'sys:'.SETTINGS_TASK)->assertJsonPath('data.kind', 'php')->assertJsonPath('data.args', '8.2 8.3');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php', '8.2', '8.3']);

    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.3', '8.4', '8.5']], $headers)->assertOk()
        ->assertJsonCount(4, 'data')->assertJsonPath('data.3', ['version' => '8.5', 'installed' => true, 'default' => true, 'used_by' => []]);
    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.3']], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.versions', ['PHP 8.4 is used by bob/shop.example and is never removed.', 'PHP 8.5 is the default version and is never removed.']);
    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.4', '8.5', '7.4']], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields', ['versions.2' => ['PHP 7.4 is not supported on this server.']]);
    $this->putJson('/api/v2/system/settings/web/php?dry_run=1', ['versions' => ['8.4', '8.5']], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Change the PHP versions', 'command' => 'v-start-sys-task', 'args' => ['php', '-', '8.3']]);
    $this->fake->assertRan('v-start-sys-task', null, 1);
});

it('installs and removes plugins as the system task', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->putJson('/api/v2/system/settings/plugins/quota', ['enabled' => true], $headers)->assertStatus(202)->assertDocumentedHeaders('setPlugin')
        ->assertJsonPath('data.kind', 'plugin')->assertJsonPath('data.args', 'quota on');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['plugin', 'quota', 'on']);
    $this->putJson('/api/v2/system/settings/plugins/firewall?dry_run=1', ['enabled' => false], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['plugin', 'firewall', 'off']);
    // Already in that state: nothing starts
    $this->putJson('/api/v2/system/settings/plugins/filemanager', ['enabled' => true], $headers)->assertOk()
        ->assertExactJson(['data' => ['name' => 'filemanager', 'enabled' => true]]);
    $this->putJson('/api/v2/system/settings/plugins/nope', ['enabled' => true], $headers)->assertNotFound();
    $this->putJson('/api/v2/system/settings/plugins/quota', ['enabled' => 'maybe'], $headers)->assertStatus(422);
    $this->fake->assertRan('v-start-sys-task', null, 1);
});

it('answers 409 E_INUSE with the running system task', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '17286300005678', 'KIND' => 'arcpanel-update', 'ARGS' => '', 'STATUS' => 'running', 'EXIT' => '', 'START' => '2026-10-08 10:00:00', 'END' => '', 'LOG' => '']);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->putJson('/api/v2/system/settings/plugins/quota', ['enabled' => true], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')->assertJsonPath('error.existing', '/api/v2/tasks/sys:17286300005678');
    $this->putJson('/api/v2/system/settings/web/php', ['versions' => ['8.2', '8.3', '8.4', '8.5']], $headers)->assertStatus(409)
        ->assertJsonPath('error.existing', '/api/v2/tasks/sys:17286300005678');
});

// Database hosts

it('changes the password of a MySQL host without answering it', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');
    $body = ['user' => 'root', 'password' => 'SECRET-DB-PASS-77'];

    $dry = $this->putJson('/api/v2/system/settings/databases/hosts/mysql/localhost/password?dry_run=1', $body, $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['mysql', 'localhost', 'root', '******']);
    expect($dry->getContent())->not->toContain('SECRET-');
    $this->fake->assertNotRan('v-change-database-host-password');

    $this->putJson('/api/v2/system/settings/databases/hosts/mysql/localhost/password', $body, $headers)->assertNoContent();
    $this->fake->assertRan('v-change-database-host-password', fn ($a) => $a === ['mysql', 'localhost', 'root', 'SECRET-DB-PASS-77']);

    $this->putJson('/api/v2/system/settings/databases/hosts/mysql/db9.example.net/password', $body, $headers)->assertNotFound();
    $this->putJson('/api/v2/system/settings/databases/hosts/pgsql/pg.example.net/password', $body, $headers)->assertNotFound();
    $this->putJson('/api/v2/system/settings/databases/hosts/mysql/localhost/password', ['user' => 'root', 'password' => 'short'], $headers)->assertStatus(422);
    $this->fake->assertRan('v-change-database-host-password', null, 1);
});

it('reads and writes the shells of reseller packages in the security tab (E39 W4 D5)', function () {
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-change-sys-config-value')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/system/settings', $headers)->assertOk()->assertJsonPath('data.security.POLICY_RESELLER_PACKAGE_SHELLS', 'nologin');
    $this->patchJson('/api/v2/system/settings/security', ['POLICY_RESELLER_PACKAGE_SHELLS' => 'nologin,bash'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_RESELLER_PACKAGE_SHELLS', 'nologin,bash']);
    $this->patchJson('/api/v2/system/settings/security', ['POLICY_RESELLER_PACKAGE_SHELLS' => 'zsh'], $headers)->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['POLICY_RESELLER_PACKAGE_SHELLS']]]);
});

it('reads and changes the webmail and database tools ports (E46)', function () {
    withSysConfig($this->fake, settingsApiConfig(['WEBMAIL_PORT' => '8086', 'WEBMAIL_LINK' => '', 'DB_ADMIN_PORT' => '8087']));
    foreach (['v-change-sys-webmail-port', 'v-change-sys-webmail-link', 'v-change-sys-db-admin-port'] as $command) {
        $this->fake->on($command)->returns();
    }
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/settings', $headers)->assertOk()
        ->assertJsonPath('data.mail.webmail_port', 8086)->assertJsonPath('data.mail.webmail_link', 'port')
        ->assertJsonPath('data.databases.db_admin_port', 8087);

    $this->patchJson('/api/v2/system/settings/mail', ['webmail_port' => 8086, 'webmail_link' => 'port'], $headers)->assertOk();
    $this->fake->assertNotRan('v-change-sys-webmail-port');
    $this->fake->assertNotRan('v-change-sys-webmail-link');

    $this->patchJson('/api/v2/system/settings/mail', ['webmail_port' => null, 'webmail_link' => 'domain'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-webmail-port', fn ($a) => $a === ['none']);
    $this->fake->assertRan('v-change-sys-webmail-link', fn ($a) => $a === ['domain']);
    $this->patchJson('/api/v2/system/settings/databases', ['db_admin_port' => 38470], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-db-admin-port', fn ($a) => $a === ['38470']);

    $this->patchJson('/api/v2/system/settings/mail', ['webmail_port' => 80], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/system/settings/mail', ['webmail_link' => 'elsewhere'], $headers)->assertStatus(422);
});

it('refuses the webmail port on a server without webmail (E46)', function () {
    withSysConfig($this->fake, settingsApiConfig(['WEBMAIL_SYSTEM' => '', 'WEBMAIL_PORT' => '']));
    $this->fake->on('v-change-sys-webmail-port')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/settings', $headers)->assertOk()
        ->assertJsonPath('data.mail.webmail_port', null)->assertJsonPath('data.mail.webmail_link', null);
    $this->patchJson('/api/v2/system/settings/mail', ['webmail_port' => 8086], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.webmail_port.0', 'This server has no webmail.');
    $this->fake->assertNotRan('v-change-sys-webmail-port');
});
