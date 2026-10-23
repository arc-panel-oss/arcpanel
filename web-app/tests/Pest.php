<?php

use App\Arc\ArcCommandFake;
use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\ArrayLegacySession;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Keys\AccessKeySets;
use App\View\Navigation;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
 * The `Link` and `Location` headers of an answer are those the committed
 * document declares for the operation and status (Fleet PD4,
 * docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §7).
 */
TestResponse::macro('assertDocumentedHeaders', function (string $operationId): TestResponse {
    /** @var TestResponse $this */
    static $operations = null;
    if ($operations === null) {
        $operations = [];
        $spec = json_decode((string) file_get_contents(base_path('../docs/api/v2/openapi.json')), true);
        foreach ($spec['paths'] as $methods) {
            foreach ($methods as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $operations[$operation['operationId']] = $operation;
                }
            }
        }
    }
    expect($operations)->toHaveKey($operationId);
    $status = (string) $this->getStatusCode();
    $documented = array_keys($operations[$operationId]['responses'][$status]['headers'] ?? []);
    expect(array_key_exists($status, $operations[$operationId]['responses']))->toBeTrue("{$operationId} documents no {$status}");
    foreach (['Link', 'Location'] as $header) {
        expect($this->headers->has($header))->toBe(in_array($header, $documented, true), "{$operationId} {$status} {$header}");
    }

    return $this;
});

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
        'TWOFA_RECOVERY_LEFT' => '0',
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
        'VERSION' => '2.0.0',
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
        'FIREWALL_SYSTEM' => 'nftables',
        'POLICY_USER_EDIT_WEB_TEMPLATES' => 'no',
        'POLICY_SYSTEM_HIDE_SERVICES' => 'no',
        'PLUGIN_APP_INSTALLER' => 'true',
        'SERVER_ID' => '7d3f5a2e-9c41-4b8e-a6d2-0f1e2d3c4b5a',
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
    // Trusted hosts (E39 W2) read the server addresses outside unit tests
    // (an "env = production" test); none by default.
    $fake->on('v-list-sys-ips', ['json'])->returnsJson([]);
    // The sidebar asks whether the account's package includes Node.js (E13);
    // without a package entry it does not.
    $fake->on('v-list-user')->returnsExit(ExitCode::NotExist);
    $fake->on('v-list-package-tenancy')->returnsJson([]);
    // The user detail page and the dashboard list Node.js apps (E14); none by default.
    $fake->on('v-list-node-apps')->using(fn (array $args) => [0, (string) json_encode(nodeAppList($args[0]))]);
    $fake->on('v-delete-node-app')->returnsExit(ExitCode::NotExist);
    // ... and app processes (E42): none, without the package grant
    $fake->on('v-list-app-processes')->using(fn (array $args) => [0, (string) json_encode(appProcessList($args[0], [], ['ENABLED' => 'no']))]);
    // The same for Docker apps (E4)
    $fake->on('v-list-docker-apps')->using(fn (array $args) => [0, (string) json_encode(dockerAppList($args[0]))]);
    // The app pages list the secret names for their reference pickers (E38); none by default.
    $fake->on('v-list-user-secret-store')->returnsJson([]);
    // PHP Manager (E41): installed versions 8.2 to 8.4 (8.3 the default), the
    // shipped profiles; web domains have no PHP tab unless a test answers it.
    $fake->on('v-list-sys-php-versions')->returnsJson(phpVersionRows());
    $fake->on('v-list-sys-php-profiles')->returnsJson(phpProfileRows());
    $fake->on('v-list-web-domain-php')->returnsExit(ExitCode::NotExist);
    // The Security tab of the server settings shows the size of the audit log (E23); empty by default.
    $fake->on('v-list-sys-audit')->returnsJson(['TOTAL' => 0, 'LIMIT' => 1, 'OFFSET' => 0, 'SIZE' => 0, 'EVENTS' => []]);
    // Task lists read the background malware scan of a user (E25); none by default.
    $fake->on('v-list-user-malware-task')->using(fn (array $args) => [0, (string) json_encode(['USER' => $args[0], 'ID' => '',
        'DOMAIN' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => ''])]);

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
    // Per-domain PHP settings of the PHP tab (E19)
    $fake->on('v-list-web-domain-php-settings')->returnsJson(domainPhpSettingsRow());
    $fake->on('v-delete-web-domain-tpl-vars')->returns();
    // Runtimes of the template badges (E5): no Node.js runtime, Python only
    $fake->on('v-list-user-node-versions')->returnsJson(['ENABLED' => 'no', 'VERSIONS' => []]);
    $fake->on('v-list-sys-packages')->returnsFixture('v-list-sys-packages/default.json');
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

/**
 * Backups of the test users (E7): bob has two archives, one restic snapshot
 * with web, database and cron, and snapshot folder entries; carol has none.
 */
function fakeBackupData(ArcCommandFake $fake): void
{
    $snapshot = '4f2a91c0';
    $archives = fn (string $user) => $user === 'bob' ? commandFixture('v-list-user-backups/bob.json') : [];
    $fake->on('v-list-user-backups')->using(fn (array $a) => [0, (string) json_encode((object) $archives($a[0]))]);
    $fake->on('v-list-user-backup')->using(function (array $a) use ($archives) {
        $row = $archives($a[0])[$a[1]] ?? null;

        return $row !== null ? [0, (string) json_encode([$a[1] => $row])] : [3, "Error: backup {$a[1]} doesn't exist"];
    });
    $fake->on('v-list-user-backups-restic')->using(fn (array $a) => [0, (string) json_encode($a[0] === 'bob' ? [[
        'time' => '2026-10-06T01:00:00.123Z', 'paths' => ['/home/bob'], 'hostname' => 'arcpanel-test', 'id' => $snapshot.str_repeat('0', 56), 'short_id' => $snapshot,
    ]] : [])]);
    $fake->on('v-list-user-backup-restic')->using(fn (array $a) => [0, (string) json_encode(['bob.2026-10-06_01-00-00' => [
        'TYPE' => 'restic', 'WEB' => 'example.com', 'DNS' => 'example.com', 'MAIL' => '', 'DB' => 'bob_wp', 'CRON' => 'yes', 'UDIR' => '',
    ]])]);
    $fake->on('v-list-user-files-restic')->using(fn (array $a) => [0, implode("\n", [
        json_encode(['struct_type' => 'snapshot', 'short_id' => $snapshot]),
        json_encode(['name' => 'tmp', 'type' => 'dir', 'path' => $a[2].'/tmp', 'mode' => 2147484141, 'struct_type' => 'node']),
        json_encode(['name' => 'note.txt', 'type' => 'file', 'path' => $a[2].'/note.txt', 'size' => 9, 'mode' => 420, 'struct_type' => 'node']),
    ])]);
    $fake->on('v-list-user-backup-exclusions')->returnsJson([
        'WEB' => ['example.com' => 'public_html/cache'], 'DNS' => ['example.com' => ''], 'MAIL' => [], 'DB' => [], 'CRON' => ['*' => ''], 'USER' => ['tmp' => '', 'apps/kuma' => ''],
    ]);
    // E48: the jobs and destinations of the server, the runs of bob, the records the exclusions pick from
    $fake->on('v-list-backup-jobs', ['json'])->returnsJson([
        '179267621466' => ['ID' => '179267621466', 'NAME' => 'Nightly archives', 'ENGINE' => 'archive', 'SCOPE' => 'all', 'EXCLUDE_USERS' => '', 'SCHEDULE' => 'daily:05:10',
            'TARGETS' => 'local:-1:-1:-1:-1:-1,offsite:-1:-1:-1:-1:-1', 'STATE' => 'enabled', 'NEXT_RUN' => '2026-10-23 05:10:00', 'LAST_STATUS' => 'success', 'RUNNING' => 'no'],
        '179267621499' => ['ID' => '179267621499', 'NAME' => 'Hourly snapshots', 'ENGINE' => 'snapshot', 'SCOPE' => 'users:bob', 'EXCLUDE_USERS' => '', 'SCHEDULE' => 'hourly:15',
            'TARGETS' => 'offsite:24:7:-1:-1:-1', 'STATE' => 'enabled', 'NEXT_RUN' => '2026-10-22 23:15:00', 'LAST_STATUS' => '', 'RUNNING' => 'no'],
    ]);
    $fake->on('v-list-backup-destinations', ['json'])->returnsJson([
        'local' => ['NAME' => 'local', 'DRIVER' => 'local', 'ENCRYPT' => 'no', 'PATH' => '/backup', 'STATE' => 'ok', 'SECRETS' => '', 'JOBS' => '179267621466'],
        'offsite' => ['NAME' => 'offsite', 'DRIVER' => 's3', 'ENCRYPT' => 'yes', 'BUCKET' => 'secret-bucket-name', 'PATH' => 'server1', 'STATE' => 'ok', 'SECRETS' => 'SECRET_ACCESS_KEY',
            'JOBS' => '179267621466,179267621499'],
        'unused' => ['NAME' => 'unused', 'DRIVER' => 'sftp', 'ENCRYPT' => 'no', 'HOST' => 'unused.example.net', 'STATE' => 'ok', 'SECRETS' => 'PASSWORD', 'JOBS' => ''],
    ]);
    $fake->on('v-list-user-backup-runs')->using(fn (array $a) => [0, (string) json_encode((object) ($a[0] === 'bob' ? [
        '17926789981107' => ['ENGINE' => 'tar', 'TRIGGER' => 'schedule', 'TASK' => '', 'STATUS' => 'succeeded', 'START' => '2026-10-22 05:10:02', 'END' => '2026-10-22 05:13:20',
            'EXIT' => '0', 'ERROR' => '', 'ARCHIVE' => BK_ARCHIVE_NAME, 'SIZE' => '412', 'PID' => '', 'JOB' => '179267621466', 'DESTS' => 'local:ok,offsite:failed'],
        '17926000000001' => ['ENGINE' => 'tar', 'TRIGGER' => 'request', 'TASK' => '17926000000001', 'STATUS' => 'failed', 'START' => '2026-10-21 10:00:00', 'END' => '2026-10-21 10:00:05',
            'EXIT' => '13', 'ERROR' => 'not enough disk space', 'ARCHIVE' => '', 'SIZE' => '', 'PID' => '', 'JOB' => '', 'DESTS' => ''],
    ] : []))]);
}

// Backup destinations, jobs and their runs of the server (E48), for the hub and REST API v2

/** `v-list-backup-destinations json`, as the command prints it */
function backupDestinations(array $overrides = []): array
{
    $rows = [
        'local' => ['NAME' => 'local', 'DRIVER' => 'local', 'DESCRIPTION' => '', 'ENCRYPT' => 'no', 'BWLIMIT' => '', 'RESTIC_PREFIX' => 'restic',
            'HOST_KEY' => '', 'STATE' => 'ok', 'CHECKED' => '2026-10-22 20:37:00', 'FAILURES' => '0', 'KIT' => '', 'CREATED' => '2026-10-22 20:36:54',
            'UPDATED' => '2026-10-22 20:36:54', 'PATH' => '/backup', 'SECRETS' => '', 'JOBS' => '179267621466'],
        'offsite' => ['NAME' => 'offsite', 'DRIVER' => 'sftp', 'DESCRIPTION' => 'Storage box', 'ENCRYPT' => 'yes', 'BWLIMIT' => '10M', 'RESTIC_PREFIX' => 'restic',
            'HOST_KEY' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'STATE' => 'failing', 'CHECKED' => '2026-10-22 21:00:00', 'FAILURES' => '3',
            'KIT' => '', 'CREATED' => '2026-10-22 20:40:00', 'UPDATED' => '2026-10-22 20:40:00', 'HOST' => 'box.example.net', 'PORT' => '23',
            'USERNAME' => 'u123', 'PATH' => 'arc', 'SECRETS' => 'PASSWORD', 'JOBS' => ''],
    ];

    return array_replace_recursive($rows, $overrides);
}

/** `v-list-backup-jobs json` */
function backupJobs(): array
{
    return ['179267621466' => ['ID' => '179267621466', 'NAME' => 'Nightly archives', 'ENGINE' => 'archive', 'SCOPE' => 'all', 'EXCLUDE_USERS' => 'carol',
        'SCHEDULE' => 'daily:05:10', 'TARGETS' => 'local:-1:-1:-1:-1:-1', 'STATE' => 'enabled', 'LAST_RUN' => '17926789985709',
        'NEXT_RUN' => '2026-10-23 05:10:00', 'NEXT_RUN_TS' => '1792707000', 'CREATED' => '2026-10-22 20:36:54', 'UPDATED' => '2026-10-22 21:23:09',
        'LAST_STATUS' => 'partial', 'LAST_END' => '2026-10-22 21:23:26', 'RUNNING' => 'no']];
}

/** `v-list-backup-job-runs [ID] json` */
function backupJobRuns(): array
{
    return [
        ['JOB' => '179267621466', 'JOB_NAME' => 'Nightly archives', 'RUN' => '17926789985709', 'TRIGGER' => 'schedule', 'STATUS' => 'partial',
            'START' => now()->subHour()->format('Y-m-d H:i:s'), 'END' => now()->subMinutes(50)->format('Y-m-d H:i:s'), 'USERS' => '2', 'DONE' => '2', 'FAILED' => '0', 'SKIPPED' => '1', 'PID' => ''],
        ['JOB' => '179267621466', 'JOB_NAME' => 'Nightly archives', 'RUN' => '17926000000001', 'TRIGGER' => 'cli', 'STATUS' => 'success',
            'START' => '2026-10-01 05:10:00', 'END' => '2026-10-01 05:12:00', 'USERS' => '2', 'DONE' => '2', 'FAILED' => '0', 'SKIPPED' => '0', 'PID' => ''],
    ];
}

/** The records the backup exclusions pick from (E48 spec §8.1); other tests fake these lists with helpers of their own. */
function fakeBackupObjects(ArcCommandFake $fake): void
{
    $fake->on('v-list-web-domains')->using(fn (array $a) => [0, (string) json_encode((object) ($a[0] === 'bob' ? ['example.com' => [], 'shop.example' => []] : []))]);
    $fake->on('v-list-mail-domains')->using(fn (array $a) => [0, (string) json_encode((object) ($a[0] === 'bob' ? ['example.com' => []] : []))]);
    $fake->on('v-list-databases')->using(fn (array $a) => [0, (string) json_encode((object) ($a[0] === 'bob' ? ['bob_wp' => [], 'bob_shop' => []] : []))]);
}

/** The newest archive of bob in tests/Fixtures/commands/v-list-user-backups/bob.json. */
const BK_ARCHIVE_NAME = 'bob.2026-10-03_05-10-09.tar';

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

/** Access key of the API v2 tests (E34): an id and a secret in the formats of func/main.sh. */
const API_KEY_ID = 'APIKEY0123456789abcd';

const API_SECRET = 'S3cret-0123456789abcdefghijklmnopqrstuvw';

/**
 * The commands of a permission set shipped in install/common/api/.
 *
 * @return list<string>
 */
function apiSetCommands(string $set): array
{
    $file = base_path('../install/common/api/'.AccessKeySets::fileOfSet($set));
    $content = is_file($file) ? (string) file_get_contents($file) : '';
    if (preg_match("/^COMMANDS='([^']*)'/m", $content, $m) !== 1) {
        throw new RuntimeException("No COMMANDS in {$file}");
    }

    return array_values(array_filter(explode(',', $m[1])));
}

/**
 * Area sets of API v2 (`v2-accounts`, `v2-web`, …), not the unions.
 *
 * @return list<string>
 */
function apiAreaSets(): array
{
    $sets = [];
    foreach (glob(base_path('../install/common/api/v2-*')) ?: [] as $file) {
        $name = AccessKeySets::setOfFile(basename($file));
        if (! in_array($name, ['v2-read', 'v2-write'], true)) {
            $sets[] = $name;
        }
    }
    sort($sets);

    return $sets;
}

/**
 * A fake for the API v2 tests: the API is on for every key from every
 * address unless $config says otherwise.
 *
 * @param  array<string, string>  $config
 */
function fakeApi(array $config = []): ArcCommandFake
{
    $fake = fakeArc(array_merge(['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all'], $config));
    // No app process task unless a test says otherwise: task lists read it (Fleet PD4)
    $fake->on('v-list-app-process-task')->returnsJson(['TASK' => null]);

    return $fake;
}

/**
 * Let the access key of the API tests belong to $user: `v-check-access-key-permissions`
 * answers for API_KEY_ID and API_SECRET only. Without $sets the key is
 * unrestricted (an administrator's key without sets); with sets its
 * commands are those of the shipped set files, so a test fails when an
 * endpoint runs a command its set does not list.
 *
 * @param  list<string>  $sets
 * @return array<string, string> the request headers
 */
function apiKey(ArcCommandFake $fake, string $user, string $role, array $sets = []): array
{
    $commands = $sets === [] ? ['*'] : array_values(array_unique(array_merge(...array_map(apiSetCommands(...), $sets))));
    $fake->on('v-check-access-key-permissions')->using(fn (array $args) => $args[0] === API_KEY_ID && $args[1] === API_SECRET
        ? [0, (string) json_encode(['ACCESS_KEY_ID' => API_KEY_ID, 'USER' => $user, 'ROLE' => $role, 'PERMISSIONS' => $sets, 'COMMANDS' => $commands])]
        : [9, 'Error: Invalid secret key for key '.$args[0]]);

    return ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET, 'Accept' => 'application/json'];
}

/**
 * The key a test signs in with: unrestricted for administrators, the v2-write
 * set (or v2-read for $readOnly) for everyone else, as the panel requires.
 * With $area the key holds only that area's set (D30), for an administrator too.
 *
 * @return array<string, string>
 */
function apiAs(ArcCommandFake $fake, string $user, string $role, bool $readOnly = false, ?string $area = null): array
{
    $sets = $area !== null ? ['v2-'.$area] : ($role === 'admin' ? [] : [$readOnly ? 'v2-read' : 'v2-write']);

    return apiKey($fake, $user, $role, $sets);
}

/**
 * Rows of `v-list-sys-php-versions json` (E41): 8.2 to 8.4 installed, 8.3
 * the default, 7.3 at its end of life, 8.5 available.
 *
 * @param  array<string, array<string, string>>  $overrides  version => keys
 * @return array<string, array<string, string>>
 */
function phpVersionRows(array $overrides = []): array
{
    $row = fn (string $installed, string $status, string $eol, string $installable = 'yes', string $extensions = '40') => [
        'INSTALLED' => $installed, 'DEFAULT' => 'no', 'USED_BY' => '', 'STATUS' => $status, 'RELEASED' => '2023-11-23',
        'ACTIVE_UNTIL' => '2025-12-31', 'EOL' => $eol, 'INSTALLABLE' => $installable, 'AVAILABLE' => 'yes',
        'EXTENSIONS' => $installed === 'yes' ? $extensions : '0',
    ];
    $rows = [
        '7.3' => $row('no', 'eol', '2021-12-06', 'no'),
        '8.2' => $row('yes', 'security', '2026-12-31'),
        '8.3' => ['DEFAULT' => 'yes', 'USED_BY' => 'bob/bob.example'] + $row('yes', 'security', '2027-12-31'),
        '8.4' => $row('yes', 'active', '2028-12-31'),
        '8.5' => $row('no', 'active', '2029-12-31'),
    ];
    foreach ($overrides as $version => $keys) {
        $rows[$version] = array_replace($rows[$version] ?? $row('no', 'active', ''), $keys);
    }

    return $rows;
}

/**
 * Rows of `v-list-sys-php-profiles json` (E41): the shipped profiles.
 *
 * @return array<string, array<string, string>>
 */
function phpProfileRows(array $extra = []): array
{
    $profile = fn (string $name, string $pm, string $children, string $memory, string $description) => [
        'NAME' => $name, 'DESCRIPTION' => $description, 'PM' => $pm, 'MAX_CHILDREN' => $children,
        'START_SERVERS' => $pm === 'dynamic' ? '4' : '', 'MIN_SPARE' => $pm === 'dynamic' ? '2' : '', 'MAX_SPARE' => $pm === 'dynamic' ? '6' : '',
        'MAX_REQUESTS' => '1000', 'IDLE_TIMEOUT' => $pm === 'ondemand' ? '10s' : '', 'REQUEST_TERMINATE_TIMEOUT' => '',
        'MEMORY_LIMIT' => $memory, 'UPLOAD_MAX_FILESIZE' => '', 'POST_MAX_SIZE' => '', 'MAX_EXECUTION_TIME' => '', 'MAX_INPUT_VARS' => '',
        'BUILTIN' => 'yes', 'DOMAINS' => '0', 'PACKAGES' => '',
    ];

    return [
        'default' => ['PACKAGES' => 'default'] + $profile('default', 'ondemand', '8', '', 'The pool settings of ArcPanel before profiles'),
        'small' => $profile('small', 'ondemand', '4', '128M', 'Small sites with little traffic'),
        'medium' => $profile('medium', 'dynamic', '20', '512M', 'Busy sites'),
        'large' => $profile('large', 'dynamic', '50', '1024M', 'Large shops and applications'),
    ] + $extra;
}

/**
 * Sidebar items of a principal keyed by key, with the pages of each hub
 * (sidebar hubs spec H1) listed next to it.
 */
function navItems(Principal $principal, ?string $route = null): Collection
{
    return collect(app(Navigation::class)->for($principal, $route))
        ->flatMap(fn ($group) => $group['items'])
        ->flatMap(fn ($item) => [$item, ...($item['children'] ?? [])])
        ->keyBy('key');
}

/**
 * Record of `v-list-app-process(es) json` (E42): a running web process by
 * default, a worker for any other name.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function appProcessRow(string $domain, string $name = 'web', array $overrides = []): array
{
    return array_merge([
        'ID' => '1', 'DOMAIN' => $domain, 'NAME' => $name, 'KIND' => $name === 'web' ? 'web' : 'worker',
        'PRESET' => $name === 'web' ? 'octane-swoole' : 'laravel-queue',
        'START' => '/usr/bin/php8.4 artisan octane:start --server=swoole --host=127.0.0.1 --port=30001',
        'CWD' => 'app', 'PORT' => $name === 'web' ? '30001' : '', 'MEMORY' => '512', 'CPUS' => '1',
        'TEMPLATE' => $name === 'web' ? 'laravel-octane' : '', 'PREV_TPL' => 'default', 'STATE' => 'running', 'SUSPENDED' => 'no',
        'TIME' => '10:00:00', 'DATE' => '2026-10-16', 'UNIT' => 'arcpanel-app-bob-1.service', 'STATUS' => 'running',
        'PID' => '4242', 'MEMORY_USED' => '120', 'CPU_SECONDS' => '3', 'RESTARTS' => '0', 'UPTIME' => '60',
    ], $overrides);
}

/**
 * Envelope of `v-list-app-processes USER json` (E42).
 *
 * @param  list<array<string, string>>  $rows
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function appProcessList(string $user, array $rows, array $overrides = []): array
{
    return array_merge([
        'USER' => $user, 'ENABLED' => 'yes', 'LIMIT' => '10', 'COUNT' => (string) count($rows),
        'MAX_MEMORY' => '768', 'DEFAULT_MEMORY' => '512', 'MAX_CPUS' => '1', 'PROCESSES' => $rows,
    ], $overrides);
}

// Object storage on Silo (E44)

/** @return array<string, string> one line of `v-list-user-buckets USER json` */
function storageBucket(string $name, array $overrides = []): array
{
    return array_merge(['BUCKET' => $name, 'QUOTA' => '100', 'VERSIONING' => 'no', 'USED' => '2048', 'OBJECTS' => '3',
        'SUSPENDED' => 'no', 'FULL' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-19'], $overrides);
}

/** @return array<string, string> `v-list-user-object-storage USER json` */
function storageAccount(array $overrides = []): array
{
    return array_merge(['INSTALLED' => 'yes', 'ALLOWED' => 'yes', 'BACKUP' => 'no', 'SUSPENDED' => 'no',
        'BUCKETS' => '1', 'BUCKETS_LIMIT' => '3', 'QUOTA_USED' => '100', 'QUOTA_LIMIT' => '1024', 'USED' => '2048',
        'OBJECTS' => '3', 'KEYS' => '1', 'KEYS_LIMIT' => '20', 'ENDPOINT' => 'https://s3.example.test', 'REGION' => 'us-east-1', 'PATH_STYLE' => 'yes'], $overrides);
}

/** A row of `v-list-web-domain-php USER DOMAIN json` (E41 §8). */
function domainPhpRow(array $overrides = []): array
{
    return array_replace([
        'DOMAIN' => 'example.com', 'VERSION' => '8.3', 'BACKEND' => 'PHP-8_3', 'PROFILE' => 'small', 'PROFILE_APPLIED' => 'yes',
        'VERSION_ALLOWED' => 'yes', 'PROFILE_ALLOWED' => 'yes', 'ALLOWED' => 'yes', 'PACKAGE' => 'default', 'DEFAULT_PROFILE' => 'default',
        'VERSIONS' => ['8.3' => ['STATUS' => 'security', 'EOL' => '2027-12-31'], '8.4' => ['STATUS' => 'active', 'EOL' => '2028-12-31']],
        'PROFILES' => ['default', 'small'],
        'EFFECTIVE' => [
            'memory_limit' => ['VALUE' => '128M', 'SOURCE' => 'profile'],
            'upload_max_filesize' => ['VALUE' => '100M', 'SOURCE' => 'php.ini'],
        ],
    ], $overrides);
}

/** A row of `v-list-web-domain-php-settings USER DOMAIN json` (E19 §5). */
function domainPhpSettingsRow(array $overrides = [], array $settings = []): array
{
    $setting = fn (string $value, string $max, string $ini, string $profile, string $effective, string $source) => [
        'VALUE' => $value, 'MAX' => $max, 'INI' => $ini, 'PROFILE' => $profile, 'EFFECTIVE' => $effective, 'SOURCE' => $source,
    ];

    return array_replace([
        'DOMAIN' => 'example.com', 'POOL' => 'domain', 'SUPPORTED' => 'yes', 'PACKAGE' => 'default', 'PROFILE' => 'small', 'VERSION' => '8.3',
        'NGINX_MAX_BODY' => '1024m',
        'SETTINGS' => array_replace([
            'memory_limit' => $setting('', '512M', '128M', '128M', '128M', 'profile'),
            'upload_max_filesize' => $setting('64M', '256M', '2M', '32M', '64M', 'domain'),
            'post_max_size' => $setting('64M', '256M', '8M', '32M', '64M', 'domain'),
            'max_execution_time' => $setting('', '300', '30', '60', '60', 'profile'),
            'max_input_vars' => $setting('', '10000', '1000', '2000', '2000', 'profile'),
            'display_errors' => $setting('', '', 'Off', '', 'Off', 'php.ini'),
            'date.timezone' => $setting('', '', 'UTC', '', 'UTC', 'php.ini'),
        ], $settings),
        'WARNINGS' => [],
    ], $overrides);
}
