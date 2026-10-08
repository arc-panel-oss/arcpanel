<?php

use App\Arc\ExitCode;

// REST API v2 ports and the port registry (E34 batch 4 spec §4.5, D45, §6).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->fake->on('v-list-user-ports', ['hostco', 'json'])->returnsJson(portAccount('hostco',
        ['ROLE' => 'reseller', 'LIMIT' => '20', 'ALLOCATED' => '15', 'FREE' => '15', 'TREE_TOTAL' => '20', 'TREE_USED' => '5'],
        [portEntry('30010-30024', ['PUBLIC_OK' => 'yes'])]));
    $this->fake->on('v-list-user-ports', ['bob', 'json'])->returnsJson(portAccount('bob',
        ['OWNER' => 'hostco', 'LIMIT' => '5', 'ALLOCATED' => '5', 'ASSIGNED' => '1', 'FREE' => '4'],
        [portEntry('30005', ['APP' => 'manual', 'COMMENT' => 'game server', 'PUBLIC_OK' => 'yes', 'LISTEN' => 'local']), portEntry('30006-30009', ['PUBLIC_OK' => 'yes'])]));
    $this->fake->on('v-list-user-ports', ['carol', 'json'])->returnsJson(portAccount('carol',
        ['OWNER' => 'resb', 'LIMIT' => '2', 'ALLOCATED' => '2', 'ASSIGNED' => '1', 'FREE' => '1'],
        [portEntry('30030', ['APP' => 'node:carol.example']), portEntry('30031')]));
    $this->fake->on('v-list-sys-ports', ['json'])->returnsJson([
        'RANGE_START' => '30000', 'RANGE_END' => '39999', 'REGISTRY' => 'yes', 'FIREWALL' => 'iptables',
        'USERS' => [
            'arcadmin' => ['OWNER' => '', 'ROLE' => 'admin', 'SUSPENDED' => 'no', 'LIMIT' => '0', 'ALLOCATED' => '0', 'ASSIGNED' => '0', 'PUBLIC' => '0', 'FREE' => '0', 'TREE_TOTAL' => '', 'TREE_USED' => ''],
            'hostco' => ['OWNER' => 'arcadmin', 'ROLE' => 'reseller', 'SUSPENDED' => 'no', 'LIMIT' => '20', 'ALLOCATED' => '15', 'ASSIGNED' => '0', 'PUBLIC' => '0', 'FREE' => '15', 'TREE_TOTAL' => '20', 'TREE_USED' => '5'],
            'bob' => ['OWNER' => 'hostco', 'ROLE' => 'user', 'SUSPENDED' => 'no', 'LIMIT' => '5', 'ALLOCATED' => '5', 'ASSIGNED' => '1', 'PUBLIC' => '0', 'FREE' => '4', 'TREE_TOTAL' => '', 'TREE_USED' => ''],
        ],
        'ENTRIES' => [
            portEntry('30005', ['USER' => 'bob', 'APP' => 'manual', 'COMMENT' => 'game server', 'PUBLIC_OK' => 'yes']),
            portEntry('30010-30024', ['USER' => 'hostco', 'PUBLIC_OK' => 'yes']),
            portEntry('30302', ['USER' => 'carol', 'APP' => 'manual']),
        ],
        'RESERVED' => [portEntry('8083', ['APP' => 'system', 'COMMENT' => 'panel']), portEntry('31000-31009', ['APP' => 'admin', 'COMMENT' => 'monitoring'])],
        'SOCKETS' => [['PROTO' => 'tcp', 'PORT' => '30300', 'SCOPE' => 'any', 'UID' => '1004', 'ACCOUNT' => 'bob']],
        'FINDINGS' => [
            ['TYPE' => 'orphan-socket', 'USER' => 'bob', 'PORT' => '30300', 'PROTO' => 'tcp', 'RULE' => '', 'DETAIL' => 'listening without an entry'],
            ['TYPE' => 'unmanaged-rule', 'USER' => '', 'PORT' => '30302', 'PROTO' => 'tcp', 'RULE' => '14', 'DETAIL' => 'manual rule'],
        ],
    ]);
});

it('lists ports inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/ports", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data.0.port', '30005')
            ->assertJsonPath('data.0.owner', 'bob')
            ->assertJsonPath('data.0.app', 'manual')
            ->assertJsonPath('data.0.public_ok', true)
            ->assertJsonPath('data.0.listen', 'local')
            ->assertJsonPath('data.0.rule', null)
            ->assertJsonPath('data.0.created_at', '2026-10-05T10:00:00')
            ->assertJsonPath('data.1.port', '30006-30009')
            ->assertJsonPath('data.1.size', 4)
            ->assertJsonPath('data.1.app', null)
            ->assertJsonPath('meta.limit', '5')
            ->assertJsonPath('meta.allocated', 5)
            ->assertJsonPath('meta.free', 4)
            ->assertJsonPath('meta.owner', 'hostco')
            ->assertJsonPath('meta.range', ['start' => 30000, 'end' => 39999]);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'reseller B, other tree' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('reads the registry for administrators only', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/ports', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('data.range', ['start' => 30000, 'end' => 39999])
            ->assertJsonPath('data.firewall', 'iptables')
            ->assertJsonPath('data.users.1.user', 'hostco')
            ->assertJsonPath('data.users.1.tree_total', 20)
            ->assertJsonPath('data.entries.1.port', '30010-30024')
            ->assertJsonPath('data.reserved.0.kind', 'system')
            ->assertJsonPath('data.reserved.1.port', '31000-31009')
            ->assertJsonPath('data.sockets.0', ['proto' => 'tcp', 'port' => 30300, 'scope' => 'any', 'uid' => 1004, 'account' => 'bob'])
            ->assertJsonPath('data.findings.0.type', 'orphan-socket')
            ->assertJsonPath('data.findings.1.rule', '14');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('reserves and releases ports of the own pool with a v2-apps key', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');
    $this->fake->on('v-allocate-user-port')->returns("30005\n");
    $this->fake->on('v-release-user-port')->returns();

    $this->postJson('/api/v2/users/bob/ports/reserve', ['proto' => 'tcp', 'comment' => 'game server'], $headers)->assertOk()
        ->assertJsonPath('data.port', '30005')
        ->assertJsonPath('data.app', 'manual');
    $this->fake->assertRan('v-allocate-user-port', fn ($a) => $a === ['bob', 'manual', 'tcp', '', 'game server']);
    $this->postJson('/api/v2/users/bob/ports/reserve', ['port' => 30005, 'proto' => 'udp'], $headers)->assertOk();
    $this->fake->assertRan('v-allocate-user-port', fn ($a) => $a === ['bob', 'manual', 'udp', '30005', '']);
    $this->postJson('/api/v2/users/bob/ports/reserve', ['proto' => 'sctp', 'comment' => "bad'quote"], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonStructure(['error' => ['fields' => ['proto', 'comment']]]);

    $this->postJson('/api/v2/users/bob/ports/30005/release', [], $headers)->assertOk()
        ->assertJsonPath('meta.user', 'bob');
    $this->fake->assertRan('v-release-user-port', fn ($a) => $a === ['bob', '30005']);
    $this->postJson('/api/v2/users/bob/ports/30005/release?proto=udp', [], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/ports/30005/release?proto=icmp', [], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/ports/30006-30009/release', [], $headers)->assertNotFound();

    // Another user's ports are outside the tree; resellers reserve only in their own pool
    $this->postJson('/api/v2/users/carol/ports/reserve', ['proto' => 'tcp'], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/ports/reserve', ['proto' => 'tcp'], apiAs($this->fake, 'hostco', 'reseller', area: 'apps'))->assertForbidden();
});

it('opens only assigned ports that may be public', function () {
    $this->fake->on('v-change-user-port-public')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->putJson('/api/v2/users/bob/ports/30005/public', ['public' => true], $headers)->assertOk()
        ->assertJsonPath('data.port', '30005');
    $this->fake->assertRan('v-change-user-port-public', fn ($a) => $a === ['bob', '30005', 'yes']);
    $this->putJson('/api/v2/users/bob/ports/30005/public?proto=tcp', ['public' => false], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-port-public', fn ($a) => $a === ['bob', '30005', 'no']);

    // a free range is not an app's port
    $this->putJson('/api/v2/users/bob/ports/30006-30009/public', ['public' => true], $headers)->assertNotFound();
    $this->putJson('/api/v2/users/bob/ports/30005/public', ['public' => 'maybe'], $headers)->assertUnprocessable();

    // carol's port is not allowed to be public, even for an administrator
    $this->putJson('/api/v2/users/carol/ports/30030/public', ['public' => true], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))
        ->assertForbidden()->assertJsonPath('error.message', 'This port may not be open to the internet.');
    // a free single port has no app to open
    $this->putJson('/api/v2/users/carol/ports/30031/public', ['public' => false], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertForbidden();
});

it('lets administrators allow public ports and resellers only forbid them', function () {
    $this->fake->on('v-change-user-port-public-ok')->returns();

    $this->putJson('/api/v2/users/bob/ports/30006-30009/public-ok', ['public_ok' => false], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertForbidden();

    $reseller = apiAs($this->fake, 'hostco', 'reseller', area: 'apps');
    $this->putJson('/api/v2/users/bob/ports/30006-30009/public-ok', ['public_ok' => true], $reseller)->assertForbidden();
    $this->putJson('/api/v2/users/bob/ports/30006-30009/public-ok', ['public_ok' => false], $reseller)->assertOk()
        ->assertJsonPath('meta.user', 'bob');
    $this->fake->assertRan('v-change-user-port-public-ok', fn ($a) => $a === ['bob', '30006-30009', 'no']);
    $this->putJson('/api/v2/users/hostco/ports/30010-30024/public-ok', ['public_ok' => false], $reseller)->assertForbidden();
    $this->putJson('/api/v2/users/bob/ports/31000-31001/public-ok', ['public_ok' => false], $reseller)->assertNotFound();

    $this->putJson('/api/v2/users/carol/ports/30031/public-ok', ['public_ok' => true], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertOk();
    $this->fake->assertRan('v-change-user-port-public-ok', fn ($a) => $a === ['carol', '30031', 'yes']);
});

it('gives and takes back free ports only for administrators and resellers', function () {
    $this->fake->on('v-change-user-port-owner')->returns();
    $reseller = apiAs($this->fake, 'hostco', 'reseller', area: 'apps');

    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'bob', 'count' => 5], $reseller)->assertOk()
        ->assertJsonPath('meta.user', 'bob');
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['hostco', '+5', 'bob']);
    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'bob', 'range' => '30020-30024'], $reseller)->assertOk();
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['hostco', '30020-30024', 'bob']);
    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'bob', 'range' => '30020-30024', 'count' => 5], $reseller)->assertUnprocessable();
    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'bob'], $reseller)->assertUnprocessable();
    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'carol', 'count' => 1], $reseller)->assertUnprocessable()
        ->assertJsonPath('error.fields.to.0', 'This user does not exist.');

    $this->postJson('/api/v2/users/bob/ports/30006-30009/reclaim', [], $reseller)->assertOk();
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['bob', '30006-30009', 'hostco']);
    $this->postJson('/api/v2/users/bob/ports/30010/reclaim', [], $reseller)->assertNotFound();

    // the administrator gives from a reseller's pool to its customer
    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'bob', 'count' => 2], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertOk();
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['hostco', '+2', 'bob']);
    $this->postJson('/api/v2/users/resb/ports/give', ['to' => 'bob', 'count' => 2], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertUnprocessable()
        ->assertJsonPath('error.fields.to.0', 'bob is not a customer of resb.');

    $user = apiAs($this->fake, 'bob', 'user', area: 'apps');
    $this->postJson('/api/v2/users/bob/ports/give', ['to' => 'bob', 'count' => 5], $user)->assertForbidden();
    $this->postJson('/api/v2/users/bob/ports/30006-30009/reclaim', [], $user)->assertForbidden();
});

it('answers a pool without room with 422 E_LIMIT and the failed step', function () {
    $this->fake->on('v-change-user-port-owner')->returnsExit(ExitCode::Limit, 'Error: hostco has 15 free ports, 50 requested');

    $this->postJson('/api/v2/users/hostco/ports/give', ['to' => 'bob', 'count' => 50], apiAs($this->fake, 'hostco', 'reseller', area: 'apps'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'hostco has 15 free ports, 50 requested')
        ->assertJsonPath('error.step.command', 'v-change-user-port-owner')
        ->assertJsonPath('error.applied', []);
});

it('sends a port request to the owner of the key only', function () {
    $this->fake->on('v-add-user-notification')->returns();

    $this->postJson('/api/v2/users/bob/ports/request', ['count' => 3, 'reason' => 'For a staging app'], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertOk()
        ->assertJsonPath('data', ['owner' => 'hostco', 'count' => 3]);
    $this->fake->assertRan('v-add-user-notification', fn ($a) => $a[0] === 'hostco' && $a[1] === 'Port request' && $a[2] === 'bob asks for 3 more ports. For a staging app');
    $this->postJson('/api/v2/users/bob/ports/request', ['count' => 3, 'reason' => '<script>'], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertUnprocessable();

    $this->postJson('/api/v2/users/bob/ports/request', ['count' => 3], apiAs($this->fake, 'hostco', 'reseller', area: 'apps'))->assertForbidden();
    $this->postJson('/api/v2/users/hostco/ports/request', ['count' => 3], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertForbidden();
});

it('deletes ports of a user for administrators only', function () {
    $this->fake->on('v-delete-user-port')->returns();

    $this->deleteJson('/api/v2/users/bob/ports/30005?force=1', [], apiAs($this->fake, 'hostco', 'reseller', area: 'apps'))->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/ports/30005?force=1', [], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertNoContent();
    $this->fake->assertRan('v-delete-user-port', fn ($a) => $a === ['bob', '30005', 'force']);
    $this->deleteJson('/api/v2/users/bob/ports/30010-30011', [], apiAs($this->fake, 'arcadmin', 'admin', area: 'apps'))->assertNotFound();
});

it('changes the registry as an administrator with a v2-server key', function () {
    foreach (['v-add-user-port', 'v-add-user-port-pool', 'v-change-sys-port-range', 'v-add-sys-port-reserved', 'v-delete-sys-port-reserved',
        'v-check-sys-ports', 'v-allocate-user-port', 'v-change-user-port-public', 'v-change-user-port-public-ok', 'v-delete-firewall-rule'] as $command) {
        $this->fake->on($command)->returns();
    }
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'hostco', 'range' => '31100-31199', 'public_ok' => true, 'comment' => 'reseller block'], $headers)->assertOk()
        ->assertJsonPath('meta.user', 'hostco');
    $this->fake->assertRan('v-add-user-port', fn ($a) => $a === ['hostco', '31100-31199', 'yes', 'reseller block']);
    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'bob', 'count' => 5], $headers)->assertOk();
    $this->fake->assertRan('v-add-user-port-pool', fn ($a) => $a === ['bob', '5', 'no']);
    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'ghost', 'count' => 5], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.user.0', 'This user does not exist.');
    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'bob', 'range' => '31100;rm'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['range']]]);

    $this->putJson('/api/v2/system/ports/range', ['start' => 30000, 'end' => 29999], $headers)->assertUnprocessable();
    $this->putJson('/api/v2/system/ports/range', ['start' => 30000, 'end' => 34999], $headers)->assertOk()
        ->assertJsonPath('data.range.start', 30000);
    $this->fake->assertRan('v-change-sys-port-range', fn ($a) => $a === ['30000', '34999']);

    $this->postJson('/api/v2/system/ports/reserved', ['range' => '31500', 'comment' => 'agent'], $headers)->assertOk();
    $this->fake->assertRan('v-add-sys-port-reserved', fn ($a) => $a === ['31500', 'agent']);
    $this->deleteJson('/api/v2/system/ports/reserved/31000-31009', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-sys-port-reserved', fn ($a) => $a === ['31000-31009']);
    $this->deleteJson('/api/v2/system/ports/reserved/8083', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/ports/reserved/31600', [], $headers)->assertNotFound();

    $this->postJson('/api/v2/system/ports/repair', [], $headers)->assertOk()
        ->assertJsonPath('data.0.type', 'orphan-socket');
    $this->fake->assertRan('v-check-sys-ports', fn ($a) => $a === ['repair']);

    $this->postJson('/api/v2/system/ports/register', ['user' => 'bob', 'port' => 30300, 'proto' => 'tcp'], $headers)->assertOk()
        ->assertJsonPath('meta.user', 'bob');
    $this->fake->assertRan('v-add-user-port', fn ($a) => $a === ['bob', '30300']);
    $this->fake->assertRan('v-allocate-user-port', fn ($a) => $a === ['bob', 'manual', 'tcp', '30300']);

    $this->postJson('/api/v2/system/ports/adopt', ['port' => 30302, 'rule' => 14], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-port-public-ok', fn ($a) => $a === ['carol', '30302', 'yes']);
    $this->fake->assertRan('v-change-user-port-public', fn ($a) => $a === ['carol', '30302', 'yes', '14']);
    $this->postJson('/api/v2/system/ports/adopt', ['port' => 30010, 'rule' => 14], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.port.0', 'Reserve the port for its user first, then link the rule.');

    $this->postJson('/api/v2/system/ports/close-rule', ['rule' => 15], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-firewall-rule', fn ($a) => $a === ['15']);
});

it('keeps registry writes to administrators', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'bob', 'count' => 5], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/ports/repair', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/ports/close-rule', ['rule' => 15], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-add-user-port-pool');
    $this->fake->assertNotRan('v-check-sys-ports');
    $this->fake->assertNotRan('v-delete-firewall-rule');
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('plans without running', function () {
    $this->postJson('/api/v2/users/bob/ports/reserve?dry_run=1', ['proto' => 'tcp'], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0.command', 'v-allocate-user-port')
        ->assertJsonPath('data.steps.0.args', ['bob', 'manual', 'tcp', '', '']);
    $this->postJson('/api/v2/system/ports/register?dry_run=1', ['user' => 'bob', 'port' => 30300, 'proto' => 'tcp'], apiAs($this->fake, 'arcadmin', 'admin', area: 'server'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-user-port')
        ->assertJsonPath('data.steps.1.command', 'v-allocate-user-port');
    $this->deleteJson('/api/v2/users/bob/ports/30005?dry_run=1', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-user-port');

    $this->fake->assertNotRan('v-allocate-user-port');
    $this->fake->assertNotRan('v-add-user-port');
    $this->fake->assertNotRan('v-delete-user-port');
});

it('refuses a v2-read key before any write command', function () {
    $this->postJson('/api/v2/users/bob/ports/reserve', ['proto' => 'tcp'], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands', ['v-allocate-user-port']);
    $this->postJson('/api/v2/users/bob/ports/30005/release', [], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands', ['v-release-user-port']);
    $readAdmin = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);
    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'bob', 'count' => 5], $readAdmin)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-add-user-port-pool']);
    $this->postJson('/api/v2/system/ports/repair', [], $readAdmin)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-check-sys-ports']);

    $this->fake->assertNotRan('v-allocate-user-port');
    $this->fake->assertNotRan('v-release-user-port');
    $this->fake->assertNotRan('v-add-user-port-pool');
    $this->fake->assertNotRan('v-check-sys-ports');
});

it('answers 404 for every port path while PORT_REGISTRY is off', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'PORT_REGISTRY' => 'no']);
    $admin = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/users/bob/ports', $admin)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->postJson('/api/v2/users/bob/ports/reserve', ['proto' => 'tcp'], $admin)->assertNotFound();
    $this->deleteJson('/api/v2/users/bob/ports/30005', [], $admin)->assertNotFound();
    $this->getJson('/api/v2/system/ports', $admin)->assertNotFound();
    $this->postJson('/api/v2/system/ports/allocate', ['user' => 'bob', 'count' => 5], $admin)->assertNotFound();
    $this->fake->assertNotRan('v-list-user-ports');
    $this->fake->assertNotRan('v-list-sys-ports');
});
