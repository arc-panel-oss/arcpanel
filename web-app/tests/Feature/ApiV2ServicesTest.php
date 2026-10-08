<?php

use App\Arc\ExitCode;

// REST API v2, services of the server (E34 batch 6 spec §5.1, D61).
// Nothing here restarts anything: every command is answered by the fake.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => [
        'HOSTNAME' => 'srv1.example.net', 'OS' => 'Ubuntu', 'VERSION' => '24.04', 'ARCH' => 'x86_64',
        'ARCPANEL' => '2.0.0', 'RELEASE' => 'release', 'UPTIME' => '3000', 'LOADAVERAGE' => '0.28 / 0.10 / 0.07',
    ]]);
    $service = fn (string $system, string $state = 'running', string $cpu = '0', string $mem = '10', string $rtime = '45') => [
        'SYSTEM' => $system, 'STATE' => $state, 'CPU' => $cpu, 'MEM' => $mem, 'RTIME' => $rtime,
    ];
    $this->fake->on('v-list-sys-services')->returnsJson([
        'nginx' => $service('reverse proxy', 'running', '12', '46', '120'),
        'exim4' => $service('mail server', 'stopped'),
        'iptables' => $service('firewall'),
        'arcpanel' => $service('panel'),
    ]);
    foreach (['v-start-service', 'v-stop-service', 'v-restart-service', 'v-update-firewall', 'v-stop-firewall', 'v-restart-system'] as $command) {
        $this->fake->on($command)->returns();
    }
});

it('lists the services for administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/services', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0', ['name' => 'nginx', 'system' => 'reverse proxy', 'running' => true, 'cpu' => 1.2, 'memory_mb' => 46, 'uptime_minutes' => 120])
            ->assertJsonPath('data.1.running', false)
            ->assertJsonPath('meta', [
                'hostname' => 'srv1.example.net', 'os' => 'Ubuntu', 'os_version' => '24.04', 'arch' => 'x86_64',
                'arcpanel_version' => '2.0.0', 'release' => 'release', 'uptime_minutes' => 3000, 'load' => [0.28, 0.1, 0.07],
            ]);
    } else {
        $response->assertJsonPath('error.code', 'E_FORBIDDEN');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every services endpoint to non-administrators before any command', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->getJson('/api/v2/system/services/status/cpu', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/services/nginx/restart', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/services/nginx/stop', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/restart', ['confirm_hostname' => 'srv1.example.net'], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-list-sys-cpu-status');
    $this->fake->assertNotRan('v-restart-service');
    $this->fake->assertNotRan('v-stop-service');
    $this->fake->assertNotRan('v-restart-system');
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('answers a status report as plain text', function () {
    $this->fake->on('v-list-sys-memory-status')->returns("MiB Mem :  15921.5 total\n");
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/services/status/mem', $headers)->assertOk()
        ->assertExactJson(['data' => ['kind' => 'mem', 'text' => "MiB Mem :  15921.5 total\n"]]);
    $this->getJson('/api/v2/system/services/status/swap', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
});

it('starts, stops and restarts services with a key holding only v2-server', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/services/nginx/restart', [], $headers)->assertOk()->assertJsonPath('data.name', 'nginx');
    $this->fake->assertRan('v-restart-service', fn ($a) => $a === ['nginx', 'yes']);
    $this->postJson('/api/v2/system/services/exim4/start', [], $headers)->assertOk()->assertJsonPath('data.name', 'exim4');
    $this->fake->assertRan('v-start-service', fn ($a) => $a === ['exim4']);
    $this->postJson('/api/v2/system/services/nginx/stop', [], $headers)->assertOk();
    $this->fake->assertRan('v-stop-service', fn ($a) => $a === ['nginx']);

    // The firewall restarts with v-update-firewall and stops with v-stop-firewall, as on the page
    $this->postJson('/api/v2/system/services/iptables/restart', [], $headers)->assertOk();
    $this->fake->assertRan('v-update-firewall');
    $this->postJson('/api/v2/system/services/iptables/stop', [], $headers)->assertOk();
    $this->fake->assertRan('v-stop-firewall');

    $this->postJson('/api/v2/system/services/unknown/restart', [], $headers)->assertNotFound();
});

it('names the failed step of a service action', function () {
    $this->fake->on('v-restart-service')->returnsExit(ExitCode::Restart, 'Error: nginx restart failed');

    $this->postJson('/api/v2/system/services/nginx/restart', [], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertStatus(500)->assertJsonPath('error.code', 'E_RESTART')
        ->assertJsonPath('error.step.command', 'v-restart-service');
});

it('restarts the panel service but never stops it (D61)', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/services/arcpanel/stop', [], $headers)->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.message', 'Service arcpanel is required by the panel: it can be restarted, not stopped.');
    $this->postJson('/api/v2/system/services/arcpanel/stop?dry_run=1', [], $headers)->assertForbidden();
    // Also when the service list does not show it
    $this->fake->on('v-list-sys-services')->returnsJson(['nginx' => ['SYSTEM' => 'reverse proxy', 'STATE' => 'running', 'CPU' => '0', 'MEM' => '1', 'RTIME' => '1']]);
    $this->postJson('/api/v2/system/services/arcpanel/stop', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/services/arcpanel/restart', [], $headers)->assertNotFound();
    $this->fake->on('v-list-sys-services')->returnsJson(['arcpanel' => ['SYSTEM' => 'panel', 'STATE' => 'running', 'CPU' => '0', 'MEM' => '1', 'RTIME' => '1']]);
    $this->fake->assertNotRan('v-stop-service');

    $this->postJson('/api/v2/system/services/arcpanel/restart', [], $headers)->assertOk();
    $this->fake->assertRan('v-restart-service', fn ($a) => $a === ['arcpanel', 'yes']);
});

it('restarts the server only with the host name typed in (D61)', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/restart', ['confirm_hostname' => 'srv1'], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonPath('error.fields.confirm_hostname.0', 'Type the host name of the server to confirm.');
    $this->postJson('/api/v2/system/restart', [], $headers)->assertStatus(422)->assertJsonPath('error.fields.confirm_hostname.0', fn ($m) => is_string($m));
    $this->postJson('/api/v2/system/restart?dry_run=1', ['confirm_hostname' => 'SRV1.example.net'], $headers)->assertStatus(422);
    $this->fake->assertNotRan('v-restart-system');

    $this->postJson('/api/v2/system/restart?dry_run=1', ['confirm_hostname' => 'srv1.example.net'], $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0', ['label' => 'Restart the server', 'command' => 'v-restart-system', 'args' => ['yes']]);
    $this->fake->assertNotRan('v-restart-system');

    $this->postJson('/api/v2/system/restart', ['confirm_hostname' => 'srv1.example.net'], $headers)->assertNoContent();
    $this->fake->assertRan('v-restart-system', fn ($a) => $a === ['yes'], 1);
});

it('plans service actions without running them', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/services/nginx/stop?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Stop nginx', 'command' => 'v-stop-service', 'args' => ['nginx']]);
    $this->postJson('/api/v2/system/services/iptables/restart?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-update-firewall');
    $this->fake->assertNotRan('v-stop-service');
    $this->fake->assertNotRan('v-update-firewall');
});

it('refuses the changes to a v2-read key before any command, and reads with it', function () {
    $headers = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);

    $this->getJson('/api/v2/system/services', $headers)->assertOk();
    $this->postJson('/api/v2/system/services/nginx/restart', [], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-restart-service']);
    $this->postJson('/api/v2/system/services/nginx/start', [], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-start-service']);
    $this->postJson('/api/v2/system/restart', ['confirm_hostname' => 'srv1.example.net'], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-restart-system']);
    // The refusal comes before the host name check
    $this->postJson('/api/v2/system/restart', ['confirm_hostname' => 'nope'], $headers)->assertForbidden();
    foreach (['v-restart-service', 'v-start-service', 'v-restart-system'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('keeps the services for the root administrator with POLICY_SYSTEM_HIDE_SERVICES (D61)', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_SYSTEM_HIDE_SERVICES' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);

    $other = apiAs($this->fake, 'admin2', 'admin');
    $this->getJson('/api/v2/system/services', $other)->assertForbidden();
    $this->getJson('/api/v2/system/services/status/cpu', $other)->assertForbidden();
    $this->postJson('/api/v2/system/services/nginx/restart', [], $other)->assertForbidden();
    $this->postJson('/api/v2/system/restart', ['confirm_hostname' => 'srv1.example.net'], $other)->assertForbidden();
    $this->fake->assertNotRan('v-restart-service');
    $this->fake->assertNotRan('v-restart-system');

    $root = apiAs($this->fake, 'arcadmin', 'admin');
    $this->getJson('/api/v2/system/services', $root)->assertOk();
    $this->postJson('/api/v2/system/services/nginx/restart', [], $root)->assertOk();
});
