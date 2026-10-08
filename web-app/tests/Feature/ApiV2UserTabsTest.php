<?php

// REST API v2, the user tabs of batch 6 (spec §4.5, D63, D66): name servers,
// the login IP allow list, login disabling, the administration fields and
// turning two-factor authentication off. hostco owns bob, resb owns carol
// and dave; arcadmin is the root administrator.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, [
        'bob' => ['NS' => 'ns1.old.example,ns2.old.example', 'TWOFA' => 'SECRETSEED', 'LOGIN_USE_IPLIST' => 'yes', 'LOGIN_ALLOW_IPS' => '192.0.2.1'],
        'otheradmin' => ['ROLE' => 'admin'],
    ]);
    $packages = ['default' => ['USERS' => '0'], 'reseller' => ['USERS' => '25']];
    foreach ($packages as $name => $row) {
        $this->fake->on('v-list-package-tenancy', [$name, 'json'])->returnsJson([$name => $row]);
    }
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de']);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'sh', 'nologin']);
    $this->fake->on('v-list-sys-php', ['json'])->returnsJson(['8.4', '8.5']);
    foreach (['v-change-user-ns', 'v-change-user-config-value', 'v-change-user-shell', 'v-change-user-php-cli', 'v-change-user-role', 'v-change-user-owner', 'v-delete-user-2fa', 'v-update-reseller-counters'] as $command) {
        $this->fake->on($command)->returns();
    }
});

/** Main paths run with the default key and with a key that holds only the accounts set (D30). */
dataset('tab keys', [
    'default key' => [null],
    'v2-accounts key' => ['accounts'],
]);

it('returns the new user fields', function () {
    $this->fake->on('v-list-user', ['bob', 'json'])->returnsJson(['bob' => userRecord([
        'LOGIN_USE_IPLIST' => 'yes', 'LOGIN_ALLOW_IPS' => '192.0.2.1,198.51.100.0/24', 'PHPCLI' => '8.4', 'SHELL' => 'bash', 'TWOFA' => 'X',
    ])]);

    $this->getJson('/api/v2/users/bob', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.allowed_ips', ['192.0.2.1', '198.51.100.0/24'])
        ->assertJsonPath('data.php_cli', '8.4')
        ->assertJsonPath('data.shell', 'bash')
        ->assertJsonPath('data.two_factor', true);
    $this->getJson('/api/v2/users/carol', apiAs($this->fake, 'resb', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('data.allowed_ips', null)
        ->assertJsonPath('data.php_cli', null);
});

// Name servers

it('changes the name servers of a customer and of an administrator itself', function (?string $area) {
    $this->patchJson('/api/v2/users/bob', ['nameservers' => ['NS1.Host.example', 'ns2.host.example']], apiAs($this->fake, 'hostco', 'reseller', area: $area))->assertOk();
    $this->fake->assertRan('v-change-user-ns', fn ($a) => $a === ['bob', 'ns1.host.example', 'ns2.host.example']);

    $this->patchJson('/api/v2/users/arcadmin', ['nameservers' => ['ns1.panel.example', 'ns2.panel.example', 'ns3.panel.example']], apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertOk();
    $this->fake->assertRan('v-change-user-ns', fn ($a) => $a === ['arcadmin', 'ns1.panel.example', 'ns2.panel.example', 'ns3.panel.example']);
})->with('tab keys');

it('refuses name servers of a user for itself and invalid lists', function () {
    $this->patchJson('/api/v2/users/bob', ['nameservers' => ['ns1.a.example', 'ns2.a.example']], apiAs($this->fake, 'bob', 'user'))->assertForbidden()
        ->assertJsonPath('error.message', 'You may not change the field nameservers of this user.');
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $this->patchJson('/api/v2/users/bob', ['nameservers' => ['ns1.a.example']], $headers)->assertUnprocessable();
    $this->patchJson('/api/v2/users/bob', ['nameservers' => array_map(fn ($i) => "ns{$i}.a.example", range(1, 9))], $headers)->assertUnprocessable();
    $this->patchJson('/api/v2/users/bob', ['nameservers' => ['ns1.a.example', 'not a host']], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['nameservers.1']]]);
    $this->patchJson('/api/v2/users/bob', ['nameservers' => ['ns1.old.example', 'ns2.old.example']], $headers)->assertOk();
    $this->fake->assertNotRan('v-change-user-ns');
});

// Security: allowed_ips, login_disabled

it('sets and drops the login IP allow list of the own account', function (?string $area) {
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);

    $this->patchJson('/api/v2/users/bob', ['allowed_ips' => ['203.0.113.5', '198.51.100.7']], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-config-value', fn ($a) => $a === ['bob', 'LOGIN_ALLOW_IPS', '203.0.113.5,198.51.100.7']);
    $this->fake->assertNotRan('v-change-user-shell');

    $this->patchJson('/api/v2/users/bob', ['allowed_ips' => null], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-config-value', fn ($a) => $a === ['bob', 'LOGIN_USE_IPLIST', 'no']);
    $this->fake->assertRan('v-change-user-config-value', fn ($a) => $a === ['bob', 'LOGIN_ALLOW_IPS', '']);

    $this->patchJson('/api/v2/users/bob', ['allowed_ips' => []], $headers)->assertUnprocessable();
    // the user record and the login take IPv4 addresses only, compared exactly
    foreach (['example.com', '198.51.100.0/24', '2001:db8::1'] as $invalid) {
        $this->patchJson('/api/v2/users/bob', ['allowed_ips' => [$invalid]], $headers)->assertUnprocessable();
    }
})->with('tab keys');

it('keeps the allow list when a request leaves it out', function () {
    $this->fake->on('v-change-user-name')->returns();
    $this->patchJson('/api/v2/users/bob', ['name' => 'Robert'], apiAs($this->fake, 'bob', 'user'))->assertOk();
    $this->fake->assertRan('v-change-user-name', fn ($a) => $a === ['bob', 'Robert']);
    $this->fake->assertNotRan('v-change-user-config-value');
});

it('lets administrators disable the login of others, not their own', function () {
    $this->patchJson('/api/v2/users/bob', ['login_disabled' => true], apiAs($this->fake, 'arcadmin', 'admin', area: 'accounts'))->assertOk();
    $this->fake->assertRan('v-change-user-config-value', fn ($a) => $a === ['bob', 'LOGIN_DISABLED', 'yes']);

    $this->patchJson('/api/v2/users/arcadmin', ['login_disabled' => true], apiAs($this->fake, 'arcadmin', 'admin'))->assertForbidden();
    $this->patchJson('/api/v2/users/bob', ['login_disabled' => true], apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->patchJson('/api/v2/users/bob', ['login_disabled' => false], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->fake->assertRan('v-change-user-config-value', null, 1);
});

// Administration

it('changes the shell, the PHP CLI version and the owner of a user', function (?string $area) {
    $this->patchJson('/api/v2/users/bob', ['shell' => 'bash', 'php_cli' => '8.4', 'owner' => 'resb'], apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertOk();

    $this->fake->assertRan('v-change-user-owner', fn ($a) => $a === ['bob', 'resb']);
    $this->fake->assertRan('v-change-user-shell', fn ($a) => $a === ['bob', 'bash']);
    $this->fake->assertRan('v-change-user-php-cli', fn ($a) => $a === ['bob', '8.4']);
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['hostco']);
})->with('tab keys');

it('moves a customer back to the administrator before it becomes a reseller', function () {
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'reseller']]);

    $this->patchJson('/api/v2/users/bob', ['role' => 'reseller'], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $calls = array_column(array_filter($this->fake->calls(), fn ($c) => in_array($c['command'], ['v-change-user-owner', 'v-change-user-role'], true)), 'args', 'command');
    expect(array_keys($calls))->toBe(['v-change-user-owner', 'v-change-user-role'])
        ->and($calls['v-change-user-owner'])->toBe(['bob', 'arcadmin'])
        ->and($calls['v-change-user-role'])->toBe(['bob', 'reseller']);
});

it('applies the rules of the administration tab', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->patchJson('/api/v2/users/resb', ['role' => 'user'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.role.0', 'Move or delete the customers of this reseller first.');
    $this->patchJson('/api/v2/users/bob', ['role' => 'reseller'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.role.0', 'A reseller needs a package that allows customers (USERS above 0).');
    $this->patchJson('/api/v2/users/bob', ['owner' => 'carol'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.owner.0', 'Please select a valid owner.');
    $this->patchJson('/api/v2/users/bob', ['shell' => 'zsh'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.shell.0', 'Please select a valid shell.');
    $this->patchJson('/api/v2/users/bob', ['php_cli' => '7.4'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.php_cli.0', 'Please select a valid PHP version.');
    $this->patchJson('/api/v2/users/bob', ['role' => 'root'], $headers)->assertUnprocessable();
    foreach (['v-change-user-role', 'v-change-user-owner', 'v-change-user-shell', 'v-change-user-php-cli'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('refuses the administration fields to non-administrators and on the root administrator', function (string $principal, string $role, string $user, array $body) {
    $this->patchJson("/api/v2/users/{$user}", $body, apiAs($this->fake, $principal, $role))->assertForbidden()
        ->assertJsonPath('error.message', 'You may not change the field '.array_key_first($body).' of this user.');
    foreach (['v-change-user-role', 'v-change-user-owner', 'v-change-user-shell', 'v-change-user-php-cli'] as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller, role of its customer' => ['hostco', 'reseller', 'bob', ['role' => 'admin']],
    'reseller, shell of its customer' => ['hostco', 'reseller', 'bob', ['shell' => 'bash']],
    'user, its own shell' => ['bob', 'user', 'bob', ['shell' => 'bash']],
    'admin, role of the root administrator' => ['otheradmin', 'admin', 'arcadmin', ['role' => 'user']],
    'admin, shell of the root administrator' => ['otheradmin', 'admin', 'arcadmin', ['shell' => 'bash']],
    'root administrator, its own PHP version' => ['arcadmin', 'admin', 'arcadmin', ['php_cli' => '8.4']],
    'admin, its own role' => ['otheradmin', 'admin', 'otheradmin', ['role' => 'user']],
    'admin, owner of a reseller' => ['arcadmin', 'admin', 'resb', ['owner' => 'hostco']],
]);

it('plans every tab of a change without running it and refuses a v2-read key before any command', function () {
    $body = ['nameservers' => ['ns1.a.example', 'ns2.a.example'], 'login_disabled' => true, 'shell' => 'bash'];

    $this->patchJson('/api/v2/users/bob?dry_run=1', $body, apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-change-user-ns')
        ->assertJsonPath('data.steps.1.args', ['bob', 'LOGIN_DISABLED', 'yes'])
        ->assertJsonPath('data.steps.2.command', 'v-change-user-shell');
    $this->patchJson('/api/v2/users/bob', ['nameservers' => ['ns1.a.example', 'ns2.a.example'], 'allowed_ips' => null], apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-change-user-ns', 'v-change-user-config-value']);
    foreach (['v-change-user-ns', 'v-change-user-config-value', 'v-change-user-shell'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

// Two-factor authentication (disableUserTwoFactor)

it('turns two-factor authentication off for the own account and for administrators', function (?string $area) {
    $this->deleteJson('/api/v2/users/bob/two-factor', [], apiAs($this->fake, 'bob', 'user', area: $area))->assertNoContent();
    $this->fake->assertRan('v-delete-user-2fa', fn ($a) => $a === ['bob']);
    $this->deleteJson('/api/v2/users/bob/two-factor', [], apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertNoContent();
    $this->fake->assertRan('v-delete-user-2fa', null, 2);
    $this->fake->assertNotRan('v-change-user-config-value');
})->with('tab keys');

it('answers 204 without a command when two-factor authentication is off', function () {
    $this->deleteJson('/api/v2/users/carol/two-factor', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertNoContent();
    $this->deleteJson('/api/v2/users/carol/two-factor?dry_run=1', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps', []);
    $this->fake->assertNotRan('v-delete-user-2fa');
});

it('refuses resellers, other trees and read-only keys', function () {
    $this->deleteJson('/api/v2/users/bob/two-factor', [], apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/two-factor', [], apiAs($this->fake, 'resb', 'reseller'))->assertNotFound();
    $this->deleteJson('/api/v2/users/carol/two-factor', [], apiAs($this->fake, 'bob', 'user'))->assertNotFound();
    $this->deleteJson('/api/v2/users/bob/two-factor', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-delete-user-2fa']);
    $this->deleteJson('/api/v2/users/bob/two-factor?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-user-2fa');
    $this->fake->assertNotRan('v-delete-user-2fa');
});
