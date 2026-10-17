<?php

use App\Api\KeyPermissions;

// REST API v2: the server identifier and the calling key's restrictions in
// GET /me (E43 M1, B1; docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §4).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeServerUsers($this->fake);
});

/**
 * Let the API key carry restrictions, as `v-check-access-key-permissions`
 * prints them.
 *
 * @param  list<string>  $sets
 * @return array<string, string>
 */
function restrictedKey(object $fake, string $user, string $role, array $sets, string $expires, array $ips): array
{
    $commands = $sets === [] ? ['*'] : array_values(array_unique(array_merge(...array_map(apiSetCommands(...), $sets))));
    $fake->on('v-check-access-key-permissions')->using(fn (array $args) => [0, (string) json_encode([
        'ACCESS_KEY_ID' => API_KEY_ID, 'USER' => $user, 'ROLE' => $role, 'PERMISSIONS' => $sets, 'COMMANDS' => $commands,
        'EXPIRES' => $expires, 'ALLOWED_IPS' => $ips,
    ])]);

    return ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET, 'Accept' => 'application/json'];
}

it('answers the server identifier to every key', function (string $user, string $role, array $sets) {
    $headers = apiKey($this->fake, $user, $role, $sets);

    $this->getJson('/api/v2/me', $headers)->assertOk()
        ->assertJsonPath('data.server_id', '7d3f5a2e-9c41-4b8e-a6d2-0f1e2d3c4b5a')
        ->assertJsonPath('data.key_expires_at', null)
        ->assertJsonPath('data.key_allowed_ips', []);
})->with([
    'administrator without sets' => ['arcadmin', 'admin', []],
    'reseller, v2-read' => ['hostco', 'reseller', ['v2-read']],
    'user, one area' => ['bob', 'user', ['v2-web']],
]);

it('reads the identifier from the configuration, not from the panel', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'SERVER_ID' => '0b6f9c1e-2d3a-4e5f-8a9b-1c2d3e4f5a6b']);

    $this->getJson('/api/v2/me', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.server_id', '0b6f9c1e-2d3a-4e5f-8a9b-1c2d3e4f5a6b');
    $this->fake->assertNotRan('v-update-sys-server-id');
});

it('answers the expiry and the address list of the calling key', function () {
    $headers = restrictedKey($this->fake, 'bob', 'user', ['v2-read'], '1830297600', ['203.0.113.7', '2001:db8::/48']);

    $this->getJson('/api/v2/me', $headers)->assertOk()
        ->assertJsonPath('data.key_expires_at', '2028-01-01T00:00:00Z')
        ->assertJsonPath('data.key_allowed_ips', ['203.0.113.7', '2001:db8::/48']);
});

it('reads the restrictions of a key from v-check-access-key-permissions', function () {
    $key = KeyPermissions::fromCommand(['ACCESS_KEY_ID' => API_KEY_ID, 'USER' => 'bob', 'ROLE' => 'user', 'PERMISSIONS' => ['v2-read'],
        'COMMANDS' => ['v-list-user'], 'EXPIRES' => '1830297600', 'ALLOWED_IPS' => ['198.51.100.0/24', '']]);
    $plain = KeyPermissions::fromCommand(['ACCESS_KEY_ID' => API_KEY_ID, 'USER' => 'bob', 'ROLE' => 'user', 'PERMISSIONS' => ['v2-read'], 'COMMANDS' => ['v-list-user']]);

    expect($key->expires?->getTimestamp())->toBe(1830297600)
        ->and($key->allowedIps)->toBe(['198.51.100.0/24'])
        ->and($plain->expires)->toBeNull()
        ->and($plain->allowedIps)->toBe([]);
});

it('documents the new fields of GET /me', function () {
    $spec = json_decode((string) file_get_contents(base_path('../docs/api/v2/openapi.json')), true);
    $me = $spec['components']['schemas']['Me'] ?? [];

    expect($me['properties'])->toHaveKeys(['server_id', 'key_expires_at', 'key_allowed_ips'])
        ->and($me['required'])->toContain('server_id', 'key_expires_at', 'key_allowed_ips');
});
