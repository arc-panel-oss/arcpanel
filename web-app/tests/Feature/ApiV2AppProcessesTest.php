<?php

use App\Arc\ExitCode;

// REST API v2 app processes (E42 spec §8).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['APP_PROCESSES' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['APP_PROCESSES' => 'no']]);
    $this->fake->on('v-list-app-processes', ['bob', 'json'])->returnsJson(appProcessList('bob', [appProcessRow('shop.bob.example')]));
    $this->fake->on('v-list-app-processes', ['carol', 'json'])->returnsJson(appProcessList('carol', [appProcessRow('old.carol.example', 'queue')], ['ENABLED' => 'no']));
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(appProcessRow('shop.bob.example', 'web', ['RELOAD' => 'command']));
    $this->fake->on('v-list-app-process', ['carol', 'old.carol.example', 'queue', 'json'])->returnsJson(appProcessRow('old.carol.example', 'queue'));
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'ghost', 'json'])->returnsExit(ExitCode::NotExist, 'Error: shop.bob.example has no app process named ghost');
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-app-process-task', [$user, 'json'])->returnsJson(['TASK' => null]);
    }
});

it('reads processes inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/app-processes", apiAs($this->fake, $principal, $role, readOnly: true, area: 'apps'))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data.0.domain', 'shop.bob.example')
            ->assertJsonPath('data.0.port', 30001)
            ->assertJsonPath('data.0.running', true)
            ->assertJsonPath('meta.room', true)
            ->assertJsonPath('meta.totals.running', 1)
            ->assertJsonPath('meta.max_memory_mb', 768);
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

it('gets one process and answers 404 for an unknown one', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps');

    $this->getJson('/api/v2/users/bob/app-processes/shop.bob.example/web', $headers)->assertOk()
        ->assertJsonPath('data.preset', 'octane-swoole')
        ->assertJsonPath('data.reload', 'command')
        ->assertJsonPath('data.template', 'laravel-octane');
    $this->getJson('/api/v2/users/bob/app-processes/shop.bob.example/ghost', $headers)->assertNotFound();
    $this->getJson('/api/v2/users/carol/app-processes/old.carol.example/queue', $headers)->assertNotFound();
});

it('lists the presets, for a domain with detection', function () {
    $this->fake->on('v-list-app-process-presets', ['bob', 'shop.bob.example', 'app', 'json'])->returnsJson(['PRESETS' => [
        ['NAME' => 'octane-swoole', 'TITLE' => 'Laravel Octane (Swoole)', 'KIND' => 'web', 'TEMPLATE' => 'laravel-octane', 'RUNTIME' => 'php',
            'START' => '/usr/bin/php8.4 artisan octane:start', 'RELOAD' => 'command', 'DETECTED' => 'yes', 'RUNTIME_MISSING' => '', 'TEMPLATE_AVAILABLE' => 'yes'],
        ['NAME' => 'celery', 'TITLE' => 'Celery worker', 'KIND' => 'worker', 'TEMPLATE' => '', 'RUNTIME' => 'python',
            'START' => '/usr/bin/celery -A app worker', 'RELOAD' => 'restart', 'DETECTED' => 'no', 'RUNTIME_MISSING' => 'Python 3 is not installed', 'TEMPLATE_AVAILABLE' => 'yes'],
    ]]);

    $this->getJson('/api/v2/users/bob/app-process-presets?domain=shop.bob.example&cwd=app', apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps'))->assertOk()
        ->assertJsonPath('data.0.detected', true)
        ->assertJsonPath('data.0.runtime_missing', null)
        ->assertJsonPath('data.1.template', null)
        ->assertJsonPath('data.1.runtime_missing', 'Python 3 is not installed');
});

it('creates a process and points to it', function () {
    $this->fake->on('v-add-app-process')->returns();
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'queue', 'json'])->returnsJson(appProcessRow('shop.bob.example', 'queue'));

    $this->postJson('/api/v2/users/bob/app-processes', ['domain' => 'shop.bob.example', 'name' => 'queue', 'preset' => 'laravel-queue', 'cwd' => 'app'],
        apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertCreated()
        ->assertHeader('Location', route('api.v2.app-processes.show', ['user' => 'bob', 'domain' => 'shop.bob.example', 'name' => 'queue']))
        ->assertJsonPath('data.kind', 'worker')
        ->assertJsonPath('data.port', null);
    $this->fake->assertRan('v-add-app-process', fn ($a) => $a === ['bob', 'shop.bob.example', 'queue', 'laravel-queue', '', 'app', '', '1']);
});

it('refuses manage without the grant but allows stop and delete with a v2-apps key', function () {
    $headers = apiAs($this->fake, 'carol', 'user', area: 'apps');
    $this->fake->on('v-stop-app-process')->returns();
    $this->fake->on('v-delete-app-process')->returns();

    $this->postJson('/api/v2/users/carol/app-processes', ['domain' => 'old.carol.example', 'name' => 'web', 'preset' => 'octane-swoole'], $headers)
        ->assertForbidden()->assertJsonPath('error.message', 'App processes are not included in your package.');
    $this->postJson('/api/v2/users/carol/app-processes/old.carol.example/queue/start', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/users/carol/app-processes/old.carol.example/queue/stop', [], $headers)->assertOk();
    $this->fake->assertRan('v-stop-app-process', fn ($a) => $a === ['carol', 'old.carol.example', 'queue']);
    $this->deleteJson('/api/v2/users/carol/app-processes/old.carol.example/queue', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-app-process', fn ($a) => $a === ['carol', 'old.carol.example', 'queue']);
});

it('updates the command and the limits in one request', function () {
    $this->fake->on('v-change-app-process-start')->returns();
    $this->fake->on('v-change-app-process-limits')->returns();

    $this->patchJson('/api/v2/users/bob/app-processes/shop.bob.example/web', [
        'start' => 'php artisan octane:start --port=%port%', 'cwd' => 'app', 'memory_mb' => 640, 'cpus' => '1.5',
    ], apiAs($this->fake, 'bob', 'user', area: 'apps'))->assertOk();
    $this->fake->assertRan('v-change-app-process-start', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', 'php artisan octane:start --port=%port%', 'app']);
    $this->fake->assertRan('v-change-app-process-limits', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', '640', '1.5']);
});

it('plans without running and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/app-processes/shop.bob.example/web/reload?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-reload-app-process');
    $this->fake->assertNotRan('v-reload-app-process');

    $this->postJson('/api/v2/users/bob/app-processes/shop.bob.example/web/reload', [], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-reload-app-process');
    $this->fake->assertNotRan('v-reload-app-process');
});

it('masks environment values unless reveal is allowed and keeps omitted values', function () {
    $this->fake->on('v-list-app-process-env', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(['VARS' => [
        ['KEY' => 'APP_KEY', 'VALUE' => 's3cr3t'],
        ['KEY' => 'APP_ENV', 'VALUE' => 'production'],
    ]]);
    $this->fake->on('v-change-app-process-env')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->getJson('/api/v2/users/bob/app-processes/shop.bob.example/web/env', $headers)->assertOk()
        ->assertJsonPath('data.0', ['name' => 'APP_KEY', 'set' => true]);
    $this->getJson('/api/v2/users/bob/app-processes/shop.bob.example/web/env?reveal=1', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden();
    // apiAs() answers for one key at a time: take the v2-apps key again
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');
    $this->getJson('/api/v2/users/bob/app-processes/shop.bob.example/web/env?reveal=1', $headers)->assertOk()
        ->assertJsonPath('data.0.value', 's3cr3t');

    $this->putJson('/api/v2/users/bob/app-processes/shop.bob.example/web/env', [
        'entries' => [['name' => 'APP_KEY'], ['name' => 'APP_ENV', 'value' => 'staging']],
    ], $headers)->assertOk()->assertJsonMissingPath('data.0.value');
    $this->fake->assertRan('v-change-app-process-env', fn ($a) => $a[0] === 'bob' && $a[2] === 'web' && count($a) === 4);
    $this->putJson('/api/v2/users/bob/app-processes/shop.bob.example/web/env', ['entries' => [['name' => 'HOST', 'value' => 'x']]], $headers)
        ->assertUnprocessable();
});

it('reads log snapshots with a cursor', function () {
    $this->fake->on('v-list-app-process-logs', ['bob', 'shop.bob.example', 'web', 'task', '', '50', 'json'])
        ->returnsJson(['STREAM' => 'task', 'SIZE' => '30', 'OFFSET' => '30', 'LINES' => ['migrated']]);

    $this->getJson('/api/v2/users/bob/app-processes/shop.bob.example/web/logs?stream=task&lines=50', apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'apps'))->assertOk()
        ->assertJsonPath('data.lines', ['migrated'])
        ->assertJsonPath('meta.stream', 'task')
        ->assertJsonPath('meta.next', rtrim(strtr(base64_encode('30'), '+/', '-_'), '='));
});

it('starts a task and refuses a busy slot', function () {
    $this->fake->on('v-start-app-process-task')->returns("17606214250042\n");
    $this->fake->on('v-list-app-process-task', ['bob', 'json'])->returnsJson(['TASK' => [
        'ID' => '17606214250042', 'DOMAIN' => 'shop.bob.example', 'NAME' => 'web', 'TASK' => 'composer-install',
        'STATUS' => 'running', 'EXIT' => '', 'START' => '2026-10-16 20:00:00', 'END' => '',
    ]]);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'apps');

    $this->postJson('/api/v2/users/bob/app-processes/shop.bob.example/web/tasks', ['task' => 'composer-install'], $headers)->assertStatus(202)->assertDocumentedHeaders('startAppProcessTask')
        ->assertHeader('Location', route('api.v2.app-process-task.show', ['user' => 'bob']))
        ->assertHeader('Link', '</api/v2/tasks/app-process:bob:17606214250042>; rel="monitor"')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.exit_code', null);
    $this->fake->assertRan('v-start-app-process-task', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', 'composer-install']);
    $this->getJson('/api/v2/users/bob/app-process-task', $headers)->assertOk()->assertJsonPath('data.task', 'composer-install');

    $this->fake->on('v-start-app-process-task')->returnsExit(ExitCode::InUse, 'Error: another app process task is running for bob');
    $this->postJson('/api/v2/users/bob/app-processes/shop.bob.example/web/tasks', ['task' => 'migrate'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.existing', '/api/v2/users/bob/app-process-task');
    $this->postJson('/api/v2/users/bob/app-processes/shop.bob.example/web/tasks', ['task' => 'custom'], $headers)->assertUnprocessable();
});

it('refuses writes on a read-only key', function () {
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/app-processes', ['domain' => 'x.example', 'name' => 'web', 'preset' => 'octane-swoole'], $readHeaders)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/app-processes/shop.bob.example/web', [], $readHeaders)->assertForbidden();
});
