<?php

use App\Arc\ExitCode;

// REST API v2 Node.js apps (E34 batch 4 spec §4.2, §6).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes', 'NODE_APPS' => '3']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
    $this->fake->on('v-list-node-apps', ['bob', 'json'])->returnsJson(nodeAppList('bob', [nodeApp('api.bob.example')]));
    $this->fake->on('v-list-node-apps', ['carol', 'json'])->returnsJson(nodeAppList('carol', [nodeApp('old.carol.example', ['STATUS' => 'errored'])], ['NODE' => 'no']));
    $this->fake->on('v-list-node-app', ['bob', 'api.bob.example', 'json'])->returnsJson(['api.bob.example' => nodeApp('api.bob.example', ['URL' => 'https://api.bob.example/', 'SSL' => 'yes'])]);
    $this->fake->on('v-list-node-app', ['carol', 'old.carol.example', 'json'])->returnsJson(['old.carol.example' => nodeApp('old.carol.example')]);
    $this->fake->on('v-list-node-app', ['bob', 'ghost.example', 'json'])->returnsExit(ExitCode::NotExist, 'Error: ghost.example has no Node.js app');
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user));
    }
});

it('reads apps inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/node-apps", apiAs($this->fake, $principal, $role, readOnly: true, area: 'apps'))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data.0.domain', 'api.bob.example')
            ->assertJsonPath('meta.room', true)
            ->assertJsonPath('meta.totals.online', 1);
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

it('refuses manage without NODE but allows stop and delete with a v2-apps key', function () {
    $headers = apiAs($this->fake, 'carol', 'user', area: 'apps');
    $this->fake->on('v-stop-node-app')->returns();
    $this->fake->on('v-delete-node-app')->returns();

    $this->postJson('/api/v2/users/carol/node-apps', ['domain' => 'new.carol.example'], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'Node.js is not included in your package.');
    $this->postJson('/api/v2/users/carol/node-apps/old.carol.example/start', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/users/carol/node-apps/old.carol.example/stop', [], $headers)->assertOk();
    $this->fake->assertRan('v-stop-node-app', fn ($a) => $a === ['carol', 'old.carol.example']);
    $this->deleteJson('/api/v2/users/carol/node-apps/old.carol.example', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-node-app', fn ($a) => $a === ['carol', 'old.carol.example']);
});

it('starts install with Link and refuses a busy slot', function () {
    $this->fake->on('v-start-user-node-task')->returns('88');
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/node-apps/api.bob.example/install', [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/node:bob:88>; rel="monitor"')
        ->assertJsonPath('data.id', 'node:bob:88');
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'app-install', 'api.bob.example']);

    $this->fake->on('v-start-user-node-task')->returnsExit(ExitCode::InUse, 'Error: Another Node.js task is running for bob');
    $this->fake->on('v-list-user-node-task', ['bob', '100', 'json'])->returnsJson(nodeTask('bob', [
        'ID' => '77', 'ACTION' => 'app-install', 'ARGS' => 'api.bob.example', 'STATUS' => 'running', 'LOG' => 'working',
    ]));
    $this->postJson('/api/v2/users/bob/node-apps/api.bob.example/build', ['script' => 'build'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.existing', '/api/v2/tasks/node:bob:77');
});

it('plans without running and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/node-apps/api.bob.example/start?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-start-node-app');
    $this->fake->assertNotRan('v-start-node-app');

    $this->postJson('/api/v2/users/bob/node-apps/api.bob.example/start', [], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-start-node-app');
    $this->fake->assertNotRan('v-start-node-app');
});

it('refuses writes on a read-only key', function () {
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/node-apps', ['domain' => 'x.example'], $readHeaders)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/node-apps/api.bob.example', [], $readHeaders)->assertForbidden();
});

it('masks environment values unless reveal is allowed', function () {
    $this->fake->on('v-list-node-app-env', ['bob', 'api.bob.example', 'json'])->returnsJson(['VARS' => [
        ['KEY' => 'API_KEY', 'VALUE' => 's3cr3t'],
    ]]);
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');

    $this->getJson('/api/v2/users/bob/node-apps/api.bob.example/env', $readHeaders)->assertOk()
        ->assertJsonPath('data.0', ['name' => 'API_KEY', 'set' => true])
        ->assertJsonMissingPath('data.0.value');
    $this->getJson('/api/v2/users/bob/node-apps/api.bob.example', $readHeaders)->assertOk()->assertJsonMissingPath('data.env');

    $this->getJson('/api/v2/users/bob/node-apps/api.bob.example/env?reveal=1', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden();
    $this->getJson('/api/v2/users/bob/node-apps/api.bob.example/env?reveal=1', apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertOk()
        ->assertJsonPath('data.0.value', 's3cr3t');
});

it('keeps stored env values when an entry omits value on put', function () {
    $this->fake->on('v-list-node-app-env', ['bob', 'api.bob.example', 'json'])->returnsJson(['VARS' => [
        ['KEY' => 'API_KEY', 'VALUE' => 's3cr3t'],
        ['KEY' => 'MODE', 'VALUE' => 'prod'],
    ]]);
    $this->fake->on('v-change-node-app-env')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->putJson('/api/v2/users/bob/node-apps/api.bob.example/env', [
        'entries' => [['name' => 'API_KEY'], ['name' => 'MODE', 'value' => 'staging']],
    ], $headers)->assertOk();
    $this->fake->assertRan('v-change-node-app-env', fn ($a) => $a === ['bob', 'api.bob.example', "API_KEY=s3cr3t\nMODE=staging\n"]);
});

it('pages log snapshots with an opaque cursor', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');
    $this->fake->on('v-list-node-app-logs', ['bob', 'api.bob.example', 'out', '', '200', 'json'])->returnsJson([
        'STREAM' => 'out', 'SIZE' => '40', 'OFFSET' => '20', 'LINES' => ['line one'],
    ]);
    $first = $this->getJson('/api/v2/users/bob/node-apps/api.bob.example/logs', $headers)->assertOk()
        ->assertJsonPath('data.lines', ['line one']);
    $cursor = $first->json('meta.next');
    expect($cursor)->not->toBeNull();

    $this->fake->on('v-list-node-app-logs', ['bob', 'api.bob.example', 'out', '20', '200', 'json'])->returnsJson([
        'STREAM' => 'out', 'SIZE' => '40', 'OFFSET' => '40', 'LINES' => ['line two'],
    ]);
    $this->getJson('/api/v2/users/bob/node-apps/api.bob.example/logs?after='.urlencode((string) $cursor), $headers)->assertOk()
        ->assertJsonPath('data.lines', ['line two']);
});

it('creates an app with Location', function () {
    $this->fake->on('v-add-node-app')->returns();
    $this->fake->on('v-list-node-app', ['bob', 'shop.bob.example', 'json'])->returnsJson(['shop.bob.example' => nodeApp('shop.bob.example')]);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/node-apps', ['domain' => 'Shop.Bob.Example', 'start' => 'npm:start'], $headers)->assertCreated()
        ->assertHeader('Location', url('/api/v2/users/bob/node-apps/shop.bob.example'))
        ->assertJsonPath('data.domain', 'shop.bob.example');
    $this->fake->assertRan('v-add-node-app', fn ($a) => $a[1] === 'shop.bob.example');
});
