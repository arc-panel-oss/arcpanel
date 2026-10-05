<?php

use App\Arc\ArcCommandFake;
use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\ArrayLegacySession;
use App\Auth\Principal;
use App\Auth\Role;
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
        'THEME' => 'dark',
        'DEBUG_MODE' => 'false',
        'WEB_SYSTEM' => 'apache2',
        'WEB_BACKEND' => 'php-fpm',
        'PROXY_SYSTEM' => 'nginx',
        'DNS_SYSTEM' => 'bind9',
        'MAIL_SYSTEM' => 'exim4',
        'DB_SYSTEM' => 'mysql',
        'CRON_SYSTEM' => 'cron',
        'BACKUP_SYSTEM' => 'local',
        'FTP_SYSTEM' => 'vsftpd',
        'FILE_MANAGER' => 'true',
        'WEB_TERMINAL' => 'false',
        'FIREWALL_SYSTEM' => 'iptables',
        'POLICY_USER_EDIT_WEB_TEMPLATES' => 'no',
        'POLICY_SYSTEM_HIDE_SERVICES' => 'no',
        'PLUGIN_APP_INSTALLER' => 'true',
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
    // The sidebar asks whether the account's package includes Node.js (E13);
    // without a package entry it does not.
    $fake->on('v-list-user')->returnsExit(ExitCode::NotExist);
    $fake->on('v-list-package-tenancy')->returnsJson([]);
    // The user detail page and the dashboard list Node.js apps (E14); none by default.
    $fake->on('v-list-node-apps')->using(fn (array $args) => [0, (string) json_encode(nodeAppList($args[0]))]);
    $fake->on('v-delete-node-app')->returnsExit(ExitCode::NotExist);
    // The same for Docker apps (E4)
    $fake->on('v-list-docker-apps')->using(fn (array $args) => [0, (string) json_encode(dockerAppList($args[0]))]);

    return $fake;
}

/**
 * Answer v-list-sys-config with these keys from now on (a test that changes
 * a policy after fakeArc()).
 *
 * @param  array<string, string>  $config
 */
function withSysConfig(ArcCommandFake $fake, array $config): void
{
    $fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig($config));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
}

/** Legacy session of a logged-in user (fresh activity, same IP as the test client). */
function sessionOf(string $user, string $role, array $overrides = []): array
{
    return array_merge([
        'user' => $user,
        'userContext' => $role,
        'user_combined_ip' => '127.0.0.1',
        'token' => str_repeat('e', 32),
        'LAST_ACTIVITY' => time(),
        'look' => '',
    ], $overrides);
}

/**
 * The users of the panel tests: administrator arcadmin and users bob and
 * carol. `v-list-user`, `v-list-users` and `v-list-user-owners` answer for
 * them. A record's OWNER key names its reseller (default: arcadmin).
 *
 * @param  array<string, array<string, string>>  $overrides  name => record keys
 * @return array<string, array<string, string>> the users
 */
function fakeServerUsers(ArcCommandFake $fake, array $overrides = []): array
{
    $users = [
        'arcadmin' => userRecord(['ROLE' => 'admin', 'NAME' => 'System Administrator']),
        'bob' => userRecord(['NAME' => 'Bob Builder', 'CONTACT' => 'bob@example.com']),
        'carol' => userRecord(['NAME' => 'Carol Shop']),
    ];
    foreach ($overrides as $name => $record) {
        $users[$name] = array_merge($users[$name] ?? userRecord(), $record);
    }
    fakeUsers($fake, $users);
    $fake->on('v-list-users', ['json'])->returnsJson($users);
    fakeOwners($fake, $users);
    // No ports unless a test says otherwise (E12)
    $fake->on('v-list-user-ports')->using(fn (array $args) => [0, (string) json_encode(portAccount($args[0]))]);

    return $users;
}

/**
 * `v-list-user-ports USER json` (E12 spec §5.3).
 *
 * @param  array<string, string>  $overrides
 * @param  list<array<string, string>>  $entries  from portEntry()
 * @return array<string, mixed>
 */
function portAccount(string $user, array $overrides = [], array $entries = []): array
{
    return array_merge([
        'USER' => $user, 'OWNER' => 'arcadmin', 'ROLE' => 'user', 'SUSPENDED' => 'no', 'LIMIT' => '0',
        'ALLOCATED' => '0', 'ASSIGNED' => '0', 'PUBLIC' => '0', 'FREE' => '0', 'TREE_TOTAL' => '', 'TREE_USED' => '',
        'FIREWALL' => 'iptables', 'RANGE_START' => '30000', 'RANGE_END' => '39999',
    ], $overrides) + ['ENTRIES' => $entries];
}

/**
 * One entry of `v-list-user-ports json`: a free port by default.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function portEntry(string $port, array $overrides = []): array
{
    [$start, $end] = array_pad(explode('-', $port, 2), 2, null);

    return array_merge([
        'PORT' => $port, 'START' => $start, 'END' => $end ?? $start, 'SIZE' => (string) ((int) ($end ?? $start) - (int) $start + 1),
        'PROTO' => 'tcp', 'APP' => '', 'PUBLIC' => 'no', 'PUBLIC_OK' => 'no', 'FW_RULE' => '', 'COMMENT' => '',
        'SUSPENDED' => 'no', 'LISTEN' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-05',
    ], $overrides);
}

/**
 * `v-list-user-owners json` for these records, resolved as func/tenancy.sh
 * does: administrators have no owner, users belong to the reseller in their
 * OWNER key when it is a reseller, everyone else to arcadmin.
 *
 * @param  array<string, array<string, string>>  $users
 */
function fakeOwners(ArcCommandFake $fake, array $users): void
{
    $owners = [];
    foreach ($users as $name => $record) {
        $role = $record['ROLE'] ?? 'user';
        $owner = $record['OWNER'] ?? '';
        $owners[$name] = [
            'ROLE' => $role,
            'OWNER' => match (true) {
                $role === 'admin' => '',
                $role === 'user' && $owner !== '' && ($users[$owner]['ROLE'] ?? '') === 'reseller' => $owner,
                default => 'arcadmin',
            },
            'SUSPENDED' => $record['SUSPENDED'] ?? 'no',
        ];
    }
    $fake->on('v-list-user-owners', ['json'])->returnsJson($owners);
}

/**
 * Two resellers on top of fakeServerUsers(): hostco owns bob, resb owns
 * carol and dave (without domains).
 *
 * @param  array<string, array<string, string>>  $overrides
 * @return array<string, array<string, string>>
 */
function fakeResellers(ArcCommandFake $fake, array $overrides = []): array
{
    $users = fakeServerUsers($fake, array_replace_recursive([
        'hostco' => ['ROLE' => 'reseller', 'NAME' => 'Host Co', 'CONTACT' => 'sales@hostco.example', 'PACKAGE' => 'reseller'],
        'resb' => ['ROLE' => 'reseller', 'NAME' => 'Reseller B', 'PACKAGE' => 'reseller'],
        'bob' => ['OWNER' => 'hostco'],
        'carol' => ['OWNER' => 'resb'],
        'dave' => ['OWNER' => 'resb', 'NAME' => 'Dave'],
    ], $overrides));
    foreach (['hostco', 'resb', 'dave'] as $name) {
        $fake->on('v-list-web-domains', [$name, 'json'])->returnsJson([]);
    }

    return $users;
}

/**
 * Sign in as $user (role from the fake users) through the legacy session.
 *
 * @param  array<string, mixed>  $session  extra session keys (look …)
 */
function signIn(string $user, string $role, array $session = []): ArrayLegacySession
{
    return ArrayLegacySession::install(sessionOf($user, $role, $session));
}

/** Fixture file under tests/Fixtures/commands/, decoded. */
function commandFixture(string $path): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/commands/'.$path)), true, 512, JSON_THROW_ON_ERROR);
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

/** A Principal for unit tests. */
function principal(string $user, Role $role, ?string $look = null, Role $effective = Role::User, bool $readOnly = false): Principal
{
    return new Principal(
        loginUser: $user,
        impersonated: $look,
        loginRole: $role,
        effectiveRole: $look === null ? $role : $effective,
        loginShell: 'nologin',
        language: 'en',
        theme: null,
        legacyToken: str_repeat('e', 32),
        readOnly: $readOnly,
    );
}

/**
 * Web domains of the test users from the fixtures: bob has example.com and
 * shop.example, carol has carol.example, arcadmin has none.
 */
function fakeWebDomains(ArcCommandFake $fake): void
{
    $fake->on('v-list-web-domains', ['bob', 'json'])->returnsFixture('v-list-web-domains/bob.json');
    $fake->on('v-list-web-domains', ['carol', 'json'])->returnsFixture('v-list-web-domains/carol.json');
    $fake->on('v-list-web-domains', ['arcadmin', 'json'])->returnsFixture('v-list-web-domains/empty.json');
    $fake->on('v-list-web-domain')->using(function (array $args) {
        $file = base_path("tests/Fixtures/commands/v-list-web-domain/{$args[0]}-{$args[1]}.json");

        return is_file($file) ? [0, (string) file_get_contents($file)] : [3, "Error: web domain {$args[1]} doesn't exist"];
    });
    // Later expectations win: the catch-all goes first.
    $fake->on('v-list-web-domain-ssl')->returnsJson([]);
    $fake->on('v-list-web-domain-ssl', ['bob', 'example.com', 'json'])->returnsFixture('v-list-web-domain-ssl/bob-example.com.json');
    $fake->on('v-list-sys-ips', ['json'])->returnsFixture('v-list-sys-ips/default.json');
    $fake->on('v-list-user-ips')->returnsFixture('v-list-user-ips/bob.json');
    $fake->on('v-list-web-templates', ['json'])->returnsFixture('v-list-web-templates/web.json');
    $fake->on('v-list-web-templates-backend', ['json'])->returnsFixture('v-list-web-templates/backend.json');
    $fake->on('v-list-web-templates-proxy', ['json'])->returnsFixture('v-list-web-templates/proxy.json');
    $fake->on('v-list-web-stats', ['json'])->returnsFixture('v-list-web-templates/stats.json');
    // Template metadata and domain variables (E6)
    $fake->on('v-list-web-templates-meta', ['web', 'json'])->returnsFixture('v-list-web-templates-meta/web.json');
    $fake->on('v-list-web-templates-meta', ['proxy', 'json'])->returnsFixture('v-list-web-templates-meta/proxy.json');
    $fake->on('v-list-web-domain-tpl-vars')->returnsFixture('v-list-web-domain-tpl-vars/none.json');
    $fake->on('v-delete-web-domain-tpl-vars')->returns();
}

/**
 * Databases of the test users (E7): bob has bob_wp and the suspended
 * bob_shop, carol has the PostgreSQL carol_app, everyone else none.
 * `v-list-database USER DB json` answers from the same fixtures.
 */
function fakeDatabases(ArcCommandFake $fake): void
{
    $list = function (string $user): array {
        $file = base_path("tests/Fixtures/commands/v-list-databases/{$user}.json");

        return is_file($file) ? commandFixture("v-list-databases/{$user}.json") : [];
    };
    $fake->on('v-list-databases')->using(fn (array $args) => [0, (string) json_encode((object) $list($args[0]))]);
    $fake->on('v-list-database')->using(function (array $args) use ($list) {
        $row = $list($args[0])[$args[1]] ?? null;

        return $row !== null ? [0, (string) json_encode([$args[1] => $row])] : [3, "Error: database {$args[1]} doesn't exist"];
    });
    $fake->on('v-list-database-hosts')->returnsFixture('v-list-database-hosts/default.json');
}

/**
 * Cron jobs of the test users (E7): bob has job 1 and the suspended job 2,
 * carol has job 1, everyone else none. `v-list-cron-job USER JOB json`
 * answers from the same fixtures; `v-get-sys-timezone` answers UTC.
 */
function fakeCronJobs(ArcCommandFake $fake): void
{
    $list = function (string $user): array {
        $file = base_path("tests/Fixtures/commands/v-list-cron-jobs/{$user}.json");

        return is_file($file) ? commandFixture("v-list-cron-jobs/{$user}.json") : [];
    };
    $fake->on('v-list-cron-jobs')->using(fn (array $args) => [0, (string) json_encode((object) $list($args[0]))]);
    $fake->on('v-list-cron-job')->using(function (array $args) use ($list) {
        $row = $list($args[0])[$args[1]] ?? null;

        return $row !== null ? [0, (string) json_encode([$args[1] => $row])] : [3, "Error: cron job {$args[1]} doesn't exist"];
    });
    $fake->on('v-get-sys-timezone')->returns("UTC\n");
}

/**
 * DNS zones of the test users (E7): bob has example.com (six records, the
 * last one suspended) and the suspended shop.example with DNSSEC, carol has
 * carol.example, everyone else none. `v-list-dns-domain`,
 * `v-list-dns-records` and `v-list-dnssec-public-key` answer from the same
 * fixtures; the users' IPs (bob's fixture) and name servers and the DNS
 * templates come with them.
 */
function fakeDnsZones(ArcCommandFake $fake): void
{
    $list = function (string $user): array {
        $file = base_path("tests/Fixtures/commands/v-list-dns-domains/{$user}.json");

        return is_file($file) ? commandFixture("v-list-dns-domains/{$user}.json") : [];
    };
    $missing = fn (string $domain) => [3, "Error: dns domain {$domain} doesn't exist"];
    $fake->on('v-list-dns-domains')->using(fn (array $args) => [0, (string) json_encode((object) $list($args[0]))]);
    $fake->on('v-list-dns-domain')->using(function (array $args) use ($list, $missing) {
        $row = $list($args[0])[$args[1]] ?? null;

        return $row !== null ? [0, (string) json_encode([$args[1] => $row])] : $missing($args[1]);
    });
    $fake->on('v-list-dns-records')->using(function (array $args) use ($list, $missing) {
        if (! isset($list($args[0])[$args[1]])) {
            return $missing($args[1]);
        }
        $file = base_path("tests/Fixtures/commands/v-list-dns-records/{$args[0]}-{$args[1]}.json");

        return [0, is_file($file) ? (string) file_get_contents($file) : '{}'];
    });
    $fake->on('v-list-dnssec-public-key')->using(function (array $args) use ($list, $missing) {
        if (! isset($list($args[0])[$args[1]])) {
            return $missing($args[1]);
        }
        $file = base_path("tests/Fixtures/commands/v-list-dnssec-public-key/{$args[0]}-{$args[1]}.json");
        $empty = [$args[1] => ['RECORD' => '', 'KEYTAG' => '', 'FLAG' => '', 'ALGORITHM' => '', 'KEY' => '', 'DS' => '']];

        return [0, is_file($file) ? (string) file_get_contents($file) : (string) json_encode($empty)];
    });
    $fake->on('v-list-dns-templates', ['json'])->returnsJson(['office365', 'child-ns', 'default', 'zoho', 'gmail', 'default-nomail']);
    $fake->on('v-list-user-ns')->returnsJson(['ns1.example.net', 'ns2.example.net']);
    $fake->on('v-list-user-ips')->returnsFixture('v-list-user-ips/bob.json');
}

/**
 * Mail domains of the test users (E7): bob has example.com (webmail,
 * DKIM, spam filter, three accounts) and the suspended shop.example with a
 * Let's Encrypt certificate and an SMTP relay, carol has carol.example,
 * everyone else none. `v-list-mail-domain` answers from
 * v-list-mail-domain/USER-DOMAIN.json, else from the list row;
 * `v-list-mail-accounts`, `v-list-mail-account` and the autoreply, DKIM and
 * SSL commands answer from their fixtures. The server has the roundcube
 * and snappymail webmail clients and bob's IPs.
 */
function fakeMailDomains(ArcCommandFake $fake): void
{
    $list = function (string $user): array {
        $file = base_path("tests/Fixtures/commands/v-list-mail-domains/{$user}.json");

        return is_file($file) ? commandFixture("v-list-mail-domains/{$user}.json") : [];
    };
    $missing = fn (string $domain) => [3, "Error: mail domain {$domain} doesn't exist"];
    $accounts = function (string $user, string $domain): array {
        $file = base_path("tests/Fixtures/commands/v-list-mail-accounts/{$user}-{$domain}.json");

        return is_file($file) ? commandFixture("v-list-mail-accounts/{$user}-{$domain}.json") : [];
    };
    $fake->on('v-list-mail-domains')->using(fn (array $args) => [0, (string) json_encode((object) $list($args[0]))]);
    $fake->on('v-list-mail-domain')->using(function (array $args) use ($list, $missing) {
        $row = $list($args[0])[$args[1]] ?? null;
        if ($row === null) {
            return $missing($args[1]);
        }
        $file = base_path("tests/Fixtures/commands/v-list-mail-domain/{$args[0]}-{$args[1]}.json");
        if (is_file($file)) {
            return [0, (string) file_get_contents($file)];
        }
        $row = array_merge($row, [
            'SSL_KEY_ALGO' => '', 'LETSENCRYPT' => 'no', 'WEBMAIL_ALIAS' => $row['WEBMAIL_ALIAS'].'.'.$args[1],
            'U_SMTP_RELAY' => '', 'U_SMTP_RELAY_HOST' => '', 'U_SMTP_RELAY_PORT' => '', 'U_SMTP_RELAY_USERNAME' => '',
        ]);

        return [0, (string) json_encode([$args[1] => $row])];
    });
    $fake->on('v-list-mail-accounts')->using(function (array $args) use ($list, $missing, $accounts) {
        return isset($list($args[0])[$args[1]])
            ? [0, (string) json_encode((object) $accounts($args[0], $args[1]))]
            : $missing($args[1]);
    });
    $fake->on('v-list-mail-account')->using(function (array $args) use ($list, $missing, $accounts) {
        if (! isset($list($args[0])[$args[1]])) {
            return $missing($args[1]);
        }
        $row = $accounts($args[0], $args[1])[$args[2]] ?? null;

        return $row !== null
            ? [0, (string) json_encode([$args[2] => array_merge($row, ['RATE_LIMIT' => '', 'WEBMAIL_ALIAS' => 'webmail.'.$args[1]])])]
            : [3, "Error: mail account {$args[2]} doesn't exist"];
    });
    $fake->on('v-list-mail-account-autoreply')->using(fn (array $args) => [0, (string) json_encode([$args[2] => ['MSG' => 'Away\nback soon']])]);
    $fake->on('v-list-mail-domain-dkim-dns')->using(function (array $args) {
        $file = base_path("tests/Fixtures/commands/v-list-mail-domain-dkim-dns/{$args[0]}-{$args[1]}.json");

        return [0, is_file($file) ? (string) file_get_contents($file) : '{}'];
    });
    $fake->on('v-list-mail-domain-ssl')->using(function (array $args) {
        $file = base_path("tests/Fixtures/commands/v-list-mail-domain-ssl/{$args[0]}-{$args[1]}.json");
        $empty = [$args[1] => ['CRT' => '', 'KEY' => '', 'CA' => '', 'SUBJECT' => '', 'ALIASES' => '', 'NOT_BEFORE' => '', 'NOT_AFTER' => '', 'SIGNATURE' => '', 'PUB_KEY' => '', 'ISSUER' => '']];

        return [0, is_file($file) ? (string) file_get_contents($file) : (string) json_encode($empty)];
    });
    $fake->on('v-list-sys-webmail', ['json'])->returnsJson(['roundcube', 'snappymail']);
    $fake->on('v-list-user-ips')->returnsFixture('v-list-user-ips/bob.json');
}

/** Restart commands answer success. */
function fakeRestarts(ArcCommandFake $fake): void
{
    foreach (['v-restart-web', 'v-restart-proxy', 'v-restart-dns', 'v-restart-web-backend'] as $restart) {
        $fake->on($restart)->returns();
    }
}

/**
 * `v-list-user-node-versions USER json` (E13 spec §5.4).
 *
 * @param  array<string, string>  $overrides
 * @param  list<array<string, string>>  $versions  from nodeRuntime()
 * @return array<string, mixed>
 */
function nodeAccount(string $user, array $overrides = [], array $versions = []): array
{
    return array_merge([
        'USER' => $user, 'PACKAGE' => 'developer', 'NODE' => 'yes', 'ALLOWED' => 'lts', 'LIMIT' => '3',
        'ENABLED' => 'yes', 'SHELL' => 'yes', 'SUSPENDED' => 'no', 'DEFAULT' => $versions[0]['VERSION'] ?? '',
        'ARCH' => 'x64', 'COUNT' => (string) count($versions), 'VERSIONS' => $versions,
    ], $overrides);
}

/**
 * One runtime of `v-list-user-node-versions json`.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function nodeRuntime(string $version, array $overrides = []): array
{
    return array_merge([
        'VERSION' => $version, 'MAJOR' => explode('.', $version)[0], 'DEFAULT' => 'no', 'LTS' => 'Krypton',
        'STATUS' => 'active', 'EOL' => '2028-04-30', 'SIZE' => '208', 'PACKAGES' => '0',
    ], $overrides);
}

/**
 * `v-list-user-node-task USER LINES json` (E13 spec §5.6): no task by default.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function nodeTask(string $user, array $overrides = []): array
{
    return array_merge([
        'USER' => $user, 'ID' => '', 'ACTION' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '',
        'START' => '', 'END' => '', 'LOG' => '',
    ], $overrides);
}

/**
 * One app of `v-list-node-apps USER json` (E14 spec §5.4), online.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function nodeApp(string $domain, array $overrides = []): array
{
    return array_merge([
        'DOMAIN' => $domain, 'NAME' => $domain, 'START' => 'server.js', 'CWD' => 'public_html', 'NODE_VERSION' => 'default',
        'PORT' => '30010', 'INSTANCES' => '1', 'MAX_MEMORY' => '512', 'TEMPLATE' => 'nodejs-proxy', 'PREV_TPL' => 'default',
        'STATE' => 'running', 'SUSPENDED' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-05', 'RUNTIME' => '24.21.0',
        'STATUS' => 'online', 'REASON' => '', 'PID' => '4242', 'CPU' => '0.5', 'MEMORY' => '48', 'RESTARTS' => '0', 'UPTIME' => '120',
    ], $overrides);
}

/**
 * `v-list-node-apps USER json` (E14 spec §5.4).
 *
 * @param  list<array<string, string>>  $apps  from nodeApp()
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function nodeAppList(string $user, array $apps = [], array $overrides = []): array
{
    return array_merge([
        'USER' => $user, 'NODE' => 'yes', 'LIMIT' => '3', 'COUNT' => (string) count($apps), 'DAEMON' => $apps === [] ? 'no' : 'yes',
        'PM2_RUNTIME' => $apps === [] ? '' : '24.21.0', 'MAX_INSTANCES' => '4', 'MEMORY_LIMIT' => '', 'APPS' => $apps,
    ], $overrides);
}

/**
 * One app of `v-list-docker-apps USER json` (E4 spec §5.5), running.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dockerApp(string $name, array $overrides = []): array
{
    return array_merge([
        'NAME' => $name, 'SLUG' => 'uptime-kuma', 'VERSION' => '2.5.5', 'DOMAIN' => $name.'.example', 'WEB_PORT' => '30020',
        'EXTRA_PORTS' => '', 'STATE' => 'running', 'STATUS' => 'running', 'REASON' => '', 'SUSPENDED' => 'no',
        'TEMPLATE' => 'docker-proxy', 'PREV_TPL' => 'default', 'PREV_VERSION' => '', 'CPUS' => '0.5', 'MEMORY' => '512',
        'TIME' => '10:00:00', 'DATE' => '2026-10-05', 'APP_NAME' => 'Uptime Kuma', 'LATEST' => '2.5.5', 'UPDATE' => 'no',
        'URL' => 'http://'.$name.'.example', 'SSL' => 'no', 'DOCKER_STATE' => 'running', 'CONTAINERS' => '1', 'RUNNING' => '1',
        'CPU' => '0.4', 'MEM' => '96',
    ], $overrides);
}

/**
 * `v-list-docker-apps USER json` (E4 spec §5.5).
 *
 * @param  list<array<string, mixed>>  $apps  from dockerApp()
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function dockerAppList(string $user, array $apps = [], array $overrides = []): array
{
    return array_merge([
        'USER' => $user, 'SYSTEM' => 'yes', 'DOCKER' => 'yes', 'LIMIT' => '3', 'COUNT' => (string) count($apps), 'APPS' => $apps,
    ], $overrides);
}

/**
 * `v-list-docker-app-task USER NAME OFFSET json` or `v-list-sys-docker-task OFFSET json`.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function dockerTask(array $overrides = []): array
{
    return array_merge([
        'ID' => '', 'ACTION' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '', 'OFFSET' => '0',
    ], $overrides);
}

/**
 * A row of `v-list-docker-catalog all json`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dockerCatalogRow(string $slug, array $overrides = []): array
{
    return array_merge([
        'SLUG' => $slug, 'NAME' => ucfirst($slug), 'SUMMARY' => 'An app.', 'CATEGORY' => 'monitoring', 'VERSION' => '1.0.0',
        'VERSIONS' => ['1.0.0'], 'ADMIN_ONLY' => false, 'ARCH' => ['amd64'], 'REQUIREMENTS' => ['memory' => '256M', 'disk' => '1G'],
        'HOMEPAGE' => 'https://example.com', 'LICENSE' => 'MIT', 'WEB' => true, 'ICON' => 'data:image/svg+xml;base64,PHN2Zy8+',
    ], $overrides);
}
