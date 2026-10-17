<?php

use App\Arc\ExitCode;

// REST API v2 service log excerpts (E43 M5, B5):
// docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §8.

const SERVICE_LOG_LINES = [
    '2026-10-17T10:00:00+0000 srv1 systemd[1]: Reloading nginx.service - nginx - high performance web server...',
    '2026-10-17T10:00:01+0000 srv1 systemd[1]: Reloaded nginx.service - nginx - high performance web server.',
];

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $service = fn (string $system) => ['SYSTEM' => $system, 'STATE' => 'running', 'CPU' => '0', 'MEM' => '10', 'RTIME' => '45'];
    $this->fake->on('v-list-sys-services')->returnsJson([
        'nginx' => $service('reverse proxy'),
        'web-terminal' => $service('web terminal backend'),
    ]);
    $this->fake->on('v-list-sys-service-log')->using(fn (array $args) => [0, (string) json_encode([
        'SERVICE' => $args[0], 'UNIT' => $args[0] === 'web-terminal' ? 'arcpanel-web-terminal' : $args[0], 'LINES' => SERVICE_LOG_LINES,
    ])]);
});

it('answers the last lines of a service for administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/services/nginx/logs', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertExactJson(['data' => ['service' => 'nginx', 'lines' => SERVICE_LOG_LINES]]);
        $this->fake->assertRan('v-list-sys-service-log', fn (array $a) => $a === ['nginx', '100', 'json']);
    } else {
        $response->assertJsonPath('error.code', 'E_FORBIDDEN');
        $this->fake->assertNotRan('v-list-sys-service-log');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('passes the number of lines, 1 to 500', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/services/web-terminal/logs?lines=500', $headers)->assertOk()
        ->assertJsonPath('data.service', 'web-terminal');
    $this->fake->assertRan('v-list-sys-service-log', fn (array $a) => $a === ['web-terminal', '500', 'json']);
    foreach (['0', '501', 'many', '-1'] as $lines) {
        $this->getJson('/api/v2/system/services/nginx/logs?lines='.$lines, $headers)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['lines']]]);
    }
    $this->fake->assertRan('v-list-sys-service-log', times: 1);
});

it('answers 404 for a service the server does not list', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/services/postfix/logs', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->fake->assertNotRan('v-list-sys-service-log');
    // A service that left the list between both calls
    $this->fake->on('v-list-sys-service-log')->returnsExit(ExitCode::NotExist, 'Error: service nginx is not listed');
    $this->getJson('/api/v2/system/services/nginx/logs', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
});

it('answers an administrator\'s v2-observe key and refuses a key without the server area', function () {
    $this->getJson('/api/v2/system/services/nginx/logs', apiKey($this->fake, 'arcadmin', 'admin', ['v2-observe']))->assertOk();
    $this->getJson('/api/v2/system/services/nginx/logs', apiKey($this->fake, 'arcadmin', 'admin', ['v2-web']))->assertForbidden();
});

it('keeps the logs for the root administrator with POLICY_SYSTEM_HIDE_SERVICES', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_SYSTEM_HIDE_SERVICES' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);

    $this->getJson('/api/v2/system/services/nginx/logs', apiAs($this->fake, 'admin2', 'admin'))->assertForbidden();
    $this->fake->assertNotRan('v-list-sys-service-log');
    $this->getJson('/api/v2/system/services/nginx/logs', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
});
