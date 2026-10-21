<?php

use App\Arc\ExitCode;

// REST API v2, the secret store of a user (E38 spec §8). hostco owns bob,
// resb owns carol and dave; arcadmin is the root administrator. No
// response carries a value.

const API_STORE_VALUE = 'api-value-never-returned-0123456789';

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->store = ['bob' => [
        '/shop/db' => ['NAME' => '/shop/db', 'DESCRIPTION' => 'Shop database', 'VERSION' => '2', 'FINGERPRINT' => 'a1b2c3d4',
            'USED_BY' => '2', 'TIME' => '10:00:00', 'DATE' => '2026-10-09'],
        '/mail/token' => ['NAME' => '/mail/token', 'DESCRIPTION' => '', 'VERSION' => '1', 'FINGERPRINT' => '0f0f0f0f',
            'USED_BY' => '0', 'TIME' => '09:00:00', 'DATE' => '2026-10-08'],
    ]];
    $this->fake->on('v-list-user-secret-store')->using(fn (array $args) => [0, (string) json_encode($this->store[$args[0]] ?? [])]);
    $this->fake->on('v-list-user-secret')->using(function (array $args) {
        $row = $this->store[$args[0]][$args[1]] ?? null;
        if ($row === null) {
            return [ExitCode::NotExist->value, "Error: secret {$args[1]} not found"];
        }
        $row['USED_BY'] = $args[1] === '/shop/db' ? ['node:shop.bob.example', 'docker:shop', 'app:shop.bob.example/worker'] : [];
        $row['REFERENCE'] = '{{secret:'.$args[1].'}}';
        $row['VERSIONS'] = [
            ['VERSION' => '1', 'FINGERPRINT' => 'eeee1111', 'TIME' => '09:00:00', 'DATE' => '2026-10-08'],
            ['VERSION' => '2', 'FINGERPRINT' => 'a1b2c3d4', 'TIME' => '10:00:00', 'DATE' => '2026-10-09'],
        ];

        return [0, (string) json_encode([$args[1] => $row])];
    });
});

/** Main paths run with the default key and with a key that holds only the secrets set (D30). */
dataset('secret keys', [
    'default key' => [null],
    'v2-secrets key' => ['secrets'],
]);

it('lists the secrets of a user without values', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/secrets", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0', ['name' => '/mail/token', 'description' => '', 'version' => 1, 'fingerprint' => '0f0f0f0f',
                'reference' => '{{secret:/mail/token}}', 'updated_at' => '2026-10-08T09:00:00', 'used_by_count' => 0])
            ->assertJsonPath('data.1.name', '/shop/db');
        expect($response->getContent())->not->toContain(API_STORE_VALUE);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its own' => ['hostco', 'reseller', 'hostco', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('shows one secret with its versions and the apps that use it', function (?string $area) {
    $this->getJson('/api/v2/users/bob/secrets/shop/db', apiAs($this->fake, 'bob', 'user', area: $area))->assertOk()
        ->assertJsonPath('data.name', '/shop/db')
        ->assertJsonPath('data.reference', '{{secret:/shop/db}}')
        ->assertJsonPath('data.versions', [
            ['version' => 1, 'fingerprint' => 'eeee1111', 'created_at' => '2026-10-08T09:00:00'],
            ['version' => 2, 'fingerprint' => 'a1b2c3d4', 'created_at' => '2026-10-09T10:00:00'],
        ])
        ->assertJsonPath('data.used_by', [['type' => 'node', 'id' => 'shop.bob.example'], ['type' => 'docker', 'id' => 'shop'],
            ['type' => 'app', 'id' => 'shop.bob.example/worker']]);
    $this->getJson('/api/v2/users/bob/secrets/nope', apiAs($this->fake, 'bob', 'user'))->assertNotFound();
    $this->getJson('/api/v2/users/bob/secrets/a/../b', apiAs($this->fake, 'bob', 'user'))->assertNotFound();
})->with('secret keys');

it('takes the name in the path with its leading slash too, as the SDK encodes it', function () {
    $headers = apiAs($this->fake, 'bob', 'user');
    // The name as listed, encoded as a whole (encodeURIComponent)
    $this->getJson('/api/v2/users/bob/secrets/'.rawurlencode('/shop/db'), $headers)->assertOk()
        ->assertJsonPath('data.name', '/shop/db');
    $this->getJson('/api/v2/users/bob/secrets/'.rawurlencode('shop/db'), $headers)->assertOk();
    $this->getJson('/api/v2/users/bob/secrets/'.rawurlencode('/a/../b'), $headers)->assertNotFound();
});

it('creates a secret with the value in a file', function (?string $area) {
    $this->fake->on('v-add-user-secret')->using(function (array $args) {
        $this->store['bob'][$args[1]] = ['NAME' => $args[1], 'DESCRIPTION' => $args[3], 'VERSION' => '1', 'FINGERPRINT' => '12345678',
            'USED_BY' => '0', 'TIME' => '11:00:00', 'DATE' => '2026-10-09'];

        return [0, ''];
    });

    $response = $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop/api_key', 'value' => API_STORE_VALUE, 'description' => 'Payment API'],
        apiAs($this->fake, 'bob', 'user', area: $area))->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/secrets/shop/api_key')
        ->assertJsonPath('data.name', '/shop/api_key')
        ->assertJsonPath('data.reference', '{{secret:/shop/api_key}}')
        ->assertJsonPath('data.version', 1);
    expect($response->getContent())->not->toContain(API_STORE_VALUE);
    $this->fake->assertRan('v-add-user-secret', fn ($a) => $a === ['bob', '/shop/api_key', API_STORE_VALUE, 'Payment API']);
})->with('secret keys');

it('validates a new secret and answers the errors of the command', function () {
    $headers = apiAs($this->fake, 'bob', 'user');
    $this->postJson('/api/v2/users/bob/secrets', ['name' => 'shop', 'value' => 'x'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['name']]]);
    $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['value']]]);
    $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop', 'value' => 'x', 'description' => 'a"b'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop', 'value' => 'x', 'description' => 'db|prod'], $headers)->assertUnprocessable();
    $this->fake->assertNotRan('v-add-user-secret');

    $this->fake->on('v-add-user-secret')->returnsExit(ExitCode::Exists, 'Error: secret /shop/db already exists');
    $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop/db', 'value' => API_STORE_VALUE], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->on('v-add-user-secret')->returnsExit(ExitCode::Limit, 'Error: bob has reached the limit of 200 secrets');
    $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop/x', 'value' => API_STORE_VALUE], $headers)->assertStatus(422)
        ->assertJsonPath('error.code', 'E_LIMIT');
});

it('plans a change without running it and refuses a v2-read key before any command', function () {
    $response = $this->postJson('/api/v2/users/bob/secrets?dry_run=1', ['name' => '/shop/api_key', 'value' => API_STORE_VALUE], apiAs($this->fake, 'bob', 'user'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-add-user-secret');
    expect($response->getContent())->not->toContain(API_STORE_VALUE);

    $read = apiAs($this->fake, 'bob', 'user', readOnly: true);
    $this->postJson('/api/v2/users/bob/secrets', ['name' => '/shop/api_key', 'value' => API_STORE_VALUE], $read)->assertForbidden()
        ->assertJsonPath('error.commands', ['v-add-user-secret']);
    $this->putJson('/api/v2/users/bob/secrets/shop/db/value', ['value' => API_STORE_VALUE], $read)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/secrets/shop/db', [], $read)->assertForbidden();
    $this->postJson('/api/v2/users/carol/secrets', ['name' => '/x', 'value' => 'y'], apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    foreach (['v-add-user-secret', 'v-change-user-secret', 'v-delete-user-secret'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('replaces a value, restarts the apps on request and rolls back', function (?string $area) {
    $this->fake->on('v-change-user-secret')->returns("node:shop.bob.example\ndocker:shop\napp:shop.bob.example/worker");
    $this->fake->on('v-rollback-user-secret')->returns("node:shop.bob.example\ndocker:shop");
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);

    $this->putJson('/api/v2/users/bob/secrets/shop/db/value', ['value' => API_STORE_VALUE, 'restart' => true], $headers)->assertOk()
        ->assertJsonPath('data.secret.name', '/shop/db')
        ->assertJsonPath('data.apps', [['type' => 'node', 'id' => 'shop.bob.example'], ['type' => 'docker', 'id' => 'shop'],
            ['type' => 'app', 'id' => 'shop.bob.example/worker']])
        ->assertJsonPath('data.restarted', true)
        ->assertJsonPath('data.failed', []);
    $this->fake->assertRan('v-change-user-secret', fn ($a) => $a === ['bob', '/shop/db', API_STORE_VALUE, 'yes']);
    $this->putJson('/api/v2/users/bob/secrets/shop/db/value', ['value' => 'other'], $headers)->assertOk()->assertJsonPath('data.restarted', false);
    $this->fake->assertRan('v-change-user-secret', fn ($a) => $a === ['bob', '/shop/db', 'other', 'no']);
    $this->putJson('/api/v2/users/bob/secrets/shop/db/value', [], $headers)->assertUnprocessable();
    // a value keeps its white space and may span lines (a PEM key)
    $this->putJson('/api/v2/users/bob/secrets/shop/db/value', ['value' => " spaced\nvalue "], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-secret', fn ($a) => $a === ['bob', '/shop/db', " spaced\nvalue ", 'no']);

    $this->postJson('/api/v2/users/bob/secrets/shop/db/rollback', ['version' => 1], $headers)->assertOk()->assertJsonPath('data.restarted', false);
    $this->fake->assertRan('v-rollback-user-secret', fn ($a) => $a === ['bob', '/shop/db', '1', 'no']);
    $this->postJson('/api/v2/users/bob/secrets/shop/db/rollback', ['version' => 'x'], $headers)->assertUnprocessable();
    $this->putJson('/api/v2/users/bob/secrets/ghost/value', ['value' => 'x'], $headers)->assertNotFound();

    // an app that does not restart is named; the value is changed anyway
    $this->fake->on('v-change-user-secret')->returns("node:shop.bob.example\nfailed:node:shop.bob.example");
    $this->putJson('/api/v2/users/bob/secrets/shop/db/value', ['value' => 'x', 'restart' => true], $headers)->assertOk()
        ->assertJsonPath('data.apps', [['type' => 'node', 'id' => 'shop.bob.example']])
        ->assertJsonPath('data.failed', [['type' => 'node', 'id' => 'shop.bob.example']]);
})->with('secret keys');

it('changes the description', function (?string $area) {
    $this->fake->on('v-change-user-secret-description')->returns();

    $this->patchJson('/api/v2/users/bob/secrets/shop/db', ['description' => 'Shop DB'], apiAs($this->fake, 'bob', 'user', area: $area))->assertOk()
        ->assertJsonPath('data.name', '/shop/db');
    $this->fake->assertRan('v-change-user-secret-description', fn ($a) => $a === ['bob', '/shop/db', 'Shop DB']);
})->with('secret keys');

it('refuses to delete a used secret unless forced', function (?string $area) {
    $this->fake->on('v-delete-user-secret')->using(fn (array $args) => ($args[2] ?? 'no') === 'yes'
        ? [0, '']
        : [ExitCode::InUse->value, 'Error: secret /shop/db is used by node:shop.bob.example docker:shop']);
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);

    $this->deleteJson('/api/v2/users/bob/secrets/shop/db', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.used_by', [['type' => 'node', 'id' => 'shop.bob.example'], ['type' => 'docker', 'id' => 'shop'],
            ['type' => 'app', 'id' => 'shop.bob.example/worker']]);
    $this->deleteJson('/api/v2/users/bob/secrets/shop/db?force=1', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-secret', fn ($a) => $a === ['bob', '/shop/db', 'yes']);
    $this->deleteJson('/api/v2/users/bob/secrets/ghost', [], $headers)->assertNotFound();
})->with('secret keys');
