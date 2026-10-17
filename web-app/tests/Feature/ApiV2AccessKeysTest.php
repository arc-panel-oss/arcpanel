<?php

use App\Arc\ExitCode;

// REST API v2, access keys (E34 batch 6 spec §4.6, D62). The key of the
// requests is API_KEY_ID; bob also has BOB_API_KEY. hostco owns bob, resb
// owns carol; arcadmin is the root administrator.

const BOB_API_KEY = 'BobKey0123456789abcd';
const CAROL_API_KEY = 'CarolKey0123456789ab';
const NEW_API_KEY = 'NewKey0123456789abcd';
const STORED_SECRET = 'stored-secret-0123456789abcdefghijklmnopq';
const NEW_SECRET = 'new-secret-0123456789abcdefghijklmnopqrst';

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $key = fn (string $id, string $user, array $permissions, string $comment, string $date) => [
        'ACCESS_KEY_ID' => $id, 'SECRET_ACCESS_KEY' => STORED_SECRET, 'USER' => $user, 'PERMISSIONS' => $permissions,
        'COMMENT' => $comment, 'TIME' => '10:00:00', 'DATE' => $date,
    ];
    $this->keys = [
        API_KEY_ID => $key(API_KEY_ID, 'bob', ['v2-accounts'], 'calling key', '2026-10-01'),
        BOB_API_KEY => $key(BOB_API_KEY, 'bob', ['v2-read'], 'deploy', '2026-09-01'),
        CAROL_API_KEY => $key(CAROL_API_KEY, 'carol', ['v2-read'], '', '2026-09-02'),
    ];
    $this->fake->on('v-list-access-keys')->using(function (array $args) {
        $rows = [];
        foreach ($this->keys as $id => $row) {
            if ($row['USER'] === $args[0]) {
                unset($row['SECRET_ACCESS_KEY']);
                $rows[$id] = $row;
            }
        }

        return [0, (string) json_encode($rows)];
    });
    $this->fake->on('v-list-access-key')->using(fn (array $args) => isset($this->keys[$args[0]])
        ? [0, (string) json_encode($this->keys[$args[0]])]
        : [ExitCode::NotExist->value, 'Error: key not found']);
    $this->fake->on('v-list-apis')->returnsJson([
        'billing' => ['COMMANDS' => ['v-add-user'], 'ROLE' => 'admin', 'API_VERSION' => '1'],
        'purge-nginx-cache' => ['COMMANDS' => ['v-purge-nginx-cache'], 'ROLE' => 'user', 'API_VERSION' => '1'],
        'v2-accounts' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-read' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-web' => ['COMMANDS' => ['v-list-web-domain'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-write' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
    ]);
    $this->fake->on('v-add-access-key')->using(fn (array $args) => [0, (string) json_encode([
        'ACCESS_KEY_ID' => NEW_API_KEY, 'SECRET_ACCESS_KEY' => NEW_SECRET, 'USER' => $args[0],
        'PERMISSIONS' => $args[1] === '' ? [] : explode(',', $args[1]), 'COMMENT' => $args[2], 'TIME' => '12:00:00', 'DATE' => '2026-10-08',
    ])]);
    $this->fake->on('v-delete-access-key')->returns();
});

it('lists the keys of an account without secrets, with the rules of the page', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/access-keys", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0', ['id' => BOB_API_KEY, 'permissions' => ['v2-read'], 'comment' => 'deploy', 'created_at' => '2026-09-01T10:00:00', 'allowed_ips' => [], 'expires_at' => null])
            ->assertJsonPath('data.1.id', API_KEY_ID);
        expect((string) $response->getContent())->not->toContain(STORED_SECRET)->not->toContain('secret');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its own' => ['hostco', 'reseller', 'hostco', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 403],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('reads one key of the account and nobody else\'s', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');

    $response = $this->getJson('/api/v2/users/bob/access-keys/'.BOB_API_KEY, $headers)->assertOk()
        ->assertJsonPath('data.permissions', ['v2-read']);
    expect((string) $response->getContent())->not->toContain(STORED_SECRET);
    $this->getJson('/api/v2/users/bob/access-keys/'.CAROL_API_KEY, $headers)->assertNotFound();
    $this->getJson('/api/v2/users/bob/access-keys/Unknown0123456789abc', $headers)->assertNotFound();
});

it('creates a key with a set it holds and shows the secret once', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');

    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-accounts'], 'comment' => 'ci'], $headers)->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/access-keys/'.NEW_API_KEY)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data', [
            'id' => NEW_API_KEY, 'permissions' => ['v2-accounts'], 'comment' => 'ci', 'created_at' => '2026-10-08T12:00:00', 'allowed_ips' => [], 'expires_at' => null, 'secret' => NEW_SECRET,
        ]);
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === ['bob', 'v2-accounts', 'ci', 'json']);
});

it('refuses a set the key does not hold and a set the owner may not have (D62)', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');

    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-accounts', 'v2-web']], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'The access key may only give the permission sets it holds; it does not hold v2-web.');
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['billing']], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.permissions.0', 'API billing not allowed');
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['ghost-set']], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => []], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/access-keys', ['comment' => 'no sets'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-accounts'], 'comment' => "a\nb"], $headers)->assertUnprocessable();
    $this->fake->assertNotRan('v-add-access-key');
});

it('lets an administrator key without sets give any set the page offers the owner', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-web', 'purge-nginx-cache']], $headers)->assertCreated();
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === ['bob', 'v2-web,purge-nginx-cache', '', 'json']);
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['billing']], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/arcadmin/access-keys', ['permissions' => ['billing']], $headers)->assertCreated();

    // a key without sets: only the administrator's own, only from a key without sets
    $this->postJson('/api/v2/users/arcadmin/access-keys', ['permissions' => []], $headers)->assertCreated()
        ->assertJsonPath('data.permissions', []);
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === ['arcadmin', '', '', 'json']);
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => []], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/arcadmin/access-keys', ['permissions' => []], apiAs($this->fake, 'arcadmin', 'admin', area: 'accounts'))->assertForbidden()
        ->assertJsonPath('error.message', 'A key with permission sets cannot create a key without sets.');
    $this->fake->assertRan('v-add-access-key', null, 3);
});

it('plans a key without a secret in the dry run and refuses a v2-read key before any command', function () {
    $response = $this->postJson('/api/v2/users/bob/access-keys?dry_run=1', ['permissions' => ['v2-accounts'], 'comment' => 'ci'], apiAs($this->fake, 'bob', 'user', area: 'accounts'))
        ->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-access-key')
        ->assertJsonPath('data.steps.0.args', ['bob', 'v2-accounts', 'ci', 'json']);
    expect((string) $response->getContent())->not->toContain(NEW_SECRET)->not->toContain(STORED_SECRET);

    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-read']], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-add-access-key']);
    $this->deleteJson('/api/v2/users/bob/access-keys/'.BOB_API_KEY, [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-delete-access-key']);
    $this->fake->assertNotRan('v-add-access-key');
    $this->fake->assertNotRan('v-delete-access-key');
});

it('deletes a key, the calling key itself too', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'accounts');

    $this->deleteJson('/api/v2/users/bob/access-keys/'.BOB_API_KEY, [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-access-key', fn ($a) => $a === [BOB_API_KEY]);
    $this->deleteJson('/api/v2/users/bob/access-keys/'.API_KEY_ID, [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-access-key', fn ($a) => $a === [API_KEY_ID]);

    $this->deleteJson('/api/v2/users/bob/access-keys/'.CAROL_API_KEY, [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/users/bob/access-keys/'.BOB_API_KEY, [], apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->deleteJson('/api/v2/users/carol/access-keys/'.CAROL_API_KEY, [], apiAs($this->fake, 'arcadmin', 'admin'))->assertNoContent();
    $this->fake->assertRan('v-delete-access-key', null, 3);
});

it('lets a key without the commands of key management delete itself, and only itself', function (string $set) {
    $headers = apiKey($this->fake, 'bob', 'user', [$set]);

    $this->deleteJson('/api/v2/users/bob/access-keys/'.API_KEY_ID.'?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', [API_KEY_ID]);
    $this->fake->assertNotRan('v-delete-access-key');
    $this->deleteJson('/api/v2/users/bob/access-keys/'.API_KEY_ID, [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-access-key', fn ($a) => $a === [API_KEY_ID], 1);

    $this->deleteJson('/api/v2/users/bob/access-keys/'.BOB_API_KEY, [], $headers)->assertForbidden();
    $this->fake->assertRan('v-delete-access-key', null, 1);
})->with(['v2-read', 'v2-files']);

it('gives keys to the root administrator only with API_SYSTEM 1', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '1', 'API_ALLOWED_IP' => 'allow-all']);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/users/bob/access-keys', $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-read']], $headers)->assertNotFound();
    $this->getJson('/api/v2/access-key-sets?user=bob', $headers)->assertNotFound();
    $this->getJson('/api/v2/users/arcadmin/access-keys', $headers)->assertOk();
    $this->fake->assertNotRan('v-add-access-key');
});

it('lists the sets the caller may give', function () {
    $this->getJson('/api/v2/access-key-sets', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('data.0', ['name' => 'billing', 'api' => 1, 'description' => 'REST API v1: user accounts and packages for a billing system.'])
        ->assertJsonPath('data.2.name', 'v2-accounts')
        ->assertJsonPath('data.2.api', 2);

    $response = $this->getJson('/api/v2/access-key-sets?user=bob', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    expect(array_column($response->json('data'), 'name'))->toBe(['purge-nginx-cache', 'v2-accounts', 'v2-read', 'v2-web', 'v2-write']);

    $this->getJson('/api/v2/access-key-sets', apiAs($this->fake, 'bob', 'user', area: 'accounts'))->assertOk()
        ->assertJsonPath('data', [['name' => 'v2-accounts', 'api' => 2, 'description' => 'REST API v2 only: users, packages and the account (logs, statistics, notifications, search, SSH and access keys).']]);
    $this->getJson('/api/v2/access-key-sets?user=bob', apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->getJson('/api/v2/access-key-sets?user=bob', apiAs($this->fake, 'resb', 'reseller'))->assertNotFound();
});
