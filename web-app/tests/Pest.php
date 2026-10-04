<?php

use App\Arc\ArcCommandFake;
use App\Arc\SysConfig;
use Illuminate\Support\Sleep;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A user record as `v-list-user USER json` returns it (trimmed to the keys the
 * panel app reads).
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function userRecord(array $overrides = []): array
{
    return array_merge([
        'NAME' => 'Test User',
        'PACKAGE' => 'default',
        'WEB_DOMAINS' => 'unlimited',
        'DNS_DOMAINS' => 'unlimited',
        'MAIL_DOMAINS' => 'unlimited',
        'DATABASES' => 'unlimited',
        'CRON_JOBS' => 'unlimited',
        'BACKUPS' => '1',
        'SHELL' => 'nologin',
        'TWOFA' => '',
        'ROLE' => 'user',
        'SUSPENDED' => 'no',
        'LANGUAGE' => 'en',
        'THEME' => '',
        'PREF_UI_SORT' => 'name',
        'LOGIN_DISABLED' => 'no',
        'LOGIN_USE_IPLIST' => 'no',
        'LOGIN_ALLOW_IPS' => '',
    ], $overrides);
}

/**
 * `v-list-sys-config json` with the keys the panel app reads.
 *
 * @param  array<string, string>  $overrides
 * @return array{config: array<string, string>}
 */
function sysConfig(array $overrides = []): array
{
    return ['config' => array_merge([
        'APP_NAME' => 'ArcPanel',
        'ARCPANEL_VERSION' => '1.0.0',
        'VERSION' => '1.10.5',
        'LANGUAGE' => 'en',
        'DISABLE_IP_CHECK' => 'no',
        'INACTIVE_SESSION_TIMEOUT' => '60',
        'POLICY_USER_VIEW_SUSPENDED' => 'no',
        'POLICY_USER_CHANGE_THEME' => 'yes',
        'POLICY_SYSTEM_PROTECTED_ADMIN' => 'no',
        'POLICY_SYSTEM_PASSWORD_RESET' => 'yes',
        'ROOT_USER' => 'arcadmin',
    ], $overrides)];
}

/**
 * Install an ArcCommandFake that answers v-list-sys-config, and no sleeping.
 *
 * @param  array<string, string>  $config
 */
function fakeArc(array $config = []): ArcCommandFake
{
    Sleep::fake();
    $fake = ArcCommandFake::install();
    app()->forgetInstance(SysConfig::class);
    cache()->flush();
    $fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig($config));

    return $fake;
}

/**
 * Let `v-list-user NAME json` return these records; unknown users fail as on
 * a server (exit 3).
 *
 * @param  array<string, array<string, string>>  $users
 */
function fakeUsers(ArcCommandFake $fake, array $users): void
{
    $fake->on('v-list-user')->using(function (array $args) use ($users) {
        $name = $args[0];

        return isset($users[$name])
            ? [0, json_encode([$name => $users[$name]])]
            : [3, "Error: user {$name} doesn't exist"];
    });
}
