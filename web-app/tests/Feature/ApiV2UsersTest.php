<?php

use App\Arc\ExitCode;

// REST API v2, users (E34 spec §6.1): hostco owns bob, resb owns carol and
// dave; arcadmin is the administrator. Non-admin keys carry the shipped
// v2-write (or v2-read) set, so every command an endpoint runs must be in it.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeWebDomains($this->fake);
    $this->users = fakeResellers($this->fake);
    $packages = [
        'default' => ['WEB_DOMAINS' => 'unlimited', 'USERS' => '0', 'RESELLER_OWNER' => ''],
        'starter' => ['WEB_DOMAINS' => '2', 'USERS' => '0', 'RESELLER_OWNER' => ''],
        'reseller' => ['WEB_DOMAINS' => '100', 'USERS' => '25', 'RESELLER_OWNER' => ''],
        'system' => ['USERS' => '0'],
    ];
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson($packages);
    foreach ($packages as $name => $row) {
        $this->fake->on('v-list-package-tenancy', [$name, 'json'])->returnsJson([$name => $row]);
    }
    $this->fake->on('v-list-reseller-packages', ['hostco', 'json'])->returnsJson(['starter' => $packages['starter'], 'hostco_gold' => ['WEB_DOMAINS' => '5', 'RESELLER_OWNER' => 'hostco']]);
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de', 'vi']);
    $this->fake->on('v-check-reseller-quota')->returns();
    $this->fake->on('v-update-reseller-counters')->returns();
});

/** The record `v-list-user NAME json` answers once a test created NAME. */
function apiCreatedUser($fake, string $name, array $record = []): void
{
    $fake->on('v-list-user', [$name, 'json'])->returnsJson([$name => userRecord(['NAME' => 'New', 'CONTACT' => 'new@example.com', 'PACKAGE' => 'starter'] + $record)]);
}

// Read (listUsers, getUser)

it('lists every user for an admin and the customers for a reseller', function () {
    $this->getJson('/api/v2/users', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0.username', 'arcadmin')
        ->assertJsonPath('meta.total', 6);
    $this->getJson('/api/v2/users?role=reseller', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.*.username', ['hostco', 'resb']);
    $this->getJson('/api/v2/users?owner=resb', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.*.username', ['carol', 'dave']);

    $this->getJson('/api/v2/users', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('data.*.username', ['bob']);
    $this->getJson('/api/v2/users', apiAs($this->fake, 'resb', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('data.*.username', ['carol', 'dave']);
    $this->getJson('/api/v2/users', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN');
});

it('pages the user list and keeps the full total', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/users?limit=2&offset=2', $headers)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta', ['total' => 6, 'limit' => 2, 'offset' => 2]);
    $this->getJson('/api/v2/users?offset=2', $headers)->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('meta', ['total' => 6]);
    $this->getJson('/api/v2/users?limit=0', $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonStructure(['error' => ['fields' => ['limit']]]);
    $this->getJson('/api/v2/users?limit=1001', $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['limit']]]);
    $this->getJson('/api/v2/users?offset=-1', $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['offset']]]);
});

it('describes a user with typed fields and no secrets', function () {
    $this->fake->on('v-list-user', ['bob', 'json'])->returnsJson(['bob' => userRecord([
        'NAME' => 'Bob Builder', 'CONTACT' => 'bob@example.com', 'PACKAGE' => 'starter', 'WEB_DOMAINS' => '2', 'DISK_QUOTA' => 'unlimited',
        'U_WEB_DOMAINS' => '1', 'U_DISK' => '250', 'NS' => 'ns1.example.net,ns2.example.net', 'RKEY' => 'secret-reset-key',
        'DATE' => '2026-09-01', 'TIME' => '10:00:00', 'TWOFA' => 'ABC', 'SUSPENDED' => 'no',
    ])]);

    $response = $this->getJson('/api/v2/users/bob', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk();
    $response->assertJsonPath('data', [
        'username' => 'bob', 'name' => 'Bob Builder', 'email' => 'bob@example.com', 'role' => 'user', 'owner' => 'hostco',
        'package' => 'starter', 'language' => 'en', 'shell' => 'nologin', 'suspended' => false, 'login_disabled' => false,
        'two_factor' => true, 'nameservers' => ['ns1.example.net', 'ns2.example.net'], 'allowed_ips' => null, 'php_cli' => null,
        'limits' => ['web_domains' => 2, 'dns_domains' => null, 'mail_domains' => null, 'mail_accounts' => null, 'databases' => null,
            'cron_jobs' => null, 'disk_mb' => null, 'bandwidth_mb' => null],
        'usage' => ['web_domains' => 1, 'dns_domains' => 0, 'mail_domains' => 0, 'mail_accounts' => 0, 'databases' => 0,
            'cron_jobs' => 0, 'disk_mb' => 250, 'bandwidth_mb' => 0],
        'created_at' => '2026-09-01T10:00:00',
    ]);
    expect((string) $response->getContent())->not->toContain('secret-reset-key');
});

it('shows users inside the tree and answers 404 outside it', function (string $principal, string $role, string $user, int $status) {
    $this->getJson("/api/v2/users/{$user}", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
})->with([
    'admin, any user' => ['arcadmin', 'admin', 'carol', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'nobody', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, itself' => ['hostco', 'reseller', 'hostco', 200],
    'reseller A, a customer of B' => ['hostco', 'reseller', 'carol', 404],
    'reseller A, reseller B' => ['hostco', 'reseller', 'resb', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'dave', 200],
    'reseller B, a customer of A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

// Create (createUser)

/** Main paths run with the default key and with a key that holds only the accounts set (D30). */
dataset('accounts keys', [
    'default key' => [null],
    'v2-accounts key' => ['accounts'],
]);

it('lets an admin create a reseller with its language', function (?string $area) {
    $this->fake->on('v-add-user')->returns();
    $this->fake->on('v-change-user-role')->returns();
    $this->fake->on('v-change-user-language')->returns();
    apiCreatedUser($this->fake, 'agency', ['ROLE' => 'reseller', 'PACKAGE' => 'reseller']);

    $this->postJson('/api/v2/users', [
        'username' => 'Agency', 'password' => 'Secret-123', 'email' => 'x@example.com', 'name' => 'Agency', 'package' => 'reseller',
        'language' => 'de', 'role' => 'reseller',
    ], apiAs($this->fake, 'arcadmin', 'admin', area: $area))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/agency')
        ->assertJsonPath('data.username', 'agency')
        ->assertJsonPath('data.role', 'reseller');

    $this->fake->assertRan('v-add-user', fn ($a) => $a === ['agency', 'Secret-123', 'x@example.com', 'reseller', 'Agency']);
    $this->fake->assertRan('v-change-user-role', fn ($a) => $a === ['agency', 'reseller']);
    $this->fake->assertRan('v-change-user-language', fn ($a) => $a === ['agency', 'de']);
})->with('accounts keys');

it('lets an admin key with the v2-write set create a customer of a reseller', function () {
    $this->fake->on('v-add-user')->returns();
    $this->fake->on('v-change-user-owner')->returns();
    apiCreatedUser($this->fake, 'client', ['OWNER' => 'resb']);

    $this->postJson('/api/v2/users', [
        'username' => 'client', 'password' => 'Secret-123', 'email' => 'c@example.com', 'name' => 'Client', 'package' => 'starter', 'owner' => 'resb',
    ], apiKey($this->fake, 'arcadmin', 'admin', ['v2-write']))->assertCreated();

    $this->fake->assertRan('v-change-user-owner', fn ($a) => $a === ['client', 'resb']);
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['resb']);
});

it('lets a reseller create its own customer, ignoring the admin fields', function (?string $area) {
    $this->fake->on('v-add-reseller-user')->returns();
    apiCreatedUser($this->fake, 'newcust', ['OWNER' => 'hostco']);

    $this->postJson('/api/v2/users', [
        'username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New Customer', 'package' => 'starter',
        'role' => 'admin', 'owner' => 'resb', 'login_disabled' => true,
    ], apiAs($this->fake, 'hostco', 'reseller', area: $area))->assertCreated();

    $this->fake->assertRan('v-add-reseller-user', fn ($a) => $a === ['hostco', 'newcust', 'Secret-123', 'new@example.com', 'starter', 'New Customer']);
    $this->fake->assertNotRan('v-change-user-role');
    $this->fake->assertNotRan('v-change-user-owner');
    $this->fake->assertNotRan('v-change-user-config-value');
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['hostco']);
})->with('accounts keys');

it('answers a repeated create with 409 and runs nothing', function () {
    $this->postJson('/api/v2/users', [
        'username' => 'carol', 'password' => 'Secret-123', 'email' => 'c@example.com', 'name' => 'C', 'package' => 'default',
    ], apiAs($this->fake, 'arcadmin', 'admin'))->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');

    $this->fake->assertNotRan('v-add-user');
});

it('validates the request and the choices', function () {
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/users', ['username' => 'x'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonStructure(['error' => ['fields' => ['username', 'password', 'email', 'name', 'package']]]);

    $input = ['username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New'];
    $this->postJson('/api/v2/users', $input + ['package' => 'reseller'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.package.0', 'Please select a valid package.');
    $this->postJson('/api/v2/users', $input + ['package' => 'starter', 'language' => 'xx'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.language.0', 'Please select a valid language.');
    $this->fake->on('v-check-reseller-quota', ['hostco', 'starter'])->returnsExit(ExitCode::Limit, 'USERS');
    $this->postJson('/api/v2/users', $input + ['package' => 'starter'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.package.0', 'The plan has no room for USERS.');

    $this->fake->assertNotRan('v-add-reseller-user');
});

it('keeps users out of user creation', function () {
    $this->postJson('/api/v2/users', [
        'username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New', 'package' => 'starter',
    ], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
});

it('answers a dry run with the planned steps and runs nothing', function () {
    $response = $this->postJson('/api/v2/users?dry_run=1', [
        'username' => 'agency', 'password' => 'Secret-123', 'email' => 'x@example.com', 'name' => 'Agency', 'package' => 'reseller', 'role' => 'reseller',
    ], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();

    $response->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0.command', 'v-add-user')
        ->assertJsonPath('data.steps.0.args', ['agency', '******', 'x@example.com', 'reseller', 'Agency'])
        ->assertJsonPath('data.steps.1.command', 'v-change-user-role');
    expect((string) $response->getContent())->not->toContain('Secret-123');
    $this->fake->assertNotRan('v-add-user');
    $this->fake->assertNotRan('v-change-user-role');
});

it('refuses the whole plan when the key may not run one of its commands', function () {
    $this->postJson('/api/v2/users', [
        'username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New', 'package' => 'starter',
    ], apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.commands', ['v-add-reseller-user', 'v-update-reseller-counters']);

    $this->fake->assertNotRan('v-add-reseller-user');
});

it('names the failed step and the steps before it', function () {
    $this->fake->on('v-add-user')->returns();
    $this->fake->on('v-change-user-role')->returnsExit(ExitCode::Invalid, 'Error: invalid role');

    $this->postJson('/api/v2/users', [
        'username' => 'agency', 'password' => 'Secret-123', 'email' => 'x@example.com', 'name' => 'Agency', 'package' => 'reseller', 'role' => 'reseller',
    ], apiAs($this->fake, 'arcadmin', 'admin'))->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_INVALID')
        ->assertJsonPath('error.message', 'Error: invalid role')
        ->assertJsonPath('error.step.command', 'v-change-user-role')
        ->assertJsonPath('error.applied.0.command', 'v-add-user');
});

// Change (updateUser, changeUserPackage, changeUserPassword)

it('updates only the fields it was sent', function (?string $area) {
    $this->fake->on('v-change-user-contact')->returns();

    $this->patchJson('/api/v2/users/bob', ['email' => 'new@example.com'], apiAs($this->fake, 'hostco', 'reseller', area: $area))->assertOk()
        ->assertJsonPath('data.username', 'bob');
    $this->fake->assertRan('v-change-user-contact', fn ($a) => $a === ['bob', 'new@example.com']);
    $this->fake->assertNotRan('v-change-user-name');
    $this->fake->assertNotRan('v-change-user-language');
})->with('accounts keys');

it('updates every field of a user with a key that holds only the accounts set', function () {
    foreach (['v-change-user-contact', 'v-change-user-name', 'v-change-user-language'] as $command) {
        $this->fake->on($command)->returns();
    }

    $this->patchJson('/api/v2/users/bob', ['email' => 'new@example.com', 'name' => 'Robert', 'language' => 'de'], apiAs($this->fake, 'hostco', 'reseller', area: 'accounts'))
        ->assertOk();
    $this->fake->assertRan('v-change-user-contact', fn ($a) => $a === ['bob', 'new@example.com']);
    $this->fake->assertRan('v-change-user-name', fn ($a) => $a === ['bob', 'Robert']);
    $this->fake->assertRan('v-change-user-language', fn ($a) => $a === ['bob', 'de']);
});

it('lets a user update its own account but no one else outside the tree', function () {
    $this->fake->on('v-change-user-name')->returns();

    $this->patchJson('/api/v2/users/bob', ['name' => 'Robert'], apiAs($this->fake, 'bob', 'user'))->assertOk();
    $this->fake->assertRan('v-change-user-name', fn ($a) => $a === ['bob', 'Robert']);

    $this->patchJson('/api/v2/users/bob', ['name' => 'Robert'], apiAs($this->fake, 'resb', 'reseller'))->assertNotFound();
    $this->patchJson('/api/v2/users/bob', ['language' => 'xx'], apiAs($this->fake, 'bob', 'user'))->assertUnprocessable()
        ->assertJsonPath('error.fields.language.0', 'Please select a valid language.');
});

it('changes the package of a customer through the reseller command', function () {
    $this->fake->on('v-change-reseller-user-package')->returns();
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->putJson('/api/v2/users/bob/package', ['package' => 'hostco_gold'], $headers)->assertOk();
    $this->fake->assertRan('v-change-reseller-user-package', fn ($a) => $a === ['hostco', 'bob', 'hostco_gold']);
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['hostco']);

    $this->putJson('/api/v2/users/bob/package', ['package' => 'reseller'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.package.0', 'Please select a valid package.');
    $this->putJson('/api/v2/users/bob/package', ['package' => 'starter'], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->putJson('/api/v2/users/carol/package', ['package' => 'starter'], $headers)->assertNotFound();
});

it('changes a password through a secret file and answers 204', function () {
    $this->fake->on('v-change-user-password')->returns();

    $this->putJson('/api/v2/users/bob/password', ['password' => 'Better-123'], apiAs($this->fake, 'bob', 'user'))->assertNoContent();
    $this->fake->assertRan('v-change-user-password', fn ($a) => $a === ['bob', 'Better-123']);

    $this->putJson('/api/v2/users/bob/password', ['password' => 'weak'], apiAs($this->fake, 'bob', 'user'))->assertUnprocessable();
    $this->putJson('/api/v2/users/carol/password', ['password' => 'Better-123'], apiAs($this->fake, 'bob', 'user'))->assertNotFound();
});

it('plans a password change without revealing it', function () {
    $response = $this->putJson('/api/v2/users/bob/password?dry_run=1', ['password' => 'Better-123'], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', '******']);

    expect((string) $response->getContent())->not->toContain('Better-123');
    $this->fake->assertNotRan('v-change-user-password');
});

// Suspend, unsuspend, delete

it('suspends and unsuspends users of the tree', function () {
    $this->fake->on('v-suspend-user')->returns();
    $this->fake->on('v-unsuspend-user')->returns();

    $this->postJson('/api/v2/users/bob/suspend', [], apiAs($this->fake, 'hostco', 'reseller'))->assertOk();
    $this->fake->assertRan('v-suspend-user', fn ($a) => $a === ['bob']);
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['hostco']);

    $this->postJson('/api/v2/users/bob/suspend', [], apiAs($this->fake, 'resb', 'reseller'))->assertNotFound();
    $this->postJson('/api/v2/users/bob/suspend', [], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->postJson('/api/v2/users/arcadmin/suspend', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertForbidden();
});

it('answers a suspend of a suspended user as it is, without a command', function () {
    $this->fake->on('v-list-user', ['carol', 'json'])->returnsJson(['carol' => userRecord(['SUSPENDED' => 'yes'])]);

    $this->postJson('/api/v2/users/carol/suspend', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.suspended', true);
    $this->fake->assertNotRan('v-suspend-user');
});

it('deletes a user, but not a reseller with customers', function (?string $area) {
    $this->fake->on('v-delete-user')->returns();

    $this->deleteJson('/api/v2/users/resb', [], apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE');
    $this->fake->assertNotRan('v-delete-user');

    $this->deleteJson('/api/v2/users/dave', [], apiAs($this->fake, 'resb', 'reseller', area: $area))->assertNoContent();
    $this->fake->assertRan('v-delete-user', fn ($a) => $a === ['dave']);
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['resb']);

    $this->deleteJson('/api/v2/users/bob', [], apiAs($this->fake, 'resb', 'reseller', area: $area))->assertNotFound();
    $this->deleteJson('/api/v2/users/bob', [], apiAs($this->fake, 'bob', 'user', area: $area))->assertForbidden();
})->with('accounts keys');
