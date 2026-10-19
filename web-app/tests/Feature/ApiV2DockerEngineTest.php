<?php

use App\Arc\ExitCode;

// REST API v2 Docker engine (E34 batch 4 spec §4.4, §6).

beforeEach(function () {
    $this->fake = fakeApi(['DOCKER_SYSTEM' => 'yes']);
    fakeResellers($this->fake);
    $this->fake->on('v-list-sys-docker', ['json'])->returnsJson([
        'SYSTEM' => 'yes', 'RUNNING' => 'yes', 'VERSION' => '29.8.2', 'COMPOSE_VERSION' => '2.40.3',
        'DATA_ROOT' => '/var/lib/docker', 'DISK_USED' => '1024', 'DISK_FREE' => '4096', 'IMAGES' => '12',
        'CONTAINERS' => '8', 'APPS' => '4', 'USERS' => '2',
        'REPORT' => ['TIME' => '2026-10-05 10:00:00', 'FINDINGS' => [
            ['USER' => 'bob', 'APP' => 'kuma', 'TYPE' => 'stopped', 'DETAIL' => '', 'REPAIRED' => 'yes'],
        ]],
    ]);
    $this->fake->on('v-list-sys-docker-task', ['0', 'json'])->returnsJson(dockerTask());
});

it('reads the engine for administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/docker', apiAs($this->fake, $principal, $role, area: 'server'))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('data.installed', true)
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.version', '29.8.2')
            ->assertJsonPath('data.compose_version', '2.40.3')
            ->assertJsonPath('data.data_root', '/var/lib/docker')
            ->assertJsonPath('data.report.findings.0.repaired', true);
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('installs with Link and refuses a busy slot', function () {
    $this->fake->on('v-start-sys-docker-task')->returns("88\n");
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/docker/install', [], $headers)->assertStatus(202)->assertDocumentedHeaders('installDockerEngine')
        ->assertHeader('Link', '</api/v2/tasks/docker-engine:88>; rel="monitor"')
        ->assertJsonPath('data.id', 'docker-engine:88')
        ->assertJsonPath('data.status', 'running');
    $this->fake->assertRan('v-start-sys-docker-task', fn ($a) => $a === []);

    $this->fake->on('v-start-sys-docker-task')->returnsExit(ExitCode::InUse, 'Error: A task is already running');
    $this->fake->on('v-list-sys-docker-task', ['0', 'json'])->returnsJson(dockerTask([
        'ID' => '77', 'ACTION' => 'install', 'STATUS' => 'running', 'LOG' => 'working',
    ]));
    $this->postJson('/api/v2/system/docker/install', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.existing', '/api/v2/tasks/docker-engine:77');
});

it('plans without running and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/docker/install?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-start-sys-docker-task');
    $this->fake->assertNotRan('v-start-sys-docker-task');

    $readHeaders = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);
    $this->postJson('/api/v2/system/docker/install', [], $readHeaders)
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-start-sys-docker-task');
    $this->fake->assertNotRan('v-start-sys-docker-task');
});

it('refuses install on a read-only key', function () {
    $this->postJson('/api/v2/system/docker/install', [], apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']))
        ->assertForbidden();
});
