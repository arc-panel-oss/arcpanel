<?php

use App\Arc\ExitCode;

// REST API v2 Node.js runtimes (E34 batch 4 spec §4.1, §6).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes', 'NODE_VERSIONS' => '3', 'NODE_ALLOWED' => 'lts']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
    $this->fake->on('v-list-user-node-versions', ['bob', 'json'])->returnsJson(nodeAccount('bob', [], [
        nodeRuntime('24.21.0', ['DEFAULT' => 'yes', 'PACKAGES' => '2']),
        nodeRuntime('22.23.3', ['LTS' => 'Jod', 'STATUS' => 'maintenance']),
    ]));
    $this->fake->on('v-list-user-node-versions', ['carol', 'json'])->returnsJson(nodeAccount('carol',
        ['PACKAGE' => 'default', 'NODE' => 'no', 'ENABLED' => 'yes'], [nodeRuntime('22.23.3', ['DEFAULT' => 'yes'])]));
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user));
    }
});

it('reads the account inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/node", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.default_version', '24.21.0')
            ->assertJsonPath('data.allowed_versions', 'lts')
            ->assertJsonPath('data.room', true)
            ->assertJsonPath('data.runtimes.0.version', '24.21.0');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('refuses manage without NODE but allows uninstall with a v2-apps key', function () {
    $headers = apiAs($this->fake, 'carol', 'user', area: 'apps');
    $this->fake->on('v-delete-user-node-version')->returns();

    $this->putJson('/api/v2/users/carol/node', ['enabled' => true], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'Node.js is not included in your package.');
    $this->postJson('/api/v2/users/carol/node/versions', ['spec' => '22'], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'Node.js is not included in your package.');
    $this->deleteJson('/api/v2/users/carol/node/versions/22.23.3', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-node-version', fn ($a) => $a === ['carol', '22.23.3']);
});

it('starts a version install with Link and refuses a busy slot', function () {
    $this->fake->on('v-start-user-node-task')->returns('17286400001234');
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/node/versions', ['spec' => '24'], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/node:bob:17286400001234>; rel="monitor"')
        ->assertJsonPath('data.id', 'node:bob:17286400001234')
        ->assertJsonPath('data.status', 'running');
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'add-version', '24']);

    $this->fake->on('v-start-user-node-task')->returnsExit(ExitCode::InUse, 'Error: Another Node.js task is running for bob');
    $this->fake->on('v-list-user-node-task', ['bob', '100', 'json'])->returnsJson(nodeTask('bob', [
        'ID' => '99', 'ACTION' => 'add-version', 'ARGS' => '22', 'STATUS' => 'running', 'LOG' => 'working',
    ]));
    $this->postJson('/api/v2/users/bob/node/versions', ['spec' => '22'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.existing', '/api/v2/tasks/node:bob:99');
});

it('plans without running and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/node/versions?dry_run=1', ['spec' => '22'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-start-user-node-task');
    $this->fake->assertNotRan('v-start-user-node-task');

    $this->postJson('/api/v2/users/bob/node/versions', ['spec' => '22'], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-start-user-node-task');
    $this->fake->assertNotRan('v-start-user-node-task');
});

it('refuses writes on a read-only key', function () {
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->putJson('/api/v2/users/bob/node', ['enabled' => false], $readHeaders)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/node/versions/22.23.3', [], $readHeaders)->assertForbidden();
});

it('lists releases, versions and packages', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');
    $this->fake->on('v-list-sys-node-releases', ['json'])->returnsJson(['ARCH' => 'x64', 'RELEASES' => [
        ['MAJOR' => '24', 'LATEST' => '24.21.0', 'LTS' => 'Krypton', 'STATUS' => 'active', 'EOL' => '2028-04-30', 'CACHED' => 'yes'],
    ]]);
    $this->fake->on('v-list-user-npm-packages', ['bob', '24.21.0', 'json'])->returnsJson([
        'USER' => 'bob', 'VERSION' => '24.21.0', 'CHECKED' => '2026-10-08 10:00:00', 'PACKAGES' => [
            ['NAME' => '@nestjs/cli', 'VERSION' => '11.0.0', 'LATEST' => '11.1.0', 'OUTDATED' => 'yes', 'BUNDLED' => 'no'],
        ],
    ]);

    $this->getJson('/api/v2/users/bob/node/releases', $headers)->assertOk()
        ->assertJsonPath('data.0.allowed', true);
    $this->getJson('/api/v2/users/bob/node/versions', $headers)->assertOk()
        ->assertJsonPath('data.0.version', '24.21.0');
    $this->getJson('/api/v2/users/bob/node/versions/24.21.0/packages', $headers)->assertOk()
        ->assertJsonPath('data.0.name', '@nestjs/cli')
        ->assertJsonPath('data.0.outdated', true)
        ->assertJsonPath('meta.checked_at', '2026-10-08 10:00:00');
});

it('queues package update and check tasks', function () {
    $this->fake->on('v-start-user-node-task')->returns('55');
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/node/versions/24.21.0/packages/update', [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/node:bob:55>; rel="monitor"');
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'update-packages', '24.21.0']);

    $this->postJson('/api/v2/users/bob/node/versions/24.21.0/packages/check', [], $headers)->assertStatus(202);
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'check-outdated', '24.21.0']);
});
