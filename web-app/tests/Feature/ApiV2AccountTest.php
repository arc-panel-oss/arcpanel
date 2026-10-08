<?php

use App\Arc\ExitCode;
use App\Services\Keys\SshKeyService;

// REST API v2, account endpoints (E34 batch 6 spec §4.1–§4.4): logs,
// statistics, notifications, search and SSH keys. hostco owns bob, resb
// owns carol and dave; arcadmin is the administrator.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    // The action log: entry N is the Nth line of history.log, newest last.
    $this->fake->on('v-list-user-log')->using(function (array $args) {
        $lines = (int) ($args[2] ?? 300);
        $rows = [];
        for ($i = max(1, 250 - $lines + 1); $i <= 250; $i++) {
            $rows[(string) $i] = ['DATE' => '2026-10-08', 'TIME' => sprintf('10:%02d:%02d', intdiv($i, 60), $i % 60), 'LEVEL' => 'Info', 'CATEGORY' => 'Web', 'MESSAGE' => "Entry {$i} of {$args[0]}."];
        }

        return [0, (string) json_encode($rows)];
    });
    $this->fake->on('v-list-user-auth-log')->returnsFixture('v-list-user-auth-log/arcadmin.json');
    $this->fake->on('v-delete-user-log')->returns();
    $this->fake->on('v-delete-user-auth-log')->returns();
});

// Logs (§4.1, D60)

it('reads the action log inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/logs", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonCount(100, 'data')
            ->assertJsonPath('data.0.message', "Entry 250 of {$owner}.")
            ->assertJsonPath('data.0.time', '2026-10-08T10:04:10')
            ->assertJsonPath('data.0.level', 'Info')
            ->assertJsonPath('data.0.category', 'Web')
            ->assertJsonPath('meta', ['limit' => 100, 'offset' => 0, 'more' => true]);
        $this->fake->assertRan('v-list-user-log', fn ($a) => $a === [$owner, 'json', '101']);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('pages the newest log entries with a key that holds only v2-accounts', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');

    $this->getJson('/api/v2/users/bob/logs?limit=2&offset=1', $headers)->assertOk()
        ->assertJsonPath('data.0.message', 'Entry 249 of bob.')
        ->assertJsonPath('data.1.message', 'Entry 248 of bob.')
        ->assertJsonPath('meta', ['limit' => 2, 'offset' => 1, 'more' => true]);
    $this->fake->assertRan('v-list-user-log', fn ($a) => $a === ['bob', 'json', '4']);

    $this->getJson('/api/v2/users/bob/logs?limit=1000', $headers)->assertOk()
        ->assertJsonCount(250, 'data')->assertJsonPath('meta.more', false);
    $this->fake->assertRan('v-list-user-log', fn ($a) => $a === ['bob', 'json', '1001']);

    $this->getJson('/api/v2/users/bob/logs?offset=249', $headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.more', false);
    $this->getJson('/api/v2/users/bob/logs?limit=1001', $headers)->assertUnprocessable();
    $this->getJson('/api/v2/users/bob/logs?limit=0', $headers)->assertUnprocessable();
    $this->getJson('/api/v2/users/bob/logs?offset=9000', $headers)->assertUnprocessable();
});

it('reads the login history newest first', function () {
    $this->getJson('/api/v2/users/bob/auth-logs?limit=1', apiAs($this->fake, 'bob', 'user', area: 'accounts'))->assertOk()
        ->assertJsonPath('data.0', ['time' => '2026-10-04T10:12:44', 'ip' => '192.0.2.191', 'success' => false, 'user_agent' => 'curl/8.5.0'])
        ->assertJsonPath('meta', ['limit' => 1, 'offset' => 0, 'more' => true]);
    $this->fake->assertRan('v-list-user-auth-log', fn ($a) => $a === ['bob', 'json', '2']);
});

it('follows POLICY_USER_VIEW_LOGS for everyone but administrators', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_USER_VIEW_LOGS' => 'no']);

    $this->getJson('/api/v2/users/bob/logs', apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->getJson('/api/v2/users/bob/logs', apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->getJson('/api/v2/users/bob/auth-logs', apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->getJson('/api/v2/users/bob/logs', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-list-user-log', null, 1);
});

it('hides the login history in demo mode and protects the root administrator\'s', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'DEMO_MODE' => 'yes']);
    $this->getJson('/api/v2/users/bob/auth-logs', apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->getJson('/api/v2/users/bob/logs', apiAs($this->fake, 'bob', 'user'))->assertOk();

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeResellers($this->fake, ['otheradmin' => ['ROLE' => 'admin']]);
    $this->getJson('/api/v2/users/arcadmin/auth-logs', apiAs($this->fake, 'otheradmin', 'admin'))->assertForbidden();
    $this->deleteJson('/api/v2/users/arcadmin/auth-logs', [], apiAs($this->fake, 'otheradmin', 'admin'))->assertForbidden();
    $this->getJson('/api/v2/users/bob/auth-logs', apiAs($this->fake, 'otheradmin', 'admin'))->assertOk();
    $this->getJson('/api/v2/users/arcadmin/auth-logs', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertNotRan('v-delete-user-auth-log');
});

it('clears logs with the rules of the page', function (string $principal, string $role, string $owner, int $status) {
    $headers = apiAs($this->fake, $principal, $role, area: 'accounts');

    $this->deleteJson("/api/v2/users/{$owner}/logs", [], $headers)->assertStatus($status);
    $this->deleteJson("/api/v2/users/{$owner}/auth-logs", [], $headers)->assertStatus($status);
    if ($status === 204) {
        $this->fake->assertRan('v-delete-user-log', fn ($a) => $a === [$owner]);
        $this->fake->assertRan('v-delete-user-auth-log', fn ($a) => $a === [$owner]);
    } else {
        $this->fake->assertNotRan('v-delete-user-log');
        $this->fake->assertNotRan('v-delete-user-auth-log');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 204],
    'reseller A, its own' => ['hostco', 'reseller', 'hostco', 204],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 403],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 204],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('follows POLICY_USER_DELETE_LOGS, plans without running and refuses a v2-read key', function () {
    $this->deleteJson('/api/v2/users/bob/logs?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-user-log')
        ->assertJsonPath('data.steps.0.args', ['bob']);
    $this->deleteJson('/api/v2/users/bob/logs', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-delete-user-log']);
    $this->deleteJson('/api/v2/users/bob/auth-logs', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-delete-user-auth-log']);
    $this->fake->assertNotRan('v-delete-user-log');
    $this->fake->assertNotRan('v-delete-user-auth-log');

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_USER_DELETE_LOGS' => 'no']);
    $this->deleteJson('/api/v2/users/bob/logs', [], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/logs', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertNoContent();
});

// Statistics (§4.2)

/** A month of `v-list-*-stats json`. */
function apiStatsMonth(array $overrides = []): array
{
    return array_merge([
        'TIME' => '01:00:00', 'PACKAGE' => 'default', 'IP_OWNED' => '0', 'DISK_QUOTA' => 'unlimited', 'U_DISK' => '150',
        'U_DISK_DIRS' => '10', 'U_DISK_WEB' => '100', 'U_DISK_MAIL' => '30', 'U_DISK_DB' => '10', 'BANDWIDTH' => '1000',
        'U_BANDWIDTH' => '20', 'U_WEB_DOMAINS' => '2', 'U_WEB_SSL' => '1', 'U_WEB_ALIASES' => '3', 'U_DNS_DOMAINS' => '2',
        'U_DNS_RECORDS' => '24', 'U_MAIL_DOMAINS' => '1', 'U_MAIL_DKIM' => '1', 'U_MAIL_ACCOUNTS' => '4', 'U_DATABASES' => '2',
        'U_CRON_JOBS' => '1', 'U_BACKUPS' => '3',
    ], $overrides);
}

it('reads the months of a user inside the tree, newest first', function (string $principal, string $role, string $owner, int $status) {
    $this->fake->on('v-list-user-stats')->using(fn (array $args) => [0, (string) json_encode([
        '2026-09-01' => apiStatsMonth(['U_DISK' => '120']),
        '2026-10-01' => apiStatsMonth(['DISK_QUOTA' => '2000']),
    ])]);

    $response = $this->getJson("/api/v2/users/{$owner}/stats", apiAs($this->fake, $principal, $role, readOnly: true, area: 'accounts'))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.month', '2026-10')
            ->assertJsonPath('data.0.disk_mb', ['used' => 150, 'limit' => 2000, 'web' => 100, 'mail' => 30, 'databases' => 10, 'directories' => 10])
            ->assertJsonPath('data.0.bandwidth_mb', ['used' => 20, 'limit' => 1000])
            ->assertJsonPath('data.0.users', null)
            ->assertJsonPath('data.0.dns_records', 24)
            ->assertJsonPath('data.1.month', '2026-09')
            ->assertJsonPath('data.1.disk_mb.limit', null);
        $this->fake->assertRan('v-list-user-stats', fn ($a) => $a === [$owner, 'json']);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('reads the server totals for administrators and the tree for resellers, not for users', function () {
    $this->fake->on('v-list-users-stats')->returnsJson(['2026-10-01' => apiStatsMonth(['U_USERS' => '5', 'DISK_QUOTA' => '0'])]);
    $this->fake->on('v-list-reseller-users-stats')->returnsJson(['2026-10-01' => apiStatsMonth(['U_USERS' => '2'])]);

    $this->getJson('/api/v2/stats', apiAs($this->fake, 'arcadmin', 'admin', area: 'accounts'))->assertOk()
        ->assertJsonPath('data.0.users', 5)
        ->assertJsonPath('data.0.disk_mb.limit', null);
    $this->fake->assertRan('v-list-users-stats');

    $this->getJson('/api/v2/stats', apiAs($this->fake, 'hostco', 'reseller', area: 'accounts'))->assertOk()
        ->assertJsonPath('data.0.users', 2);
    $this->fake->assertRan('v-list-reseller-users-stats', fn ($a) => $a === ['hostco', 'json']);

    $this->getJson('/api/v2/stats', apiAs($this->fake, 'bob', 'user', area: 'accounts'))->assertForbidden();
});

// Notifications (§4.3, D64)

it('lists the notifications of the key owner as plain text', function () {
    $this->fake->on('v-list-user-notifications', ['bob', 'json'])->returnsJson([
        '1' => ['TOPIC' => 'Welcome', 'NOTICE' => '<p>Read the <a href="/docs">docs</a>.</p>', 'ACK' => 'yes', 'TIME' => '09:00:00', 'DATE' => '2026-10-01'],
        '2' => ['TOPIC' => 'Update &amp; news', 'NOTICE' => 'Version 2.0', 'ACK' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-02'],
    ]);

    $this->getJson('/api/v2/notifications', apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'accounts'))->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0', ['id' => 2, 'title' => 'Update & news', 'body' => 'Version 2.0', 'acknowledged' => false, 'created_at' => '2026-10-02T10:00:00'])
        ->assertJsonPath('data.1.body', 'Read the docs.');
});

it('acknowledges and deletes notifications with a key that holds only v2-accounts', function () {
    $this->fake->on('v-list-user-notifications', ['hostco', 'json'])->returnsJson([
        '7' => ['TOPIC' => 'Hi', 'NOTICE' => 'x', 'ACK' => 'no', 'TIME' => '09:00:00', 'DATE' => '2026-10-01'],
    ]);
    $this->fake->on('v-acknowledge-user-notification')->returns();
    $this->fake->on('v-delete-user-notification')->returns();
    $headers = apiAs($this->fake, 'hostco', 'reseller', area: 'accounts');

    $this->postJson('/api/v2/notifications/7/acknowledge', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-acknowledge-user-notification', fn ($a) => $a === ['hostco', '7']);
    $this->postJson('/api/v2/notifications/acknowledge', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-acknowledge-user-notification', fn ($a) => $a === ['hostco', 'all']);
    $this->deleteJson('/api/v2/notifications/7', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-notification', fn ($a) => $a === ['hostco', '7']);
    $this->deleteJson('/api/v2/notifications', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-notification', fn ($a) => $a === ['hostco', 'all']);

    $this->postJson('/api/v2/notifications/8/acknowledge', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/notifications/8', [], $headers)->assertNotFound();
    $this->fake->assertRan('v-acknowledge-user-notification', null, 2);
});

it('refuses notification changes on a read-only key and plans them in a dry run (D64)', function () {
    $this->fake->on('v-list-user-notifications', ['bob', 'json'])->returnsJson([
        '3' => ['TOPIC' => 'Hi', 'NOTICE' => 'x', 'ACK' => 'no', 'TIME' => '09:00:00', 'DATE' => '2026-10-01'],
    ]);
    $readOnly = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/notifications/3/acknowledge', [], $readOnly)->assertForbidden()
        ->assertJsonPath('error.commands', ['v-acknowledge-user-notification']);
    $this->postJson('/api/v2/notifications/acknowledge', [], $readOnly)->assertForbidden();
    $this->deleteJson('/api/v2/notifications/3', [], $readOnly)->assertForbidden()
        ->assertJsonPath('error.commands', ['v-delete-user-notification']);
    $this->deleteJson('/api/v2/notifications', [], $readOnly)->assertForbidden();

    $this->deleteJson('/api/v2/notifications?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'all']);
    $this->fake->assertNotRan('v-acknowledge-user-notification');
    $this->fake->assertNotRan('v-delete-user-notification');
});

// Search (§4.3)

/** Rows of `v-search-object json` over the users of the tests. */
function apiSearchRows(): array
{
    $row = fn (string $user, string $type, string $key, string $result, string $parent = '', string $link = '') => [
        'USER' => $user, 'TYPE' => $type, 'KEY' => $key, 'RESULT' => $result, 'ALIAS' => '', 'LINK' => $link,
        'PARENT' => $parent, 'SUSPENDED' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-01',
    ];

    return [
        '1' => $row('bob', 'web', 'DOMAIN', 'shop.example'),
        '2' => $row('bob', 'dns', 'RECORD', 'www.shop.example', 'shop.example', '5'),
        '3' => $row('bob', 'mail', 'ACCOUNT', 'info@shop.example', 'shop.example', 'info'),
        '4' => $row('bob', 'cron', 'JOB', 'php shop.php', '2', '2'),
        '5' => $row('carol', 'db', 'DATABASE', 'carol_shop'),
        '6' => $row('carol', 'mail', 'DOMAIN', 'shop.carol.example'),
    ];
}

it('finds the objects of the tree, grouped by type with their API paths', function () {
    $this->fake->on('v-search-object', ['shop', 'json'])->returnsJson(apiSearchRows());

    $this->getJson('/api/v2/search?q=shop', apiAs($this->fake, 'arcadmin', 'admin', area: 'accounts'))->assertOk()
        ->assertJsonPath('data.0.type', 'users')
        ->assertJsonPath('data.0.items.0', ['name' => 'carol', 'owner' => 'carol', 'parent' => null, 'path' => '/api/v2/users/carol'])
        ->assertJsonPath('data.1', ['type' => 'web_domains', 'items' => [['name' => 'shop.example', 'owner' => 'bob', 'parent' => null, 'path' => '/api/v2/users/bob/web-domains/shop.example']]])
        ->assertJsonPath('data.2.type', 'dns_records')
        ->assertJsonPath('data.2.items.0.path', '/api/v2/users/bob/dns-zones/shop.example/records/5')
        ->assertJsonPath('data.2.items.0.parent', 'shop.example')
        ->assertJsonPath('data.3.type', 'mail_domains')
        ->assertJsonPath('data.4.items.0.path', '/api/v2/users/bob/mail-domains/shop.example/accounts/info')
        ->assertJsonPath('data.5.items.0.path', '/api/v2/users/carol/databases/carol_shop')
        ->assertJsonPath('data.6.items.0.path', '/api/v2/users/bob/cron-jobs/2')
        ->assertJsonCount(7, 'data');

    // reseller A: its customer bob only, no pages or actions
    $response = $this->getJson('/api/v2/search?q=shop', apiAs($this->fake, 'hostco', 'reseller', area: 'accounts'))->assertOk();
    expect(collect($response->json('data'))->pluck('items')->flatten(1)->pluck('owner')->unique()->values()->all())->toBe(['bob'])
        ->and(collect($response->json('data'))->pluck('type')->all())->not->toContain('users');
});

it('searches a user\'s own objects with v-search-user-object', function () {
    $this->fake->on('v-search-user-object', ['bob', 'shop', 'json'])->returnsJson(array_slice(apiSearchRows(), 0, 4, true));

    $this->getJson('/api/v2/search?q=shop', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.type', 'web_domains');
    $this->fake->assertNotRan('v-search-object');
});

it('answers at most 50 objects per type and refuses a short query', function () {
    $rows = [];
    for ($i = 1; $i <= 60; $i++) {
        $rows[(string) $i] = ['USER' => 'bob', 'TYPE' => 'web', 'KEY' => 'DOMAIN', 'RESULT' => "site{$i}.example", 'LINK' => '', 'PARENT' => ''];
    }
    $this->fake->on('v-search-object', ['site', 'json'])->returnsJson($rows);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/search?q=site', $headers)->assertOk()->assertJsonCount(50, 'data.0.items');
    $this->getJson('/api/v2/search?q=s', $headers)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['q']]]);
    $this->getJson('/api/v2/search', $headers)->assertUnprocessable();
});

// SSH keys (§4.4)

/** A public key line whose base64 part decodes. */
function apiSshKey(string $comment = ''): string
{
    return trim('ssh-ed25519 '.base64_encode("\0\0\0\x0bssh-ed25519".random_bytes(32)).' '.$comment);
}

beforeEach(function () {
    $this->sshKeys = ['bob' => [
        'bob@laptop' => ['ID' => 'bob@laptop', 'KEY' => 'SHA256:laptopfingerprint'],
        'work key' => ['ID' => 'work key', 'KEY' => 'SHA256:workfingerprint'],
        'filemanager.ssh.key' => ['ID' => 'filemanager.ssh.key', 'KEY' => 'SHA256:filemanager'],
    ]];
    $this->fake->on('v-list-user-ssh-key')->using(fn (array $args) => isset($this->sshKeys[$args[0]])
        ? [0, (string) json_encode($this->sshKeys[$args[0]])]
        : [0, '']);
});

it('lists SSH keys of its own account, and of any account for administrators', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/ssh-keys", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data', [['id' => 'bob@laptop', 'fingerprint' => 'SHA256:laptopfingerprint'], ['id' => 'work key', 'fingerprint' => 'SHA256:workfingerprint']])
            ->assertJsonPath('meta.total', 2);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its own' => ['hostco', 'reseller', 'hostco', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 403],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 403],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('adds a key with a key that holds only v2-accounts and names a key without comment', function () {
    $this->fake->on('v-add-user-ssh-key')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');
    $line = apiSshKey('bob@desktop');

    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => $line], $headers)->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/ssh-keys')
        ->assertJsonPath('data.id', 'bob@desktop');
    $this->fake->assertRan('v-add-user-ssh-key', fn ($a) => $a === ['bob', $line]);

    $bare = apiSshKey();
    $response = $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => $bare], $headers)->assertCreated();
    expect($response->json('data.id'))->toMatch('/^[0-9a-f]{32}$/')
        ->and($response->json('data.fingerprint'))->toStartWith('SHA256:');
    $this->fake->assertRan('v-add-user-ssh-key', fn ($a) => $a[1] === $bare.' '.$response->json('data.id'));
});

it('refuses duplicates, invalid keys and line breaks', function () {
    $this->fake->on('v-add-user-ssh-key')->returns();
    $headers = apiAs($this->fake, 'bob', 'user');
    $line = apiSshKey('bob@laptop');

    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => $line], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->sshKeys['bob']['other'] = ['ID' => 'other', 'KEY' => SshKeyService::fingerprint($same = apiSshKey('other'))];
    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => preg_replace('/ other$/', ' renamed', $same)], $headers)->assertStatus(409);

    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => 'not a key'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['key']]]);
    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => apiSshKey('a')."\n".apiSshKey('b')], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => 'ssh-ed25519 '.str_repeat('A', 16400)], $headers)->assertUnprocessable();
    $this->fake->assertNotRan('v-add-user-ssh-key');

    $this->fake->on('v-add-user-ssh-key')->returnsExit(ExitCode::Parsing, 'Error: Validating user private key');
    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => apiSshKey('broken')], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION');
});

it('plans an add without running it and refuses a v2-read key and a reseller on its customer', function () {
    $this->postJson('/api/v2/users/bob/ssh-keys?dry_run=1', ['key' => apiSshKey('ci')], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-user-ssh-key');
    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => apiSshKey('ci')], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-add-user-ssh-key']);
    $this->postJson('/api/v2/users/bob/ssh-keys', ['key' => apiSshKey('ci')], apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->fake->assertNotRan('v-add-user-ssh-key');
});

it('deletes a key by its URL-encoded comment', function () {
    $this->fake->on('v-delete-user-ssh-key')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');

    $this->deleteJson('/api/v2/users/bob/ssh-keys/'.rawurlencode('work key'), [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-ssh-key', fn ($a) => $a === ['bob', 'work key']);
    $this->deleteJson('/api/v2/users/bob/ssh-keys/bob%40laptop', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertNoContent();
    $this->fake->assertRan('v-delete-user-ssh-key', fn ($a) => $a === ['bob', 'bob@laptop']);

    $this->deleteJson('/api/v2/users/bob/ssh-keys/filemanager.ssh.key', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/users/bob/ssh-keys/ghost', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/users/bob/ssh-keys/bob%40laptop', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/ssh-keys/bob%40laptop', [], apiAs($this->fake, 'resb', 'reseller'))->assertNotFound();
    $this->fake->assertRan('v-delete-user-ssh-key', null, 2);
});
