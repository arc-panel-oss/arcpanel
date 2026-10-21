<?php

use App\Arc\ExitCode;
use App\Services\Keys\AccessKeySets;

// REST API v2: the v2-observe set and the reveal rule (E43 M2, B3), and the
// per-key address list and expiry (B6);
// docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §6, §9.

const RESTRICTED_KEY = 'RestrictKey012345678';
const RESTRICTED_SECRET = 'restricted-secret-0123456789abcdefghijkl';

/** The commands that v2-observe leaves out of v2-read (§6). */
function observeExclusions(): array
{
    return [
        'v-open-fs-file', 'v-read-fs-file', 'v-add-fs-download', 'v-search-fs-object', 'v-list-fs-directory-json', 'v-get-fs-file-info', 'v-list-fs-disk-usage',
        'v-list-user-secret-store', 'v-list-user-secret',
        'v-list-user-files-restic',
    ];
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->fake->on('v-list-apis')->returnsJson([
        'v2-accounts' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-observe' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-read' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-write' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
    ]);
    $this->fake->on('v-add-access-key')->using(fn (array $args) => [0, (string) json_encode([
        'ACCESS_KEY_ID' => RESTRICTED_KEY, 'SECRET_ACCESS_KEY' => RESTRICTED_SECRET, 'USER' => $args[0],
        'PERMISSIONS' => explode(',', $args[1]), 'COMMENT' => $args[2], 'TIME' => '12:00:00', 'DATE' => '2026-10-17',
        'ALLOWED_IPS' => count($args) === 6 && $args[3] !== '' ? explode(',', $args[3]) : [],
        'EXPIRES' => count($args) === 6 ? $args[4] : '',
    ])]);
});

// B3: the observe set

it('ships v2-observe as v2-read without file contents, the secret store and restic file listings', function () {
    $observe = apiSetCommands('v2-observe');
    $read = apiSetCommands('v2-read');
    $expected = array_values(array_diff($read, observeExclusions()));
    sort($observe);
    sort($expected);

    expect($observe)->toBe($expected)
        ->and(array_values(array_intersect($observe, observeExclusions())))->toBe([])
        ->and((string) file_get_contents(base_path('../install/common/api/v2-observe')))->toContain("ROLE='user'")->toContain("API_VERSION='2'");
});

it('describes v2-observe and offers it with the sets of full access', function () {
    expect(AccessKeySets::description('v2-observe'))->toBe('REST API v2 only: read-only monitoring without file contents or secret values.')
        ->and(AccessKeySets::summary('v2-observe'))->toBe('Read-only monitoring without file contents or secret values.')
        ->and(AccessKeySets::groups(['v2-accounts', 'v2-observe', 'v2-read'])[0]['sets'])->toBe(['v2-read', 'v2-observe']);

    $this->getJson('/api/v2/access-key-sets?user=bob', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonFragment(['name' => 'v2-observe', 'api' => 2, 'description' => 'REST API v2 only: read-only monitoring without file contents or secret values.']);
});

it('lets an observe key read and refuses file contents, secrets and restic file listings before any command', function () {
    fakeWebDomains($this->fake);
    $headers = apiKey($this->fake, 'bob', 'user', ['v2-observe']);

    $this->getJson('/api/v2/users/bob/web-domains', $headers)->assertOk();
    $this->getJson('/api/v2/users/bob/files?path=web', $headers)->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN');
    $this->getJson('/api/v2/users/bob/files/content?path=web/index.html', $headers)->assertForbidden();
    $this->postJson('/api/v2/users/bob/files/download', ['paths' => ['web/a.txt']], $headers)->assertForbidden();
    $this->getJson('/api/v2/users/bob/secrets', $headers)->assertForbidden();
    foreach (observeExclusions() as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('refuses reveal=1 to a key whose only set is v2-observe and masks values without it', function (string $path) {
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes', 'NODE_APPS' => '3', 'APP_PROCESSES' => 'yes']]);
    $this->fake->on('v-list-node-app', ['bob', 'api.bob.example', 'json'])->returnsJson(['api.bob.example' => nodeApp('api.bob.example')]);
    $this->fake->on('v-list-node-app-env', ['bob', 'api.bob.example', 'json'])->returnsJson(['VARS' => [['KEY' => 'API_KEY', 'VALUE' => 'hidden-value']]]);
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(appProcessRow('shop.bob.example'));
    $this->fake->on('v-list-app-process-env', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(['VARS' => [['KEY' => 'API_KEY', 'VALUE' => 'hidden-value']]]);
    $headers = apiKey($this->fake, 'bob', 'user', ['v2-observe']);

    $response = $this->getJson($path.'?reveal=1', $headers)->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN');
    expect((string) $response->getContent())->not->toContain('hidden-value');
    $response = $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.0', ['name' => 'API_KEY', 'set' => true]);
    expect((string) $response->getContent())->not->toContain('hidden-value');
})->with([
    'Node.js app' => ['/api/v2/users/bob/node-apps/api.bob.example/env'],
    'app process' => ['/api/v2/users/bob/app-processes/shop.bob.example/web/env'],
]);

// B6: restrictions of a key

it('creates a key limited to addresses and with an expiry', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $expires = now()->addDays(30)->startOfSecond();

    $this->postJson('/api/v2/users/bob/access-keys', [
        'permissions' => ['v2-observe'], 'comment' => 'fleet',
        'allowed_ips' => ['203.0.113.7', '2001:db8::/48'], 'expires_at' => $expires->toIso8601String(),
    ], $headers)->assertCreated()
        ->assertJsonPath('data.allowed_ips', ['203.0.113.7', '2001:db8::/48'])
        ->assertJsonPath('data.expires_at', $expires->utc()->format('Y-m-d\TH:i:s\Z'));
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === ['bob', 'v2-observe', 'fleet', '203.0.113.7,2001:db8::/48', (string) $expires->getTimestamp(), 'json']);
});

it('keeps the old arguments of v-add-access-key for a key without restrictions', function () {
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-read'], 'allowed_ips' => [], 'expires_at' => null], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertCreated()
        ->assertJsonPath('data.allowed_ips', [])
        ->assertJsonPath('data.expires_at', null);
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === ['bob', 'v2-read', '', 'json']);
});

it('refuses restrictions outside the rules before any command', function (array $body) {
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-read'], ...$body], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertUnprocessable()->assertJsonPath('error.code', 'E_VALIDATION');
    $this->fake->assertNotRan('v-add-access-key');
})->with([
    'not an address' => [['allowed_ips' => ['example.com']]],
    'a bad network' => [['allowed_ips' => ['203.0.113.0/33']]],
    'a bad IPv6 network' => [['allowed_ips' => ['2001:db8::/129']]],
    'more than 20' => [['allowed_ips' => array_map(fn (int $i) => '198.51.100.'.$i, range(1, 21))]],
    'twice the same' => [['allowed_ips' => ['198.51.100.1', '198.51.100.1']]],
    'not a list' => [['allowed_ips' => '198.51.100.1']],
    'in less than an hour' => [['expires_at' => now()->addMinutes(30)->toIso8601String()]],
    'in the past' => [['expires_at' => now()->subDay()->toIso8601String()]],
    'more than three years ahead' => [['expires_at' => now()->addYears(3)->addDay()->toIso8601String()]],
    'not ISO 8601' => [['expires_at' => 'next friday']],
]);

it('lets a restricted key create keys only inside its own address list and expiry (E39 W5 N27)', function () {
    $until = now()->toImmutable()->addDays(10)->startOfSecond();
    $this->fake->on('v-check-access-key-permissions')->using(fn (array $args) => [0, (string) json_encode([
        'ACCESS_KEY_ID' => API_KEY_ID, 'USER' => 'arcadmin', 'ROLE' => 'admin', 'PERMISSIONS' => [], 'COMMANDS' => ['*'],
        'ALLOWED_IPS' => ['203.0.113.0/24', '2001:db8::/32'], 'EXPIRES' => (string) $until->getTimestamp(),
    ])]);
    $headers = ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET, 'Accept' => 'application/json'];
    $post = fn (array $body) => $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-read'], ...$body], $headers);
    $inside = ['allowed_ips' => ['203.0.113.7', '203.0.113.128/25', '2001:db8:1::/48'], 'expires_at' => $until->subDay()->toIso8601String()];

    $post([])->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN');
    $post(['allowed_ips' => $inside['allowed_ips']])->assertForbidden();
    $post(['expires_at' => $inside['expires_at']])->assertForbidden();
    $post([...$inside, 'allowed_ips' => ['198.51.100.7']])->assertForbidden();
    $post([...$inside, 'allowed_ips' => ['203.0.0.0/16']])->assertForbidden();
    $post([...$inside, 'allowed_ips' => ['2001:db8::/31']])->assertForbidden();
    $post([...$inside, 'expires_at' => $until->addDay()->toIso8601String()])->assertForbidden();
    $this->postJson('/api/v2/users/bob/access-keys?dry_run=1', ['permissions' => ['v2-read']], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-add-access-key');

    $post($inside)->assertCreated();
    $post([...$inside, 'expires_at' => $until->toIso8601String()])->assertCreated();
    $this->fake->assertRan('v-add-access-key', null, 2);
});

it('shows the restrictions in the dry run and refuses a v2-read key before any command', function () {
    $expires = now()->addDays(2)->startOfSecond();

    $this->postJson('/api/v2/users/bob/access-keys?dry_run=1', ['permissions' => ['v2-read'], 'allowed_ips' => ['203.0.113.7'], 'expires_at' => $expires->toIso8601String()], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertOk()->assertJsonPath('data.steps.0.args', ['bob', 'v2-read', '', '203.0.113.7', (string) $expires->getTimestamp(), 'json']);
    $this->postJson('/api/v2/users/bob/access-keys', ['permissions' => ['v2-read'], 'allowed_ips' => ['203.0.113.7']], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden();
    $this->fake->assertNotRan('v-add-access-key');
});

it('lists and reads keys with their restrictions', function () {
    $row = ['ACCESS_KEY_ID' => RESTRICTED_KEY, 'USER' => 'bob', 'PERMISSIONS' => ['v2-observe'], 'COMMENT' => 'fleet', 'TIME' => '12:00:00', 'DATE' => '2026-10-17',
        'ALLOWED_IPS' => ['203.0.113.7'], 'EXPIRES' => '1830297600'];
    $this->fake->on('v-list-access-keys')->returnsJson([RESTRICTED_KEY => $row]);
    $this->fake->on('v-list-access-key')->returnsJson($row);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/users/bob/access-keys', $headers)->assertOk()
        ->assertJsonPath('data.0.allowed_ips', ['203.0.113.7'])
        ->assertJsonPath('data.0.expires_at', '2028-01-01T00:00:00Z');
    $this->getJson('/api/v2/users/bob/access-keys/'.RESTRICTED_KEY, $headers)->assertOk()
        ->assertJsonPath('data.expires_at', '2028-01-01T00:00:00Z');
});

it('answers 401 to an expired key and 403 E_API_DISABLED outside its address list', function () {
    $this->fake->on('v-check-access-key-permissions')->returnsExit(ExitCode::Password->value, 'Error: Key '.API_KEY_ID.' has expired');
    $headers = ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET, 'Accept' => 'application/json'];

    $this->getJson('/api/v2/me', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'E_UNAUTHENTICATED');

    $this->fake->on('v-check-access-key-permissions')->returnsExit(ExitCode::Disabled->value, 'Error: Key '.API_KEY_ID.' does not accept requests from 127.0.0.1');
    $this->getJson('/api/v2/me', $headers)->assertForbidden()
        ->assertJsonPath('error.code', 'E_API_DISABLED')
        ->assertJsonPath('error.message', 'The access key does not accept requests from this address.');
});

it('counts a request from outside the key\'s address list as no failed authentication', function () {
    $this->fake->on('v-check-access-key-permissions')->returnsExit(ExitCode::Disabled->value, 'Error: refused');
    $headers = ['Authorization' => 'Bearer '.API_KEY_ID.':'.API_SECRET, 'Accept' => 'application/json'];

    for ($i = 0; $i < 11; $i++) {
        $this->getJson('/api/v2/me', $headers)->assertForbidden();
    }
});
