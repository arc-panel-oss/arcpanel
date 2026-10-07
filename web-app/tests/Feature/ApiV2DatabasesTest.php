<?php

use App\Services\Databases\DatabaseActions;
use App\Services\Mail\HostingCredentialsMailer;
use Illuminate\Validation\ValidationException;

// REST API v2, databases (E34 batch 2 spec §6.4). bob has bob_wp and the
// suspended bob_shop; carol has her own database.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeDatabases($this->fake);
});

it('lists the databases of a user inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/databases", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', count($response->json('data')));
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

it('describes a database with the full name and the short name', function () {
    $this->getJson('/api/v2/users/bob/databases/bob_wp', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.name', 'bob_wp')
        ->assertJsonPath('data.short_name', 'wp')
        ->assertJsonPath('data.user', 'bob')
        ->assertJsonPath('data.db_user', 'bob_wp')
        ->assertJsonPath('data.type', 'mysql')
        ->assertJsonPath('data.host', 'localhost')
        ->assertJsonPath('data.disk_mb', 12)
        ->assertJsonMissingPath('data.password');
});

it('creates a database from short names with a key that holds only v2-db', function () {
    $this->fake->on('v-add-database')->returns();
    $this->fake->on('v-list-database', ['bob', 'bob_app', 'json'])->returnsJson(['bob_app' => [
        'DATABASE' => 'bob_app', 'DBUSER' => 'bob_app', 'HOST' => 'localhost', 'TYPE' => 'mysql',
        'CHARSET' => 'utf8mb4', 'U_DISK' => '0', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '12:00:00',
    ]]);

    $this->postJson('/api/v2/users/bob/databases', [
        'name' => 'app', 'db_user' => 'app', 'password' => 'Secret1a',
    ], apiAs($this->fake, 'bob', 'user', area: 'db'))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/databases/bob_app')
        ->assertJsonPath('data.short_name', 'app');
    $this->fake->assertRan('v-add-database');
});

it('refuses a short name that already has the prefix and a duplicate full name', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'db');

    $this->postJson('/api/v2/users/bob/databases', ['name' => 'bob_app', 'db_user' => 'app', 'password' => 'Secret1a'], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['name']]]);
    $this->postJson('/api/v2/users/bob/databases', ['name' => 'wp', 'db_user' => 'wp', 'password' => 'Secret1a'], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->assertNotRan('v-add-database');
});

it('names the allowed type when the server does not have it', function () {
    $this->postJson('/api/v2/users/bob/databases', [
        'name' => 'app', 'db_user' => 'app', 'password' => 'Secret1a', 'type' => 'pgsql',
    ], apiAs($this->fake, 'bob', 'user', area: 'db'))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.type.0', 'Type must be one of: mysql');
});

it('plans a create without running it and refuses a v2-read key', function () {
    $this->postJson('/api/v2/users/bob/databases?dry_run=1', [
        'name' => 'app', 'db_user' => 'app', 'password' => 'Secret1a',
    ], apiAs($this->fake, 'bob', 'user', area: 'db'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-add-database');
    $this->fake->assertNotRan('v-add-database');

    $this->postJson('/api/v2/users/bob/databases', [
        'name' => 'app', 'db_user' => 'app', 'password' => 'Secret1a',
    ], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-add-database');
});

it('changes the database user, refuses a rebuild to a user and suspends once', function () {
    $this->fake->on('v-change-database-user')->returns();
    $this->fake->on('v-suspend-database')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'db');

    $this->patchJson('/api/v2/users/bob/databases/bob_wp', ['db_user' => 'editor'], $headers)->assertOk();
    $this->fake->assertRan('v-change-database-user', fn ($a) => $a === ['bob', 'bob_wp', 'editor']);

    $this->postJson('/api/v2/users/bob/databases/bob_wp/rebuild', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/users/bob/databases/bob_shop/suspend', [], $headers)->assertOk();
    $this->fake->assertNotRan('v-suspend-database');
});

it('mails database credentials and still creates the database when the mail fails', function () {
    app()->instance(HostingCredentialsMailer::class, new class implements HostingCredentialsMailer
    {
        public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void
        {
            throw new RuntimeException('mail down');
        }

        public function sendMailCredentials(string $to, array $details, string $language): void {}
    });
    $this->fake->on('v-add-database')->returns();
    $this->fake->on('v-list-database', ['bob', 'bob_app', 'json'])->returnsJson(['bob_app' => [
        'DATABASE' => 'bob_app', 'DBUSER' => 'bob_app', 'HOST' => 'localhost', 'TYPE' => 'mysql',
        'CHARSET' => 'utf8mb4', 'U_DISK' => '0', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '12:00:00',
    ]]);

    $this->postJson('/api/v2/users/bob/databases', [
        'name' => 'app', 'db_user' => 'app', 'password' => 'Secret1a', 'send_credentials_to' => 'owner@example.net',
    ], apiAs($this->fake, 'bob', 'user', area: 'db'))
        ->assertCreated()->assertJsonPath('meta.credentials_sent', false);
});

it('refuses a short name that already starts with the owner prefix', function () {
    $actions = app(DatabaseActions::class);

    expect(fn () => $actions->assertShortName('name', 'alice', 'alice_shop'))
        ->toThrow(ValidationException::class);
    expect($actions->fullName('alice', 'shop', 'pgsql'))->toBe('alice_shop');
});
