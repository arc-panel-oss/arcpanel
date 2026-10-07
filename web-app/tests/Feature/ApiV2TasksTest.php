<?php

use App\Api\TaskId;
use App\Arc\ExitCode;
use App\Services\Tasks\TaskMapper;

// REST API v2 tasks (E34 batch 3 spec §5, §8).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
});

function fakeTaskSources($fake, string $user = 'bob'): void
{
    $fake->on('v-list-sys-task', ['100', 'json'])->returnsJson([
        'ID' => '9001', 'KIND' => 'php', 'ARGS' => '8.2', 'STATUS' => 'done', 'EXIT' => '0',
        'START' => '2026-10-07 10:00:00', 'END' => '2026-10-07 10:05:00', 'LOG' => "done\n",
    ]);
    $fake->on('v-list-sys-docker-task', ['0', 'json'])->returnsJson(dockerTask([
        'ID' => '8001', 'ACTION' => 'install', 'STATUS' => 'running', 'LOG' => 'pull', 'OFFSET' => '0',
    ]));
    $fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user, [
        'ID' => '7001', 'ACTION' => 'add-version', 'ARGS' => '24.21.0', 'STATUS' => 'done', 'EXIT' => '0',
        'START' => '2026-10-07 11:00:00', 'END' => '2026-10-07 11:01:00', 'LOG' => "ok\n",
    ]));
    $fake->on('v-list-user-quick-install-task', [$user, '100', 'json'])->returnsJson([
        'USER' => $user, 'ID' => '6001', 'DOMAIN' => 'example.com', 'APP' => 'WordPress', 'STATUS' => 'done', 'EXIT' => '0',
        'START' => '2026-10-07 09:00:00', 'END' => '2026-10-07 09:10:00', 'LOG' => "installed\n",
    ]);
    $fake->on('v-list-docker-apps', [$user, 'json'])->returnsJson(dockerAppList($user, [dockerApp('kuma')]));
    $fake->on('v-list-docker-app-task', [$user, 'kuma', '0', 'json'])->returnsJson(dockerTask([
        'ID' => '5001', 'ACTION' => 'update', 'STATUS' => 'failed', 'EXIT' => '1', 'LOG' => "err\n", 'OFFSET' => '0',
    ]));
    $fake->on('v-list-user-tasks', [$user, 'json'])->returnsJson([
        '4002' => ['ID' => '4002', 'KIND' => 'letsencrypt', 'ARGS' => 'shop.example', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 08:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
        '4001' => ['ID' => '4001', 'KIND' => 'backup', 'ARGS' => 'bob.2026-10-01.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
            'QUEUED' => '2026-10-07 05:00:00', 'START' => '2026-10-07 05:00:01', 'END' => '2026-10-07 05:10:00', 'LOG' => "backup ok\n"],
    ]);
    $fake->on('v-list-user-tasks', [$user, '4001', '100', 'json'])->returnsJson([
        'ID' => '4001', 'KIND' => 'backup', 'ARGS' => 'bob.2026-10-01.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
        'QUEUED' => '2026-10-07 05:00:00', 'START' => '2026-10-07 05:00:01', 'END' => '2026-10-07 05:10:00', 'LOG' => "backup ok\n",
    ]);
}

it('maps the six sources to the Task schema', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/tasks/sys:9001', $headers)->assertOk()
        ->assertJsonPath('data.id', 'sys:9001')
        ->assertJsonPath('data.kind', 'php')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.resource', null)
        ->assertJsonPath('data.queued_at', null);

    $this->getJson('/api/v2/tasks/docker-engine:8001', $headers)->assertOk()
        ->assertJsonPath('data.resource', '/system/docker')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.exit_code', null);

    $this->getJson('/api/v2/tasks/node:bob:7001', $headers)->assertOk()
        ->assertJsonPath('data.resource', '/users/bob/node');

    $this->getJson('/api/v2/tasks/install:bob:6001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'quick-install')
        ->assertJsonPath('data.resource', '/users/bob/web-domains/example.com');

    $this->getJson('/api/v2/tasks/docker:bob:kuma:5001', $headers)->assertOk()
        ->assertJsonPath('data.resource', '/users/bob/docker-apps/kuma');

    $this->getJson('/api/v2/tasks/queue:bob:4001', $headers)->assertOk()
        ->assertJsonPath('data.queued_at', '2026-10-07T05:00:00')
        ->assertJsonPath('data.resource', '/users/bob/backups/bob.2026-10-01.tar');
});

it('answers 410 when a single-slot source holds another task or none', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/tasks/sys:0000', $headers)->assertStatus(410)->assertJsonPath('error.code', 'E_GONE');
    $this->fake->on('v-list-sys-task', ['100', 'json'])->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->getJson('/api/v2/tasks/sys:9001', $headers)->assertStatus(410);
});

it('answers 410 for an expired queue task and 404 for a malformed id', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'hostco', 'reseller');
    $this->fake->on('v-list-user-tasks', ['bob', '3999', '100', 'json'])->returnsExit(ExitCode::NotExist->value, "Error: task 3999 doesn't exist");

    $this->getJson('/api/v2/tasks/queue:bob:3999', $headers)->assertStatus(410);
    $this->getJson('/api/v2/tasks/not-a-source:bob:1', $headers)->assertNotFound();
    $this->getJson('/api/v2/tasks/node:carol:7001', $headers)->assertNotFound();
});

it('hides system tasks from non-administrators', function () {
    fakeTaskSources($this->fake);
    $this->getJson('/api/v2/tasks/sys:9001', apiAs($this->fake, 'bob', 'user'))->assertNotFound();
    $this->getJson('/api/v2/system/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->getJson('/api/v2/system/tasks', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()->assertJsonCount(2, 'data');
});

it('lists user tasks newest first and respects the tree', function () {
    fakeTaskSources($this->fake);
    $this->getJson('/api/v2/users/bob/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertOk()
        ->assertJsonPath('data.0.id', 'queue:bob:4002')
        ->assertJsonCount(5, 'data');
    $this->getJson('/api/v2/users/carol/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
});

it('adds Link on Let’s Encrypt 202 responses', function () {
    fakeWebDomains($this->fake);
    fakeMailDomains($this->fake);
    $this->fake->on('v-schedule-letsencrypt-domain')->returns("17290000001234\n");
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt', [], $headers)
        ->assertStatus(202)
        ->assertHeader('Location')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000001234>; rel="monitor"');

    $this->fake->on('v-schedule-letsencrypt-domain')->returns("17290000009999\n");
    $this->postJson('/api/v2/users/bob/mail-domains/example.com/letsencrypt', [], $headers)
        ->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009999>; rel="monitor"');
});

it('lets a v2-read key and an area key follow tasks', function () {
    fakeTaskSources($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-schedule-letsencrypt-domain')->returns("17290000001234\n");
    $this->getJson('/api/v2/tasks/node:bob:7001', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk();
    $this->getJson('/api/v2/tasks/node:bob:7001', apiAs($this->fake, 'bob', 'user', area: 'backup'))->assertOk();
    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt', [], apiAs($this->fake, 'bob', 'user', area: 'web'))
        ->assertStatus(202)
        ->assertHeader('Link');
    $this->fake->assertRan('v-schedule-letsencrypt-domain');
});

it('parses task ids and maps statuses in unit checks', function () {
    expect(TaskId::parse('sys:1')?->apiId())->toBe('sys:1')
        ->and(TaskId::parse('docker:bob:kuma:9')?->app)->toBe('kuma')
        ->and(TaskId::parse('bad:extra:too:many:parts'))->toBeNull();

    $view = TaskMapper::fromSource(TaskId::parse('node:bob:1') ?? throw new RuntimeException, [
        'ACTION' => 'app-install', 'ARGS' => 'app.example build', 'STATUS' => 'done', 'EXIT' => '0', 'START' => '', 'END' => '', 'LOG' => '',
    ], 100);
    expect($view->status)->toBe('succeeded')
        ->and($view->resource)->toBe('/users/bob/node-apps/app.example');
});
