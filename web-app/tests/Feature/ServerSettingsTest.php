<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Server settings and the system task (E7 batch 3 spec §6, §8).

/** `v-list-sys-php-versions json` */
function phpVersionsFixture(): array
{
    return [
        '8.2' => ['INSTALLED' => 'no', 'DEFAULT' => 'no', 'USED_BY' => ''],
        '8.3' => ['INSTALLED' => 'yes', 'DEFAULT' => 'no', 'USED_BY' => ''],
        '8.4' => ['INSTALLED' => 'yes', 'DEFAULT' => 'no', 'USED_BY' => 'bob/shop.example'],
        '8.5' => ['INSTALLED' => 'yes', 'DEFAULT' => 'yes', 'USED_BY' => ''],
    ];
}

function noTask(): array
{
    return ['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => ''];
}

beforeEach(function () {
    $this->fake = fakeArc([
        'SMTP_RELAY' => 'false', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail',
        'DB_PMA_ALIAS' => 'phpmyadmin', 'DB_PGA_ALIAS' => 'phppgadmin', 'PHPMYADMIN_KEY' => '', 'PMA_RESTRICT_ACCESS' => 'no',
        'BACKUP' => '/backup', 'BACKUP_MODE' => 'zstd', 'BACKUP_GZIP' => '4', 'BACKUP_INCREMENTAL' => 'no',
        'API_SYSTEM' => '1', 'API' => 'no', 'API_ALLOWED_IP' => '', 'LOGIN_STYLE' => 'default', 'POLICY_CSRF_STRICTNESS' => '1',
        'POLICY_USER_EDIT_DETAILS' => 'yes', 'POLICY_USER_VIEW_LOGS' => 'yes', 'POLICY_USER_DELETE_LOGS' => 'yes',
        'POLICY_SYSTEM_HIDE_ADMIN' => 'no', 'POLICY_SYSTEM_ENABLE_BACON' => 'false', 'ENFORCE_SUBDOMAIN_OWNERSHIP' => 'yes',
        'PLUGIN_APP_INSTALLER' => 'true', 'RESOURCES_LIMIT' => 'no', 'DISK_QUOTA' => 'no',
        'TITLE' => '{{page}} - {{hostname}} - {{appname}}', 'SUBJECT_EMAIL' => '{{subject}}', 'HIDE_DOCS' => 'no', 'FROM_NAME' => '', 'FROM_EMAIL' => '',
    ]);
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net', 'UPTIME' => '10', 'LOADAVERAGE' => '0 / 0 / 0']]);
    $this->fake->on('v-get-sys-timezone')->returns("Europe/Berlin\n");
    $this->fake->on('v-get-sys-timezones')->returnsJson(['Asia/Bangkok', 'Europe/Berlin', 'UTC']);
    $this->fake->on('v-list-sys-languages')->returnsJson(['en', 'de', 'vi']);
    $this->fake->on('v-list-sys-themes')->returnsJson(['dark', 'default']);
    $this->fake->on('v-list-sys-php-versions')->returnsJson(phpVersionsFixture());
    $this->fake->on('v-list-sys-task')->returnsJson(noTask());
    $this->fake->on('v-list-remote-dns-hosts')->returns('');
    $this->fake->on('v-list-database-hosts')->returnsJson([
        ['HOST' => 'localhost', 'PORT' => '3306', 'TYPE' => 'mysql', 'MAX_DB' => '500', 'U_DB_BASES' => '3', 'SUSPENDED' => 'no'],
        ['HOST' => 'db2.example.net', 'PORT' => '3306', 'TYPE' => 'mysql', 'MAX_DB' => '500', 'U_DB_BASES' => '0', 'SUSPENDED' => 'no'],
    ]);
    $this->fake->on('v-list-backup-host')->returns('');
    $this->fake->on('v-list-backup-host-restic')->returns('');
    $this->fake->on('v-list-sys-arcpanel-ssl')->returnsJson(['ARCPANEL' => [
        'CRT' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", 'KEY' => "-----BEGIN PRIVATE KEY-----\nSECRETKEY\n-----END PRIVATE KEY-----",
        'SUBJECT' => 'srv1.example.net', 'ALIASES' => 'srv1.example.net', 'NOT_BEFORE' => 'Oct 4 2026', 'NOT_AFTER' => 'Oct 4 2027',
        'SIGNATURE' => 'ecdsa-with-SHA256', 'PUB_KEY' => '256 bit', 'ISSUER' => 'Example CA',
    ]]);
    $this->fake->on('v-change-sys-config-value')->returns();
});

// Pages

it('shows every tab to administrators', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/settings')->assertRedirect('/server/settings/general');
    $this->get('/server/settings/general')->assertOk()->assertSee('srv1.example.net')->assertSee('Europe/Berlin')
        ->assertSee('Web Server')->assertSee('White Label');
    // Versions are installed and removed on the PHP page (E41 §8)
    $this->get('/server/settings/web')->assertOk()->assertSee('Default PHP Version')->assertSee(route('server.php.index'), false)->assertDontSee('Apply PHP versions');
    $this->get('/server/settings/dns')->assertOk()->assertSee('No DNS cluster hosts');
    $this->get('/server/settings/mail')->assertOk()->assertSee('Global SMTP Relay')->assertSee('webmail');
    $this->get('/server/settings/databases')->assertOk()->assertSee('db2.example.net')->assertSee('phpmyadmin');
    $this->get('/server/settings/backups')->assertOk()->assertSee('Incremental Backups');
    $this->get('/server/settings/ssl')->assertOk()->assertSee('Example CA')->assertDontSee('SECRETKEY');
    $security = $this->get('/server/settings/security')->assertOk()->assertSee('System Protection')->assertSee('Allow users to delete log history')
        ->assertSeeInOrder(['Login screen style', 'Allow users to reset their passwords', 'System Protection'])->getContent();
    // One Login card: the password reset policy sits in it
    expect(substr_count($security, '>Login</h2>'))->toBe(1);
    $this->get('/server/settings/plugins')->assertOk()->assertSee('Disk Quota')->assertSee('Install');
    $this->get('/server/settings/white-label')->assertOk()->assertSee('{{page}} - {{hostname}} - {{appname}}', false);
    $this->get('/server/settings/nope')->assertNotFound();
});

it('answers 404 for the tabs of systems the server does not run', function () {
    withSysConfig($this->fake, ['DNS_SYSTEM' => '', 'MAIL_SYSTEM' => '', 'DB_SYSTEM' => '']);
    signIn('arcadmin', 'admin');

    $this->get('/server/settings/dns')->assertNotFound();
    $this->get('/server/settings/mail')->assertNotFound();
    $this->get('/server/settings/databases')->assertNotFound();
    $this->post('/server/settings/mail', ['relay' => '1', 'relay_host' => 'smtp.example.net'])->assertNotFound();
    $this->get('/server/settings/general')->assertOk()->assertDontSee('DNS Server')->assertDontSee('Mail Server');
});

it('keeps the settings for administrators and refuses changes in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/settings/general')->assertForbidden();
        $this->post('/server/settings/general', ['hostname' => 'x.example.net'])->assertForbidden();
        $this->post('/server/settings/plugins/quota', ['state' => 'on'])->assertForbidden();
        $this->get('/server/task')->assertForbidden();
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    $this->get('/server/settings/general')->assertOk()->assertSee('Read-only')->assertSee('<fieldset class="ap-fieldset-plain" disabled', false);
    $this->post('/server/settings/general', ['hostname' => 'x.example.net'])->assertForbidden();
    $this->fake->assertNotRan('v-change-sys-hostname');
});

// General

it('changes only what changed on the General tab, in the legacy order', function () {
    foreach (['v-change-sys-hostname', 'v-change-sys-timezone', 'v-change-sys-language'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('arcadmin', 'admin');
    $form = ['hostname' => 'srv1.example.net', 'timezone' => 'Europe/Berlin', 'language' => 'en', 'theme' => 'dark', 'user_theme' => '1'];

    $this->post('/server/settings/general', $form)->assertRedirect('/server/settings/general')->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-change-sys-config-value');

    $this->post('/server/settings/general', ['hostname' => 'srv2.example.net', 'timezone' => 'UTC', 'language' => 'de', 'language_all' => '1',
        'theme' => 'default', 'debug' => '1'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-hostname', fn ($a) => $a === ['srv2.example.net']);
    $this->fake->assertRan('v-change-sys-timezone', fn ($a) => $a === ['UTC']);
    $this->fake->assertRan('v-change-sys-language', fn ($a) => $a === ['de', 'yes']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['THEME', 'default']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_USER_CHANGE_THEME', 'no']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['DEBUG_MODE', 'true']);
    $order = array_column($this->fake->calls(), 'command');
    expect(array_search('v-change-sys-hostname', $order))->toBeLessThan(array_search('v-change-sys-timezone', $order));
});

it('validates the General tab against the lists of the server', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/general', ['hostname' => 'not a host', 'timezone' => 'Mars/Base', 'language' => 'xx', 'theme' => 'dark'])
        ->assertSessionHasErrors(['hostname']);
    $this->post('/server/settings/general', ['hostname' => 'srv1.example.net', 'timezone' => 'Mars/Base', 'language' => 'xx', 'theme' => 'pink'])
        ->assertSessionHasErrors(['timezone', 'language', 'theme']);
    $this->fake->assertNotRan('v-change-sys-timezone');
});

it('turns the suspended view off with the preview features', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_ENABLE_BACON' => 'true', 'POLICY_USER_VIEW_SUSPENDED' => 'yes', 'THEME' => 'dark']);
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/general', ['hostname' => 'srv1.example.net', 'timezone' => 'Europe/Berlin', 'language' => 'en', 'theme' => 'dark', 'user_theme' => '1'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_SYSTEM_ENABLE_BACON', 'false']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_USER_VIEW_SUSPENDED', 'no']);
});

it('reports the failed step and the steps applied before it', function () {
    $this->fake->on('v-change-sys-hostname')->returns();
    $this->fake->on('v-change-sys-timezone')->returnsExit(ExitCode::Invalid, 'Error: invalid timezone');
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/general', ['hostname' => 'srv2.example.net', 'timezone' => 'UTC', 'language' => 'en', 'theme' => 'default'])
        ->assertSessionHas('error', 'Change the time zone failed')
        ->assertSessionHas('error_detail', fn ($detail) => str_contains($detail, 'Error: invalid timezone') && str_contains($detail, 'Change the host name'));
    $this->fake->assertNotRan('v-change-sys-config-value');
});

// Web: default PHP and the PHP versions task

it('changes the default PHP version', function () {
    $this->fake->on('v-change-sys-php')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web', ['php_default' => '8.5'])->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/server/settings/web', ['php_default' => '8.3'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-php', fn ($a) => $a === ['8.3']);
    $this->post('/server/settings/web', ['php_default' => '8.2'])->assertSessionHasErrors('php_default');
});

it('installs and removes PHP versions in the background and never removes a locked one', function () {
    $this->fake->on('v-start-sys-task')->returns("17286400001234\n");
    signIn('arcadmin', 'admin');

    // 8.2 checked (install), 8.3 unchecked (remove); 8.4 (in use) and 8.5 (default) unchecked stay
    $this->post('/server/settings/web/php', ['versions' => ['8.2']])
        ->assertRedirect('/server/settings/web')->assertSessionHas('ok', 'The PHP versions are being changed. This may take a few minutes.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php', '8.2', '8.3']);

    $this->post('/server/settings/web/php', ['versions' => ['8.3', '8.4', '8.5']])->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertRan('v-start-sys-task', null, 1);
});

it('says so when another task runs', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web/php', ['versions' => ['8.2', '8.3']])
        ->assertSessionHas('error', 'Another task is running. Wait until it has finished.');
});

it('answers the task as JSON', function () {
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '1', 'KIND' => 'php', 'ARGS' => '8.2 -', 'STATUS' => 'running', 'EXIT' => '', 'START' => 'now', 'END' => '', 'LOG' => '$ v-add-web-php 8.2']);
    signIn('arcadmin', 'admin');

    $this->getJson('/server/task')->assertOk()->assertJsonPath('task.status', 'running')->assertJsonPath('task.log', '$ v-add-web-php 8.2')
        ->assertHeader('Cache-Control', 'no-store, private');
});

// Mail

it('writes the SMTP relay with the password in a file and needs it for a new relay', function () {
    $this->fake->on('v-add-sys-smtp-relay')->returns();
    $this->fake->on('v-change-sys-webmail')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/mail', ['webmail_alias' => 'webmail', 'relay' => '1', 'relay_host' => 'smtp.example.net', 'relay_user' => 'me'])
        ->assertSessionHasErrors('relay_password');
    $this->fake->assertNotRan('v-add-sys-smtp-relay');

    $this->post('/server/settings/mail', ['webmail_alias' => 'mail', 'relay' => '1', 'relay_host' => 'smtp.example.net', 'relay_port' => '2525',
        'relay_user' => 'me', 'relay_password' => 'S3cret-relay'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-webmail', fn ($a) => $a === ['mail']);
    $this->fake->assertRan('v-add-sys-smtp-relay', fn ($a) => $a === ['smtp.example.net', 'me', 'S3cret-relay', '2525']);
});

it('keeps an unchanged relay and removes it when turned off', function () {
    withSysConfig($this->fake, ['SMTP_RELAY' => 'true', 'SMTP_RELAY_HOST' => 'smtp.example.net', 'SMTP_RELAY_PORT' => '587', 'SMTP_RELAY_USER' => 'me', 'WEBMAIL_ALIAS' => 'webmail']);
    $this->fake->on('v-delete-sys-smtp-relay')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/mail', ['webmail_alias' => 'webmail', 'relay' => '1', 'relay_host' => 'smtp.example.net', 'relay_port' => '587', 'relay_user' => 'me'])
        ->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/server/settings/mail', ['webmail_alias' => 'webmail'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-sys-smtp-relay');
});

// Databases

it('changes the aliases and the single sign-on, and the password of one database host', function () {
    foreach (['v-change-sys-db-alias', 'v-add-sys-pma-sso', 'v-change-database-host-password'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('arcadmin', 'admin');

    // Hidden fields change nothing: no pma_alias, no SSO marker
    $this->post('/server/settings/databases', [])->assertSessionHas('ok', 'Nothing has changed.');

    $this->post('/server/settings/databases', ['pma_alias' => 'pma', 'pma_sso_shown' => '1', 'pma_sso' => '1', 'pma_restrict_shown' => '1'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-db-alias', fn ($a) => $a === ['pma', 'pma']);
    $this->fake->assertRan('v-add-sys-pma-sso', fn ($a) => $a === ['quiet']);

    $this->post('/server/settings/databases/hosts/mysql/db2.example.net/password', ['user' => 'admin', 'password' => 'N3w-pass-word'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-database-host-password', fn ($a) => $a === ['mysql', 'db2.example.net', 'admin', 'N3w-pass-word']);

    $this->post('/server/settings/databases/hosts/mysql/db9.example.net/password', ['user' => 'admin', 'password' => 'N3w-pass-word'])->assertNotFound();
    $this->post('/server/settings/databases/hosts/mysql/localhost/password', ['user' => 'root', 'password' => 'short'])->assertSessionHasErrorsIn('dbhost', ['password']);
});

// Backups

it('turns on local and remote backups, gzip at level 9', function () {
    $this->fake->on('v-add-backup-host')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/backups', ['local' => '1', 'mode' => 'gzip', 'level' => '4', 'remote' => '1', 'remote_type' => 'sftp',
        'host' => 'backup.example.net', 'username' => 'arc', 'path' => '/srv/backup'])->assertSessionHasErrors('password');
    $this->fake->assertNotRan('v-add-backup-host');

    $this->post('/server/settings/backups', ['local' => '1', 'mode' => 'gzip', 'level' => '4', 'remote' => '1', 'remote_type' => 'sftp',
        'host' => 'backup.example.net', 'username' => 'arc', 'password' => 'Rem0te-pass', 'path' => '/srv/backup'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['BACKUP_MODE', 'gzip']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['BACKUP_GZIP', '9']);
    $this->fake->assertRan('v-add-backup-host', fn ($a) => $a === ['sftp', 'backup.example.net', 'arc', 'Rem0te-pass', '/srv/backup', '22']);
});

it('replaces the remote backup type and the restic repository', function () {
    withSysConfig($this->fake, ['BACKUP_SYSTEM' => 'local,sftp', 'BACKUP_INCREMENTAL' => 'yes', 'BACKUP_MODE' => 'zstd', 'BACKUP_GZIP' => '4']);
    $this->fake->on('v-list-backup-host', ['sftp', 'json'])->returnsJson(['sftp' => ['HOST' => 'backup.example.net', 'USERNAME' => 'arc', 'PORT' => '22', 'TYPE' => 'sftp', 'BPATH' => '/srv']]);
    $this->fake->on('v-list-backup-host-restic')->returnsJson(['restic' => ['REPO' => '/backup/restic', 'SNAPSHOTS' => '30', 'KEEP_DAILY' => '-1', 'KEEP_WEEKLY' => '-1', 'KEEP_MONTHLY' => '-1', 'KEEP_YEARLY' => '-1']]);
    foreach (['v-add-backup-host', 'v-delete-backup-host', 'v-add-backup-host-restic', 'v-delete-backup-host-restic'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('arcadmin', 'admin');
    $restic = ['incremental' => '1', 'repo' => '/backup/restic', 'snapshots' => '30', 'keep_daily' => '-1', 'keep_weekly' => '-1', 'keep_monthly' => '-1', 'keep_yearly' => '-1'];

    // Nothing changed: no secret needed, restic untouched
    $this->post('/server/settings/backups', ['local' => '1', 'mode' => 'zstd', 'level' => '4', 'remote' => '1', 'remote_type' => 'sftp',
        'host' => 'backup.example.net', 'username' => 'arc', 'port' => '22', 'path' => '/srv'] + $restic)->assertSessionHas('ok', 'Nothing has changed.');

    $this->post('/server/settings/backups', ['local' => '1', 'mode' => 'zstd', 'level' => '4', 'remote' => '1', 'remote_type' => 'b2',
        'bucket' => 'arc-backups', 'key_id' => 'K001', 'password' => 'b2-app-key'] + array_replace($restic, ['keep_daily' => '7']))
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-backup-host', fn ($a) => $a === ['sftp']);
    $this->fake->assertRan('v-add-backup-host', fn ($a) => $a === ['b2', 'arc-backups', 'K001', 'b2-app-key']);
    $this->fake->assertRan('v-delete-backup-host-restic');
    $this->fake->assertRan('v-add-backup-host-restic', fn ($a) => $a === ['/backup/restic', '30', '7', '-1', '-1', '-1']);

    $this->post('/server/settings/backups', ['local' => '1', 'mode' => 'zstd', 'level' => '4'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-backup-host', fn ($a) => $a === ['sftp'], 2);
    $this->fake->assertRan('v-delete-backup-host-restic', null, 2);
});

// SSL

it('changes the panel certificate through a secret directory', function () {
    $this->fake->on('v-change-sys-arcpanel-ssl')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/ssl', ['certificate' => 'nope', 'key' => ''])->assertSessionHasErrors(['certificate', 'key']);
    $this->post('/server/settings/ssl', ['certificate' => "-----BEGIN CERTIFICATE-----\r\nNEW\r\n-----END CERTIFICATE-----", 'key' => "-----BEGIN PRIVATE KEY-----\nK\n-----END PRIVATE KEY-----"])
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-arcpanel-ssl', fn ($a) => json_decode($a[0], true) === [
        'certificate.crt' => "-----BEGIN CERTIFICATE-----\nNEW\n-----END CERTIFICATE-----\n",
        'certificate.key' => "-----BEGIN PRIVATE KEY-----\nK\n-----END PRIVATE KEY-----\n",
    ]);
});

// Security

it('switches REST API v1 on its own key and turns it off with the other APIs (E39 W4 D9)', function () {
    $this->fake->on('v-change-sys-api')->returns();
    signIn('arcadmin', 'admin');
    $form = ['API_SYSTEM' => '1', 'API' => 'no', 'API_ALLOWED_IP' => '', 'LOGIN_STYLE' => 'default', 'INACTIVE_SESSION_TIMEOUT' => '60', 'POLICY_CSRF_STRICTNESS' => '1'];

    $this->get('/server/settings/security')->assertOk()->assertSee('REST API v1');

    $this->post('/server/settings/security', array_replace($form, ['API_V1' => 'yes']))->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-api', fn ($a) => $a === ['enable']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['API_V1', 'yes']);

    // With API v1 still on, API_SYSTEM 0 and the legacy API off are not "everything off"
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['API_SYSTEM' => '1', 'API' => 'no', 'API_V1' => 'yes', 'API_ALLOWED_IP' => '']));
    app()->forgetInstance(SysConfig::class);
    cache()->flush();
    $this->post('/server/settings/security', array_replace($form, ['API_SYSTEM' => '0']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-api', fn ($a) => $a === ['disable'], 0);

    $this->post('/server/settings/security', array_replace($form, ['API_SYSTEM' => '0', 'API_V1' => 'no']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-api', fn ($a) => $a === ['disable']);

    $this->post('/server/settings/security', array_replace($form, ['API_V1' => 'maybe']))->assertSessionHasErrors('API_V1');
});

it('turns the API off with one command and keeps System Protection for the ROOT_USER', function () {
    $this->fake->on('v-change-sys-api')->returns();
    signIn('arcadmin', 'admin');
    $form = ['API_SYSTEM' => '1', 'API' => 'no', 'API_ALLOWED_IP' => '', 'LOGIN_STYLE' => 'default', 'INACTIVE_SESSION_TIMEOUT' => '60', 'POLICY_CSRF_STRICTNESS' => '1'];

    $this->post('/server/settings/security', array_replace($form, ['API_SYSTEM' => '0']))->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-api', fn ($a) => $a === ['disable']);
    $this->fake->assertNotRan('v-change-sys-config-value');

    $this->post('/server/settings/security', array_replace($form, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => "203.0.113.5\nallow-all", 'POLICY_SYSTEM_HIDE_ADMIN' => 'yes', 'POLICY_USER_VIEW_LOGS' => 'no']))
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-api', fn ($a) => $a === ['enable']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['API_SYSTEM', '2']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['API_ALLOWED_IP', '203.0.113.5,allow-all']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_SYSTEM_HIDE_ADMIN', 'yes']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_USER_VIEW_LOGS', 'no']);

    $this->post('/server/settings/security', array_replace($form, ['API_ALLOWED_IP' => 'not-an-ip']))->assertSessionHasErrors('API_ALLOWED_IP');

    // Another administrator cannot change System Protection
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin');
    $this->get('/server/settings/security')->assertOk()->assertDontSee('System Protection');
    $this->post('/server/settings/security', array_replace($form, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'POLICY_SYSTEM_PROTECTED_ADMIN', 0);
});

it('shows the allowed API addresses one per line and keeps them when the form leaves them out', function () {
    $this->fake->on('v-change-sys-api')->returns();
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => '203.0.113.5,allow-all']);
    signIn('arcadmin', 'admin');

    $html = $this->get('/server/settings/security')->assertOk()->getContent();
    expect($html)->not->toContain('<x-ap-')
        ->and($html)->toMatch('#<textarea[^>]*name="API_ALLOWED_IP"[^>]*>203\.0\.113\.5\nallow-all</textarea>#');

    $form = ['API_SYSTEM' => '2', 'API' => 'no', 'LOGIN_STYLE' => 'default', 'INACTIVE_SESSION_TIMEOUT' => '60', 'POLICY_CSRF_STRICTNESS' => '1'];
    $this->post('/server/settings/security', $form)->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'API_ALLOWED_IP', 0);
});

// Plugins and White Label

it('installs plugins in the background and changes the app installer flag', function () {
    $this->fake->on('v-start-sys-task')->returns("1\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/plugins/quota', ['state' => 'on'])->assertSessionHas('ok', 'The plugin is being installed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['plugin', 'quota', 'on']);
    $this->post('/server/settings/plugins/firewall', ['state' => 'off'])->assertSessionHas('ok', 'The plugin is being removed.');
    $this->post('/server/settings/plugins/firewall', ['state' => 'maybe'])->assertSessionHasErrors('state');
    $this->post('/server/settings/plugins/nope', ['state' => 'on'])->assertNotFound();

    $this->post('/server/settings/plugins', [])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['PLUGIN_APP_INSTALLER', 'false']);
});

it('changes the White Label values and the logo', function () {
    $this->fake->on('v-update-white-label-logo')->returns();
    signIn('arcadmin', 'admin');
    $form = ['APP_NAME' => 'HostCo', 'TITLE' => '{{page}} - {{hostname}} - {{appname}}', 'SUBJECT_EMAIL' => '{{subject}}', 'HIDE_DOCS' => 'yes', 'FROM_NAME' => '', 'FROM_EMAIL' => 'noreply@hostco.example'];

    $this->post('/server/settings/white-label', $form)->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['APP_NAME', 'HostCo']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['HIDE_DOCS', 'yes']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['FROM_EMAIL', 'noreply@hostco.example']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'TITLE', 0);

    $this->post('/server/settings/white-label/logo', ['action' => 'apply'])->assertSessionHas('ok');
    $this->fake->assertRan('v-update-white-label-logo', fn ($a) => $a === []);
    $this->post('/server/settings/white-label/logo', ['action' => 'restore'])->assertSessionHas('ok');
    $this->fake->assertRan('v-update-white-label-logo', fn ($a) => $a === ['yes', 'yes']);
});

it('has a capability for every settings route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.settings') || $name === 'server.task');

    expect($routes)->not->toBeEmpty()
        ->and(Capabilities::names())->toContain('server.settings');
});

it('sets the shells resellers may give in their packages, from the shells of the server (E39 W4 D5)', function () {
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin', 'sh']);
    $this->fake->on('v-change-sys-api')->returns();
    signIn('arcadmin', 'admin');
    $form = ['API_SYSTEM' => '1', 'API' => 'no', 'API_V1' => 'no', 'API_ALLOWED_IP' => '', 'LOGIN_STYLE' => 'default', 'INACTIVE_SESSION_TIMEOUT' => '60', 'POLICY_CSRF_STRICTNESS' => '1'];

    $this->get('/server/settings/security')->assertOk()->assertSee('Shells resellers may give in their packages')
        ->assertSee('name="POLICY_RESELLER_PACKAGE_SHELLS"', false);
    $this->post('/server/settings/security', $form + ['POLICY_RESELLER_PACKAGE_SHELLS' => 'nologin,bash'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['POLICY_RESELLER_PACKAGE_SHELLS', 'nologin,bash']);

    $this->post('/server/settings/security', $form + ['POLICY_RESELLER_PACKAGE_SHELLS' => 'nologin,zsh'])->assertSessionHasErrors('POLICY_RESELLER_PACKAGE_SHELLS');
    $this->post('/server/settings/security', $form + ['POLICY_RESELLER_PACKAGE_SHELLS' => 'nologin,'])->assertSessionHasErrors('POLICY_RESELLER_PACKAGE_SHELLS');
});
