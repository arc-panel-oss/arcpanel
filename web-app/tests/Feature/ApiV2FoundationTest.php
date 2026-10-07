<?php

use App\Arc\ExitCode;

// REST API v2, foundation (E34 spec §4, D3, D8, D10, D12, D13, D16).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeServerUsers($this->fake);
});

it('describes the key of the request', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/me', $headers)->assertOk()
        ->assertExactJson(['data' => [
            'access_key_id' => API_KEY_ID,
            'user' => 'arcadmin',
            'role' => 'admin',
            'read_only' => false,
            'permissions' => [],
            'commands' => ['*'],
            'panel_version' => '2.0.0',
            'api_version' => '2',
        ]])
        ->assertHeader('ArcPanel-Version', '2.0.0')
        ->assertHeader('X-Request-Id');
    // The secret reaches the command in a file, the client address with it
    $this->fake->assertRan('v-check-access-key-permissions', fn (array $args) => $args === [API_KEY_ID, API_SECRET, '127.0.0.1', 'json']);
});

it('lists the sets and commands of a restricted key', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/me', $headers)->assertOk()
        ->assertJsonPath('data.user', 'bob')
        ->assertJsonPath('data.role', 'user')
        ->assertJsonPath('data.permissions', ['v2-read'])
        ->assertJsonPath('data.commands', apiSetCommands('v2-read'));
});

it('keeps the client request id and replaces an invalid one', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/me', [...$headers, 'X-Request-Id' => 'cli-42.a'])->assertHeader('X-Request-Id', 'cli-42.a');
    $id = $this->getJson('/api/v2/me', [...$headers, 'X-Request-Id' => "bad id\x01"])->headers->get('X-Request-Id');
    expect($id)->toMatch('/^[0-9a-f-]{36}$/');
});

it('is stateless: no session, no cookie', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $response = $this->getJson('/api/v2/me', $headers)->assertOk();
    expect($response->headers->getCookies())->toBe([]);
});

it('answers 401 without a key, with a malformed or a wrong key', function (array $headers) {
    apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/me', $headers)->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'E_UNAUTHENTICATED', 'message' => 'Authentication failed.']])
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertHeader('X-Request-Id');
})->with([
    'no header' => [[]],
    'basic' => [['Authorization' => 'Basic YWRtaW46eA==']],
    'no secret' => [['Authorization' => 'Bearer '.API_KEY_ID]],
    'wrong secret' => [['Authorization' => 'Bearer '.API_KEY_ID.':'.str_repeat('x', 40)]],
]);

it('blocks an address after ten failed authentications a minute', function () {
    apiAs($this->fake, 'arcadmin', 'admin');
    $wrong = ['Authorization' => 'Bearer '.API_KEY_ID.':'.str_repeat('x', 40)];

    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/api/v2/me', $wrong)->assertUnauthorized();
    }
    $this->getJson('/api/v2/me', apiAs($this->fake, 'arcadmin', 'admin'))->assertStatus(429)
        ->assertJsonPath('error.code', 'E_RATE_LIMIT')
        ->assertHeader('Retry-After');
    $this->fake->assertRan('v-check-access-key-permissions', times: 10);
});

it('answers 429 with Retry-After above 60 requests a minute', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v2/me', $headers)->assertOk();
    }
    $this->getJson('/api/v2/me', $headers)->assertStatus(429)
        ->assertJsonPath('error.code', 'E_RATE_LIMIT')
        ->assertHeader('Retry-After')
        ->assertHeader('X-Request-Id');
});

it('refuses a suspended owner and a user key without sets', function (int $exit, string $code) {
    $this->fake->on('v-check-access-key-permissions')->returnsExit($exit, 'Error: refused');

    $this->getJson('/api/v2/me', ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET])->assertForbidden()
        ->assertJsonPath('error.code', $code);
})->with([
    'suspended' => [ExitCode::Suspended->value, 'E_SUSPENDED'],
    'no sets' => [ExitCode::Forbidden->value, 'E_FORBIDDEN'],
]);

it('follows API_SYSTEM: off, administrator only, every key', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '0', 'API_ALLOWED_IP' => 'allow-all']);
    $this->getJson('/api/v2/me', apiAs($this->fake, 'arcadmin', 'admin'))->assertForbidden()->assertJsonPath('error.code', 'E_API_DISABLED');
    $this->fake->assertNotRan('v-check-access-key-permissions');

    withSysConfig($this->fake, ['API_SYSTEM' => '1', 'API_ALLOWED_IP' => 'allow-all']);
    $this->getJson('/api/v2/me', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->getJson('/api/v2/me', apiAs($this->fake, 'bob', 'user'))->assertForbidden()->assertJsonPath('error.code', 'E_API_DISABLED');

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all']);
    $this->getJson('/api/v2/me', apiAs($this->fake, 'bob', 'user'))->assertOk();
});

it('follows API_ALLOWED_IP', function (string $allowed, int $status) {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => $allowed]);

    $this->getJson('/api/v2/me', apiAs($this->fake, 'arcadmin', 'admin'))->assertStatus($status);
})->with([
    'allow-all' => ['allow-all', 200],
    'listed' => ['10.0.0.1,127.0.0.1', 200],
    'any address' => ['0.0.0.0', 200],
    'not listed' => ['10.0.0.1', 403],
    'empty' => ['', 403],
]);

it('answers unknown paths and methods with the error body', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/nothing-here', $headers)->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'E_NOTEXIST', 'message' => 'Not found.']])
        ->assertHeader('X-Request-Id');
    $this->postJson('/api/v2/me', [], $headers)->assertStatus(405)->assertJsonPath('error.code', 'E_METHOD_NOT_ALLOWED');
});
