<?php

use App\Arc\ExitCode;

// REST API v2 Docker apps (E34 batch 4 spec §4.3, §6).

beforeEach(function () {
    $this->fake = fakeApi(['DOCKER_SYSTEM' => 'yes']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['DOCKER' => 'yes', 'DOCKER_APPS' => '3']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['DOCKER' => 'no']]);
    $this->fake->on('v-list-docker-apps', ['bob', 'json'])->returnsJson(dockerAppList('bob', [dockerApp('kuma')]));
    $this->fake->on('v-list-docker-apps', ['carol', 'json'])->returnsJson(dockerAppList('carol', [dockerApp('old', ['STATUS' => 'failed'])], ['DOCKER' => 'no']));
    $this->fake->on('v-list-docker-app', ['bob', 'kuma', 'json'])->returnsJson(['kuma' => dockerApp('kuma', ['URL' => 'https://kuma.example', 'SSL' => 'yes'])]);
    $this->fake->on('v-list-docker-app', ['carol', 'old', 'json'])->returnsJson(['old' => dockerApp('old')]);
    $this->fake->on('v-list-docker-catalog', ['all', 'json'])->returnsJson([
        dockerCatalogRow('uptime-kuma'), dockerCatalogRow('portainer', ['ADMIN_ONLY' => true]),
    ]);
    $this->fake->on('v-list-docker-app-task', ['bob', 'kuma', '0', 'json'])->returnsJson(dockerTask());
});

it('reads apps inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/docker-apps", apiAs($this->fake, $principal, $role, readOnly: true, area: 'apps'))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data.0.name', 'kuma')
            ->assertJsonPath('meta.room', true)
            ->assertJsonPath('meta.engine', true)
            ->assertJsonPath('meta.totals.running', 1);
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

it('refuses manage without DOCKER but allows stop and delete with a v2-apps key', function () {
    $headers = apiAs($this->fake, 'carol', 'user', area: 'apps');
    $this->fake->on('v-stop-docker-app')->returns();
    $this->fake->on('v-delete-docker-app')->returns();

    $this->postJson('/api/v2/users/carol/docker-apps', ['slug' => 'uptime-kuma', 'name' => 'newapp'], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'Docker apps are not included in your package.');
    $this->postJson('/api/v2/users/carol/docker-apps/old/start', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/users/carol/docker-apps/old/stop', [], $headers)->assertOk();
    $this->fake->assertRan('v-stop-docker-app', fn ($a) => $a === ['carol', 'old']);
    $this->deleteJson('/api/v2/users/carol/docker-apps/old', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-docker-app', fn ($a) => $a === ['carol', 'old']);
});

it('installs with Link, Location and refuses a busy slot', function () {
    $this->fake->on('v-add-docker-app')->returns("88\n");

    $this->postJson('/api/v2/users/bob/docker-apps', ['slug' => 'uptime-kuma', 'name' => 'shop'], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/docker:bob:shop:88>; rel="monitor"')
        ->assertHeader('Location', url('/api/v2/users/bob/docker-apps/shop'))
        ->assertJsonPath('data.id', 'docker:bob:shop:88');

    $this->fake->on('v-start-docker-app-task')->returnsExit(ExitCode::InUse, 'Error: A task is already running');
    $this->fake->on('v-list-docker-app-task', ['bob', 'kuma', '0', 'json'])->returnsJson(dockerTask([
        'ID' => '77', 'ACTION' => 'update', 'STATUS' => 'running', 'LOG' => 'working',
    ]));
    $this->postJson('/api/v2/users/bob/docker-apps/kuma/tasks', ['action' => 'update'], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.existing', '/api/v2/tasks/docker:bob:kuma:77');
});

it('plans without running and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/docker-apps/kuma/start?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-start-docker-app');
    $this->fake->assertNotRan('v-start-docker-app');

    $this->postJson('/api/v2/users/bob/docker-apps/kuma/start', [], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-start-docker-app');
    $this->fake->assertNotRan('v-start-docker-app');
});

it('refuses writes on a read-only key', function () {
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/docker-apps', ['slug' => 'uptime-kuma', 'name' => 'x1'], $readHeaders)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/docker-apps/kuma', [], $readHeaders)->assertForbidden();
});

it('masks secret environment values on get and keeps secrets when value is empty on put', function () {
    $this->fake->on('v-list-docker-app-env', ['bob', 'kuma', 'json'])->returnsJson(['VARIABLES' => [
        ['NAME' => 'TZ', 'LABEL' => 'TZ', 'TYPE' => 'string', 'SECRET' => 'no', 'SET' => 'yes', 'VALUE' => 'UTC'],
        ['NAME' => 'TOKEN', 'LABEL' => 'Token', 'TYPE' => 'password', 'SECRET' => 'yes', 'SET' => 'yes', 'VALUE' => ''],
    ]]);
    $this->fake->on('v-change-docker-app-env')->returns();
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');

    $this->getJson('/api/v2/users/bob/docker-apps/kuma/env', $readHeaders)->assertOk()
        ->assertJsonPath('data.0.value', 'UTC')
        ->assertJsonPath('data.1.secret', true)
        ->assertJsonMissingPath('data.1.value');

    $this->putJson('/api/v2/users/bob/docker-apps/kuma/env', ['vars' => ['TZ' => 'Europe/Berlin', 'TOKEN' => '']], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertOk();
    $this->fake->assertRan('v-change-docker-app-env', fn ($a) => $a === ['bob', 'kuma', "TZ=Europe/Berlin\nTOKEN=\n"]);
});

it('pages log snapshots with an opaque cursor', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');
    $this->fake->on('v-list-docker-app-logs', ['bob', 'kuma', 'all', '', '200', 'json'])->returnsJson([
        'LINES' => [['TIME' => '2026-10-05T10:00:00.1Z', 'SERVICE' => 'app', 'TEXT' => 'ready']], 'NEXT' => '2026-10-05T10:00:00.1Z',
    ]);
    $first = $this->getJson('/api/v2/users/bob/docker-apps/kuma/logs', $headers)->assertOk()
        ->assertJsonPath('data.lines.0.text', 'ready');
    $cursor = $first->json('meta.next');
    expect($cursor)->not->toBeNull();

    $this->fake->on('v-list-docker-app-logs', ['bob', 'kuma', 'all', '2026-10-05T10:00:00.1Z', '200', 'json'])->returnsJson(['LINES' => [], 'NEXT' => '']);
    $this->getJson('/api/v2/users/bob/docker-apps/kuma/logs?after='.urlencode((string) $cursor), $headers)->assertOk()
        ->assertJsonPath('data.lines', [])
        ->assertJsonPath('meta.next', $cursor);
});

it('hides admin-only catalog apps from non-administrators', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');
    $this->getJson('/api/v2/docker-catalog', $headers)->assertOk()->assertJsonCount(1, 'data');
    $this->fake->on('v-list-docker-catalog-app', ['portainer', 'latest', 'json'])->returnsJson(['slug' => 'portainer', 'admin_only' => true]);
    $this->getJson('/api/v2/docker-catalog/portainer', $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/docker-apps', ['slug' => 'portainer', 'name' => 'port'], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertNotFound();
});
