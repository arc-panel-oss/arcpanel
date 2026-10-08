<?php

use App\Http\Requests\Api\V2\UpdateServerSettingsRequest;
use App\Http\Resources\V2\PhpVersionResource;
use App\Http\Resources\V2\PluginResource;
use App\Services\Server\ServerSettings;
use App\Services\Server\ServerSettingsActions;
use App\Services\Server\ServerSettingsApi;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// The allow-lists of the server settings in REST API v2 (E34 batch 6 D58) and
// the field checks that the settings page and the API share (D66).

beforeEach(function () {
    $this->fake = fakeArc(['SMTP_RELAY' => 'true', 'SMTP_RELAY_HOST' => 'smtp.example.net', 'SMTP_RELAY_PORT' => '587', 'SMTP_RELAY_USER' => 'me', 'BACKUP_SYSTEM' => 'local,sftp']);
    $this->fake->on('v-get-sys-timezones')->returnsJson(['Europe/Berlin', 'UTC']);
    $this->fake->on('v-list-sys-languages')->returnsJson(['en', 'de']);
    $this->fake->on('v-list-sys-themes')->returnsJson(['dark', 'default']);
    $this->fake->on('v-list-sys-php-versions')->returnsJson([
        '8.3' => ['INSTALLED' => 'no', 'DEFAULT' => 'no', 'USED_BY' => ''],
        '8.4' => ['INSTALLED' => 'yes', 'DEFAULT' => 'no', 'USED_BY' => ''],
        '8.5' => ['INSTALLED' => 'yes', 'DEFAULT' => 'yes', 'USED_BY' => ''],
    ]);
    $this->fake->on('v-list-database-hosts')->returnsJson([
        ['HOST' => 'localhost', 'PORT' => '3306', 'TYPE' => 'mysql', 'MAX_DB' => '500', 'U_DB_BASES' => '3', 'SUSPENDED' => 'no'],
        ['HOST' => 'pg.example.net', 'PORT' => '5432', 'TYPE' => 'pgsql', 'MAX_DB' => '100', 'U_DB_BASES' => '0', 'SUSPENDED' => 'no'],
    ]);
    $this->fake->on('v-list-backup-host', ['sftp', 'json'])->returnsJson(['sftp' => ['HOST' => 'backup.example.net', 'USERNAME' => 'arc', 'PORT' => '22', 'BPATH' => '/srv']]);
});

it('has an allow-list for every tab of the settings page (D58)', function () {
    expect(array_keys(ServerSettingsApi::READ))->toBe(ServerSettings::TABS)
        ->and(array_keys(ServerSettingsApi::WRITE))->toBe(array_values(array_diff(ServerSettings::TABS, ['dns'])))
        ->and(array_keys(UpdateServerSettingsRequest::RULES))->toBe(array_keys(ServerSettingsApi::WRITE));

    foreach (ServerSettingsApi::WRITE as $tab => $fields) {
        // A tab takes only fields it answers (and the page's "apply to all users" flag), each with its rules
        expect(array_values(array_diff($fields, [...array_keys(ServerSettingsApi::READ[$tab]), 'language_all'])))->toBe([], $tab)
            ->and(array_keys(UpdateServerSettingsRequest::RULES[$tab]))->toBe($fields, $tab)
            ->and(array_values(array_intersect($fields, array_keys(ServerSettingsApi::REFUSED))))->toBe([], $tab);
    }

    $security = array_keys(ServerSettingsApi::READ['security']);
    expect($security)->toContain(...['API_SYSTEM', 'LOGIN_STYLE', 'INACTIVE_SESSION_TIMEOUT', 'POLICY_CSRF_STRICTNESS', ...array_merge(...array_values(ServerSettings::SECURITY_POLICIES))])
        ->and(array_keys(ServerSettingsApi::READ['white-label']))->toContain(...ServerSettings::WHITE_LABEL_KEYS)
        ->and(ServerSettingsApi::WRITE['white-label'])->toBe(ServerSettings::WHITE_LABEL_KEYS);
});

it('never names a secret key and answers every secret as a set flag (D58)', function () {
    foreach (ServerSettingsApi::READ as $tab => $fields) {
        expect(array_values(array_intersect(array_keys($fields), ServerSettingsApi::NEVER)))->toBe([], $tab);
        foreach ($fields as $field => $type) {
            if (preg_match('/password$|^key$|^repo$|secret|token/i', $field) === 1) {
                expect($type)->toBe('secret', "{$tab}.{$field}");
            }
        }
    }
    expect(ServerSettingsApi::NEVER)->toContain('PHPMYADMIN_KEY', 'SERVER_SMTP_PASSWD');
});

it('refuses the API switches, the protection group, phpMyAdmin single sign-on and the logo (D58, D23)', function () {
    $d58 = ['API_SYSTEM', 'API', 'API_ALLOWED_IP', 'POLICY_SYSTEM_PROTECTED_ADMIN', 'POLICY_SYSTEM_HIDE_ADMIN', 'POLICY_SYSTEM_HIDE_SERVICES'];

    expect(array_keys(ServerSettingsApi::REFUSED))->toContain(...[...$d58, 'pma_sso', 'pma_restrict', 'PHPMYADMIN_KEY', 'logo'])
        ->and(array_values(array_intersect($d58, ServerSettings::SECURITY_POLICIES['protection'])))->toBe(ServerSettings::SECURITY_POLICIES['protection']);
    foreach (array_keys(ServerSettingsApi::REFUSED) as $field) {
        expect(ServerSettingsApi::refusal($field))->toStartWith($field.' cannot be changed through the API');
    }
});

it('reads every tab with exactly its allow-list', function () {
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net']]);
    $this->fake->on('v-get-sys-timezone')->returns("UTC\n");
    $this->fake->on('v-list-remote-dns-hosts')->returns('');
    $this->fake->on('v-list-backup-host-restic')->returns('');
    $this->fake->on('v-list-sys-arcpanel-ssl')->returnsJson(['ARCPANEL' => ['CRT' => 'crt', 'KEY' => 'key']]);
    $api = app(ServerSettingsApi::class);

    foreach (ServerSettings::TABS as $tab) {
        $values = $api->read($tab);
        expect(array_keys($values))->toBe(array_keys(ServerSettingsApi::READ[$tab]), $tab);
        foreach (ServerSettingsApi::READ[$tab] as $field => $type) {
            $value = $values[$field];
            match ($type) {
                'string' => expect($value)->toBeString(),
                '?string' => expect($value === null || is_string($value))->toBeTrue("{$tab}.{$field}"),
                'boolean' => expect($value)->toBeBool(),
                'integer' => expect($value)->toBeInt(),
                '?integer' => expect($value === null || is_int($value))->toBeTrue("{$tab}.{$field}"),
                'secret' => expect(array_keys($value))->toBe(['set']),
                default => expect($value)->toBeArray(),
            };
        }
    }
});

it('checks the fields of the settings tabs as the page did (D66)', function () {
    $actions = app(ServerSettingsActions::class);

    $actions->checkGeneral(['timezone' => 'UTC', 'language' => null, 'theme' => 'dark']);
    try {
        $actions->checkGeneral(['timezone' => 'Mars/Base', 'language' => 'xx', 'theme' => null]);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['timezone', 'language']);
    }

    expect($actions->installedPhp())->toBe(['8.4', '8.5'])
        ->and($actions->knowsDatabaseHost('mysql', 'localhost'))->toBeTrue()
        ->and($actions->knowsDatabaseHost('pgsql', 'pg.example.net'))->toBeFalse()
        ->and($actions->knowsDatabaseHost('mysql', 'db9.example.net'))->toBeFalse()
        ->and(ServerSettingsActions::knowsPlugin('quota'))->toBeTrue()
        ->and(ServerSettingsActions::knowsPlugin('app-installer'))->toBeFalse();

    $relay = ['relay' => true, 'relay_host' => 'smtp.example.net', 'relay_port' => '587', 'relay_user' => 'me', 'relay_password' => null];
    $actions->checkMail($relay);
    expect(fn () => $actions->checkMail(['relay_host' => 'smtp2.example.net'] + $relay))->toThrow(ValidationException::class);

    $backups = ['remote' => true, 'remote_type' => 'sftp', 'host' => 'backup.example.net', 'port' => '22', 'username' => 'arc', 'path' => '/srv',
        'bucket' => '', 'key_id' => '', 'remote_secret' => null];
    $actions->checkBackups($backups);
    $actions->checkBackups(['remote' => false] + $backups);
    try {
        $actions->checkBackups(['remote_type' => 'b2'] + $backups);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['bucket', 'key_id', 'password'])
            ->and($e->errors()['password'][0])->toBe('Field "Application Key" can not be blank.');
    }
});

it('plans only the given values', function () {
    $settings = app(ServerSettings::class);

    expect($settings->planUpdateNotifications(null, null))->toBe([])
        ->and($settings->planPolicies(['API_SYSTEM' => '0', 'POLICY_SYSTEM_HIDE_ADMIN' => 'yes']))->toBe([])
        ->and(array_map(fn ($s) => $s->args, $settings->planPolicies(['LOGIN_STYLE' => 'old'])))->toBe([['LOGIN_STYLE', 'old']]);
});

it('types the PHP version and plugin resources', function () {
    $request = Request::create('/');

    expect((new PhpVersionResource(['version' => '8.4', 'installed' => true, 'default' => false, 'used_by' => ['bob/shop.example']]))->resolve($request))
        ->toBe(['version' => '8.4', 'installed' => true, 'default' => false, 'used_by' => ['bob/shop.example']])
        ->and((new PluginResource(['name' => 'quota', 'enabled' => false]))->resolve($request))->toBe(['name' => 'quota', 'enabled' => false]);
});
