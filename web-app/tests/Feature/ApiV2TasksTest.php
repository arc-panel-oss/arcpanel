<?php

use App\Arc\ExitCode;

// REST API v2 tasks (E34 batch 3 spec §5, §8).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
});

function fakeTaskSources($fake, string $user = 'bob'): void
{
    $fake->on('v-list-sys-task', ['100', 'json'])->returnsJson([
        'ID' => '17280000009001', 'KIND' => 'php', 'ARGS' => '8.2', 'STATUS' => 'done', 'EXIT' => '0',
        'START' => '2026-10-07 10:00:00', 'END' => '2026-10-07 10:05:00', 'LOG' => "done\n",
    ]);
    $fake->on('v-list-sys-docker-task', ['0', 'json'])->returnsJson(dockerTask([
        'ID' => '17280000008001', 'ACTION' => 'install', 'STATUS' => 'running', 'LOG' => 'pull', 'OFFSET' => '0',
    ]));
    $fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user, [
        'ID' => '17280000007001', 'ACTION' => 'add-version', 'ARGS' => '24.21.0', 'STATUS' => 'done', 'EXIT' => '0',
        'START' => '2026-10-07 11:00:00', 'END' => '2026-10-07 11:01:00', 'LOG' => "ok\n",
    ]));
    $fake->on('v-list-user-quick-install-task', [$user, '100', 'json'])->returnsJson([
        'USER' => $user, 'ID' => '17280000006001', 'DOMAIN' => 'example.com', 'APP' => 'WordPress', 'STATUS' => 'done', 'EXIT' => '0',
        'START' => '2026-10-07 09:00:00', 'END' => '2026-10-07 09:10:00', 'LOG' => "installed\n",
    ]);
    $fake->on('v-list-docker-apps', [$user, 'json'])->returnsJson(dockerAppList($user, [dockerApp('kuma')]));
    $fake->on('v-list-docker-app-task', [$user, 'kuma', '0', 'json'])->returnsJson(dockerTask([
        'ID' => '17280000005001', 'ACTION' => 'update', 'STATUS' => 'failed', 'EXIT' => '1', 'LOG' => "err\n", 'OFFSET' => '0',
    ]));
    $fake->on('v-list-app-process-task', [$user, 'json'])->returnsJson(['TASK' => [
        'ID' => '17280000003001', 'DOMAIN' => 'shop.example', 'NAME' => 'web', 'TASK' => 'migrate',
        'STATUS' => 'done', 'EXIT' => '0', 'START' => '2026-10-07 08:00:00', 'END' => '2026-10-07 08:00:30',
    ]]);
    $fake->on('v-list-user-tasks', [$user, 'json'])->returnsJson([
        '17280000004002' => ['ID' => '17280000004002', 'KIND' => 'letsencrypt', 'ARGS' => 'shop.example', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 08:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
        '17280000004001' => ['ID' => '17280000004001', 'KIND' => 'backup', 'ARGS' => 'bob.2026-10-01.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
            'QUEUED' => '2026-10-07 05:00:00', 'START' => '2026-10-07 05:00:01', 'END' => '2026-10-07 05:10:00', 'LOG' => "backup ok\n"],
    ]);
    $fake->on('v-list-user-tasks', [$user, '17280000004001', '100', 'json'])->returnsJson([
        'ID' => '17280000004001', 'KIND' => 'backup', 'ARGS' => 'bob.2026-10-01.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
        'QUEUED' => '2026-10-07 05:00:00', 'START' => '2026-10-07 05:00:01', 'END' => '2026-10-07 05:10:00', 'LOG' => "backup ok\n",
    ]);
}

it('maps the six sources to the Task schema', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/tasks/sys:17280000009001', $headers)->assertOk()
        ->assertJsonPath('data.id', 'sys:17280000009001')
        ->assertJsonPath('data.kind', 'php')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.resource', null)
        ->assertJsonPath('data.queued_at', null);

    $this->getJson('/api/v2/tasks/docker-engine:17280000008001', $headers)->assertOk()
        ->assertJsonPath('data.resource', '/system/docker')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.exit_code', null);

    $this->getJson('/api/v2/tasks/node:bob:17280000007001', $headers)->assertOk()
        ->assertJsonPath('data.resource', '/users/bob/node');

    $this->getJson('/api/v2/tasks/install:bob:17280000006001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'quick-install')
        ->assertJsonPath('data.resource', '/users/bob/web-domains/example.com');

    $this->getJson('/api/v2/tasks/docker:bob:kuma:17280000005001', $headers)->assertOk()
        ->assertJsonPath('data.resource', '/users/bob/docker-apps/kuma');

    $this->getJson('/api/v2/tasks/queue:bob:17280000004001', $headers)->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.queued_at', '2026-10-07T05:00:00')
        ->assertJsonPath('data.resource', '/users/bob/backups/bob.2026-10-01.tar');
});

it('answers 410 when a single-slot source holds another task or none', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/tasks/sys:17280000000000', $headers)->assertStatus(410)->assertJsonPath('error.code', 'E_GONE');
    $this->fake->on('v-list-sys-task', ['100', 'json'])->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->getJson('/api/v2/tasks/sys:17280000009001', $headers)->assertStatus(410);
});

it('answers 410 for an expired queue task and 404 for a malformed id', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'hostco', 'reseller');
    $this->fake->on('v-list-user-tasks', ['bob', '17280000003999', '100', 'json'])->returnsExit(ExitCode::NotExist->value, "Error: task 17280000003999 doesn't exist");

    $this->getJson('/api/v2/tasks/queue:bob:17280000003999', $headers)->assertStatus(410);
    $this->getJson('/api/v2/tasks/not-a-source:bob:17280000004001', $headers)->assertNotFound();
    $this->getJson('/api/v2/tasks/node:carol:17280000007001', $headers)->assertNotFound();

    // A record id is 14 digits: 1–4 digits would be LINES to v-list-user-tasks
    $admin = apiAs($this->fake, 'arcadmin', 'admin');
    foreach (['queue:bob:abc', 'queue:bob:../x', 'queue:bob:3999', 'sys:1', 'node:bob:1728000000700', 'docker:bob:kuma:x'] as $malformed) {
        $this->getJson('/api/v2/tasks/'.$malformed, $admin)->assertNotFound();
    }
    $this->fake->assertRan('v-list-user-tasks', fn ($a) => $a[1] !== '17280000003999', times: 0);
});

it('answers 500 when a task status cannot be mapped', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'hostco', 'reseller');
    $this->fake->on('v-list-user-tasks', ['bob', '17280000004001', '100', 'json'])->returnsJson([
        'ID' => '17280000004001', 'KIND' => 'backup', 'ARGS' => 'bob.tar', 'STATUS' => 'weird', 'EXIT' => '0', 'ERROR' => '',
        'QUEUED' => '2026-10-07 05:00:00', 'START' => '', 'END' => '', 'LOG' => '',
    ]);

    $this->getJson('/api/v2/tasks/queue:bob:17280000004001', $headers)->assertStatus(500)->assertJsonPath('error.code', 'E_SERVER');

    $this->fake->on('v-list-user-tasks', ['bob', 'json'])->returnsJson([
        '17280000004001' => ['ID' => '17280000004001', 'KIND' => 'backup', 'ARGS' => 'bob.tar', 'STATUS' => 'weird', 'EXIT' => '0', 'ERROR' => '',
            'QUEUED' => '2026-10-07 05:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
    ]);
    $this->getJson('/api/v2/users/bob/tasks', $headers)->assertStatus(500)->assertJsonPath('error.code', 'E_SERVER');
});

it('hides system tasks from non-administrators', function () {
    fakeTaskSources($this->fake);
    $this->getJson('/api/v2/tasks/sys:17280000009001', apiAs($this->fake, 'bob', 'user'))->assertNotFound();
    $this->getJson('/api/v2/system/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->getJson('/api/v2/system/tasks', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()->assertJsonCount(2, 'data');
});

it('lists user tasks newest first and respects the tree', function () {
    fakeTaskSources($this->fake);
    $this->getJson('/api/v2/users/bob/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertOk()
        ->assertJsonPath('data.0.id', 'queue:bob:17280000004002')
        ->assertJsonFragment(['id' => 'queue:bob:17280000004001', 'status' => 'succeeded'])
        ->assertJsonCount(6, 'data');
    $this->getJson('/api/v2/users/carol/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
});

it('pages the user tasks with limit and offset', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $all = $this->getJson('/api/v2/users/bob/tasks', $headers)->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonMissingPath('meta.limit')
        ->json('data.*.id');
    $this->getJson('/api/v2/users/bob/tasks?limit=2&offset=1', $headers)->assertOk()
        ->assertJsonPath('data.*.id', array_slice($all, 1, 2))
        ->assertJsonPath('meta', ['total' => 6, 'limit' => 2, 'offset' => 1]);
    $this->getJson('/api/v2/users/bob/tasks?limit=0', $headers)->assertUnprocessable();
});

it('lets admin, both resellers and users read tasks inside their tree only', function (string $principal, string $role, string $owner, int $status) {
    fakeTaskSources($this->fake, $owner === 'carol' ? 'carol' : 'bob');
    if ($owner === 'carol') {
        $this->fake->on('v-list-user-tasks', ['carol', 'json'])->returnsJson([
            '17280000005001' => ['ID' => '17280000005001', 'KIND' => 'backup', 'ARGS' => 'carol.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
                'QUEUED' => '2026-10-08 09:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
        ]);
        $this->fake->on('v-list-user-tasks', ['carol', '17280000005001', '100', 'json'])->returnsJson([
            'ID' => '17280000005001', 'KIND' => 'backup', 'ARGS' => 'carol.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:00:00', 'START' => '', 'END' => '', 'LOG' => '',
        ]);
        $this->fake->on('v-list-user-node-task', ['carol', '100', 'json'])->returnsJson(['ID' => '', 'ACTION' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
        $this->fake->on('v-list-user-quick-install-task', ['carol', '100', 'json'])->returnsJson(['ID' => '', 'USER' => 'carol', 'DOMAIN' => '', 'APP' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
        $this->fake->on('v-list-docker-apps', ['carol', 'json'])->returnsJson(['APPS' => []]);
    }

    $this->getJson("/api/v2/users/{$owner}/tasks", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);

    $taskId = $owner === 'carol' ? 'queue:carol:17280000005001' : 'queue:bob:17280000004001';
    $this->getJson('/api/v2/tasks/'.$taskId, apiAs($this->fake, $principal, $role, readOnly: true))
        ->assertStatus($status === 200 ? 200 : 404)
        ->when($status === 200, fn ($response) => $response->assertJsonPath('data.status', 'succeeded'));
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'reseller B, a customer of A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

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
    $this->getJson('/api/v2/tasks/node:bob:17280000007001', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk();
    $this->getJson('/api/v2/tasks/node:bob:17280000007001', apiAs($this->fake, 'bob', 'user', area: 'backup'))->assertOk();
    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt', [], apiAs($this->fake, 'bob', 'user', area: 'web'))
        ->assertStatus(202)
        ->assertHeader('Link');
    $this->fake->assertRan('v-schedule-letsencrypt-domain');
});

// Fleet PD4: the one-off task of app processes is a task source
// (docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §6)
it('reads the app process task as a task and lists it with the others', function () {
    fakeTaskSources($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/tasks/app-process:bob:17280000003001', $headers)->assertOk()
        ->assertJsonPath('data.id', 'app-process:bob:17280000003001')
        ->assertJsonPath('data.kind', 'app-process')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.args', 'shop.example web migrate')
        ->assertJsonPath('data.started_at', '2026-10-07T08:00:00')
        ->assertJsonPath('data.finished_at', '2026-10-07T08:00:30')
        ->assertJsonPath('data.resource', '/users/bob/app-processes/shop.example/web');
    $ids = array_column($this->getJson('/api/v2/users/bob/tasks', $headers)->assertOk()->json('data'), 'id');
    expect($ids)->toContain('app-process:bob:17280000003001');
});

it('answers 410 for an app process task that is no longer kept, 404 outside the tree', function () {
    fakeTaskSources($this->fake);

    $this->getJson('/api/v2/tasks/app-process:bob:17280000003999', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertStatus(410)
        ->assertJsonPath('error.code', 'E_GONE');
    $this->getJson('/api/v2/tasks/app-process:bob:17280000003001', apiAs($this->fake, 'carol', 'user', readOnly: true))->assertNotFound();
});
