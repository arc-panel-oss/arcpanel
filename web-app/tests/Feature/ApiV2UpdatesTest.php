<?php

use App\Arc\ExitCode;

// REST API v2, updates, system packages and graphs of the server (E34 batch 6
// spec §5.4–§5.6, D65). Nothing here updates or installs anything: every
// command is answered by the fake.

const UPDATE_TASK = '17286400001234';

beforeEach(function () {
    $this->fake = fakeApi(['UPGRADE_SEND_EMAIL' => 'true', 'UPGRADE_SEND_EMAIL_LOG' => 'false']);
    fakeResellers($this->fake);
    $package = fn (string $version, string $updated, string $descr) => [
        'VERSION' => $version, 'ARCH' => 'amd64', 'UPDATED' => $updated, 'DESCR' => $descr, 'TIME' => '21:41:08', 'DATE' => '2026-10-04',
    ];
    $this->fake->on('v-list-sys-arcpanel-updates')->returnsJson([
        'arcpanel' => $package('2.0.0-1+ubuntu24.04', 'yes', 'ArcPanel core package'),
        'arcpanel-php' => $package('8.5.11-1+ubuntu24.04', 'no', 'ArcPanel internal php interpreter'),
    ]);
    $this->fake->on('v-list-sys-arcpanel-autoupdate', ['plain'])->returns("Enabled\n");
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-start-sys-task')->returns(UPDATE_TASK."\n");
    $this->fake->on('v-list-sys-packages')->returnsJson(commandFixture('v-list-sys-packages/default.json'));
    $this->fake->on('v-list-sys-rrd')->returnsJson(commandFixture('v-list-sys-rrd/default.json'));
    $this->fake->on('v-export-rrd')->returnsJson(['about' => 'RRDtool xport JSON output', 'meta' => [
        'start' => 1791100800, 'end' => 1791101400, 'step' => 300, 'legend' => ['Load', 'Processes'],
    ], 'data' => [[0.25, 112], [null, null]]]);
    foreach (['v-add-cron-arcpanel-autoupdate', 'v-delete-cron-arcpanel-autoupdate', 'v-change-sys-config-value'] as $command) {
        $this->fake->on($command)->returns();
    }
});

it('lists the panel packages with the update settings for administrators only', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/updates', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'name' => 'arcpanel', 'version' => '2.0.0-1+ubuntu24.04', 'arch' => 'amd64', 'description' => 'ArcPanel core package',
                'up_to_date' => true, 'built_at' => '2026-10-04T21:41:08',
            ])
            ->assertJsonPath('data.1.up_to_date', false)
            ->assertJsonPath('meta', ['auto_update' => true, 'send_email' => true, 'send_log' => false]);
    } else {
        $response->assertJsonPath('error.code', 'E_FORBIDDEN');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every endpoint of this area to non-administrators before any command', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->getJson('/api/v2/system/packages', $headers)->assertForbidden();
    $this->getJson('/api/v2/system/graphs', $headers)->assertForbidden();
    $this->getJson('/api/v2/system/graphs/la', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/updates', [], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/updates/settings', ['auto_update' => false], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/packages/go', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/packages/python', [], $headers)->assertForbidden();
    foreach (['v-list-sys-packages', 'v-list-sys-rrd', 'v-export-rrd', 'v-start-sys-task', 'v-delete-cron-arcpanel-autoupdate'] as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

// Updates

it('updates one package or every package as the system task with a key holding only v2-server', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/updates', ['package' => 'arcpanel-php'], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/sys:'.UPDATE_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.id', 'sys:'.UPDATE_TASK)
        ->assertJsonPath('data.kind', 'arcpanel-update')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.args', 'arcpanel-php');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-update', 'arcpanel-php']);

    $this->postJson('/api/v2/system/updates', [], $headers)->assertStatus(202)->assertJsonPath('data.args', null);
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-update']);

    $this->postJson('/api/v2/system/updates', ['package' => 'nginx'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.package.0', fn ($m) => is_string($m));
    $this->fake->assertRan('v-start-sys-task', null, 2);
});

it('answers 409 E_INUSE with the running system task', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '17286300005678', 'KIND' => 'php', 'ARGS' => '8.3 -', 'STATUS' => 'running', 'EXIT' => '', 'START' => '2026-10-08 10:00:00', 'END' => '', 'LOG' => '']);
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/updates', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.existing', '/api/v2/tasks/sys:17286300005678');
    $this->postJson('/api/v2/system/packages/go', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.existing', '/api/v2/tasks/sys:17286300005678');
});

it('changes only the update settings that changed', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->putJson('/api/v2/system/updates/settings', ['auto_update' => true, 'send_email' => true], $headers)->assertOk()
        ->assertExactJson(['data' => ['auto_update' => true, 'send_email' => true, 'send_log' => false]]);
    $this->fake->assertNotRan('v-add-cron-arcpanel-autoupdate');
    $this->fake->assertNotRan('v-change-sys-config-value');

    $this->putJson('/api/v2/system/updates/settings', ['auto_update' => false, 'send_log' => true], $headers)->assertOk();
    $this->fake->assertRan('v-delete-cron-arcpanel-autoupdate', null, 1);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['UPGRADE_SEND_EMAIL_LOG', 'true'], 1);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'UPGRADE_SEND_EMAIL', 0);

    $this->putJson('/api/v2/system/updates/settings', ['send_email' => 'maybe'], $headers)->assertStatus(422);
});

it('names the failed step of the update settings and the steps applied before it', function () {
    $this->fake->on('v-change-sys-config-value')->returnsExit(ExitCode::Invalid, 'Error: invalid value');

    $this->putJson('/api/v2/system/updates/settings', ['auto_update' => false, 'send_email' => false], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertStatus(422)
        ->assertJsonPath('error.step.command', 'v-change-sys-config-value')
        ->assertJsonPath('error.applied.0.command', 'v-delete-cron-arcpanel-autoupdate');
});

it('plans the update writes in a dry run without running them', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/updates?dry_run=1', ['package' => 'arcpanel'], $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0', ['label' => 'Update arcpanel', 'command' => 'v-start-sys-task', 'args' => ['arcpanel-update', 'arcpanel']]);
    $this->putJson('/api/v2/system/updates/settings?dry_run=1', ['auto_update' => false], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-cron-arcpanel-autoupdate');
    $this->postJson('/api/v2/system/packages/go?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Install Go', 'command' => 'v-start-sys-task', 'args' => ['package', 'go', 'add']]);
    $this->deleteJson('/api/v2/system/packages/python?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['package', 'python', 'delete']);
    $this->fake->assertNotRan('v-start-sys-task');
    $this->fake->assertNotRan('v-delete-cron-arcpanel-autoupdate');
});

it('refuses the writes to a v2-read key before any command, and reads with it', function () {
    $headers = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);

    $this->getJson('/api/v2/system/updates', $headers)->assertOk();
    $this->getJson('/api/v2/system/packages', $headers)->assertOk();
    $this->getJson('/api/v2/system/graphs', $headers)->assertOk();
    $this->getJson('/api/v2/system/graphs/la?period=weekly', $headers)->assertOk();

    $this->postJson('/api/v2/system/updates', [], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-start-sys-task']);
    $this->postJson('/api/v2/system/packages/go', [], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-start-sys-task']);
    $this->deleteJson('/api/v2/system/packages/python', [], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/updates/settings', ['auto_update' => false, 'send_email' => false], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-delete-cron-arcpanel-autoupdate', 'v-change-sys-config-value']);
    $this->fake->assertNotRan('v-start-sys-task');
    $this->fake->assertNotRan('v-delete-cron-arcpanel-autoupdate');
    $this->fake->assertNotRan('v-change-sys-config-value');
});

// System packages

it('lists the system package catalog with state, version and users', function () {
    $this->fake->on('v-list-sys-packages')->returnsJson([[
        'SLUG' => 'python', 'NAME' => 'Python 3', 'SUMMARY' => 'Python 3 summary', 'CATEGORY' => 'runtime', 'PROVIDES' => ['python'],
        'PACKAGES' => ['python3', 'pipx'], 'AVAILABLE' => 'yes', 'STATUS' => 'installed', 'SOURCE' => 'arcpanel', 'VERSION' => '3.12.3',
        'UPGRADABLE' => 'yes', 'DOMAINS' => ['bob/app.example'], 'USED_BY' => 1,
    ], [
        'SLUG' => 'go', 'NAME' => 'Go', 'SUMMARY' => 'Go summary', 'CATEGORY' => 'runtime', 'PROVIDES' => ['go'], 'PACKAGES' => [],
        'AVAILABLE' => 'no', 'STATUS' => 'missing', 'SOURCE' => '-', 'VERSION' => '', 'UPGRADABLE' => 'no', 'DOMAINS' => [], 'USED_BY' => 0,
    ]]);

    $this->getJson('/api/v2/system/packages?limit=1', apiAs($this->fake, 'arcadmin', 'admin', area: 'server'))->assertOk()
        ->assertJsonPath('data.0', [
            'slug' => 'python', 'name' => 'Python 3', 'summary' => 'Python 3 summary', 'category' => 'runtime', 'provides' => ['python'],
            'packages' => ['python3', 'pipx'], 'available' => true, 'status' => 'installed', 'source' => 'arcpanel', 'version' => '3.12.3',
            'upgradable' => true, 'domains' => ['bob/app.example'], 'used_by' => 1,
        ])
        ->assertJsonPath('meta', ['total' => 2, 'limit' => 1, 'offset' => 0]);
    $this->getJson('/api/v2/system/packages?offset=1&limit=1', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.0.source', null)->assertJsonPath('data.0.version', null)->assertJsonPath('data.0.available', false);
});

it('installs and removes system packages as the system task', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/packages/go', [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/sys:'.UPDATE_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.id', 'sys:'.UPDATE_TASK)->assertJsonPath('data.kind', 'package')->assertJsonPath('data.args', 'go add');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['package', 'go', 'add']);
    $this->deleteJson('/api/v2/system/packages/python', [], $headers)->assertStatus(202)->assertJsonPath('data.args', 'python delete');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['package', 'python', 'delete']);

    $this->postJson('/api/v2/system/packages/nope', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/system/packages/nope', [], $headers)->assertNotFound();
    $this->fake->assertRan('v-start-sys-task', null, 2);
});

it('answers the refusals of the command', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::Exists, 'Error: system package python is already installed');
    $this->postJson('/api/v2/system/packages/python', [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::Forbidden, 'Error: system package python is needed by web domains: bob/app.example');
    $this->deleteJson('/api/v2/system/packages/python', [], $headers)->assertForbidden()
        ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'needed by web domains: bob/app.example'));
});

// Graphs (D65)

it('lists the server graphs', function () {
    $this->getJson('/api/v2/system/graphs', apiAs($this->fake, 'arcadmin', 'admin', area: 'server'))->assertOk()
        ->assertJsonPath('data', [
            ['id' => 'la', 'title' => 'Load Average', 'type' => 'la'],
            ['id' => 'mem', 'title' => 'Memory Usage', 'type' => 'mem'],
            ['id' => 'net_eth0', 'title' => 'Bandwidth Usage eth0', 'type' => 'net'],
        ])
        ->assertJsonPath('meta.total', 3);
});

it('answers the data points of a graph with its unit, as JSON', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/graphs/la', $headers)->assertOk()
        ->assertExactJson(['data' => [
            'id' => 'la', 'title' => 'Load Average', 'period' => 'daily', 'unit' => 'Points',
            'start' => 1791100800, 'end' => 1791101400, 'step' => 300, 'series' => ['Load', 'Processes'],
            'points' => [['time' => 1791100800, 'values' => [0.25, 112.0]], ['time' => 1791101100, 'values' => [null, null]]],
        ]]);
    $this->fake->assertRan('v-export-rrd', fn ($a) => $a === ['la', 'daily', 'json']);

    $this->getJson('/api/v2/system/graphs/net_eth0?period=triennially', $headers)->assertOk()
        ->assertJsonPath('data.unit', 'KBytes')->assertJsonPath('data.period', 'triennially');
    $this->fake->assertRan('v-export-rrd', fn ($a) => $a === ['net_eth0', 'triennially', 'json']);

    $this->getJson('/api/v2/system/graphs/la?period=hourly', $headers)->assertStatus(422)->assertJsonPath('error.code', 'E_VALIDATION');
    $this->getJson('/api/v2/system/graphs/mysql_localhost', $headers)->assertNotFound();
    $this->fake->assertRan('v-export-rrd', null, 2);

    $this->fake->on('v-export-rrd')->returnsExit(ExitCode::Args, 'Does not exist');
    $this->getJson('/api/v2/system/graphs/mem', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
});
