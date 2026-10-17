<?php

use App\Arc\ArcCommandFake;

// REST API v2, cross-user lists (E43 M3, B2;
// docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §5).
// The fake v-list-sys-<kind> commands answer from the per-user fixtures:
// every user without an owner, the reseller and its customers with one,
// and only bob as changed when UPDATED_SINCE is given.

const SYS_AS_OF = 1792231200;

/** The users each OWNER argument scopes, as v-list-users and tenancy.conf would. */
function sysListScopes(): array
{
    return [
        '' => ['arcadmin', 'bob', 'carol', 'dave', 'hostco', 'resb'],
        'hostco' => ['bob', 'hostco'],
        'resb' => ['carol', 'dave', 'resb'],
    ];
}

/** The node app records of bob: the keys of nodeapps.conf, without pm2's live state. */
function sysNodeAppRecords(): array
{
    $keys = ['DOMAIN', 'NAME', 'START', 'CWD', 'NODE_VERSION', 'PORT', 'INSTANCES', 'MAX_MEMORY', 'TEMPLATE', 'PREV_TPL', 'STATE', 'SUSPENDED', 'TIME', 'DATE'];
    $records = [];
    foreach (['shop.example', 'api.example'] as $domain) {
        $records[$domain] = array_intersect_key(nodeApp($domain), array_flip($keys));
    }

    return $records;
}

function fakeSysLists(ArcCommandFake $fake): void
{
    $fixture = fn (string $command) => function (string $user) use ($command): array {
        $file = base_path("tests/Fixtures/commands/{$command}/{$user}.json");

        return is_file($file) ? commandFixture("{$command}/{$user}.json") : [];
    };
    $kinds = [
        'v-list-sys-web-domains' => $fixture('v-list-web-domains'),
        'v-list-sys-databases' => $fixture('v-list-databases'),
        'v-list-sys-cron-jobs' => $fixture('v-list-cron-jobs'),
        'v-list-sys-backups' => $fixture('v-list-user-backups'),
        'v-list-sys-node-apps' => fn (string $user): array => $user === 'bob' ? sysNodeAppRecords() : [],
    ];
    foreach ($kinds as $command => $objects) {
        $fake->on($command)->using(function (array $args) use ($objects) {
            $users = sysListScopes()[$args[0]] ?? [$args[0]];
            $changed = $args[1] === '' ? $users : array_values(array_intersect($users, ['bob']));
            $map = [];
            foreach ($changed as $user) {
                $map[$user] = (object) $objects($user);
            }

            return [0, (string) json_encode(['AS_OF' => (string) SYS_AS_OF, 'USERS' => $users, 'CHANGED_USERS' => $changed, 'OBJECTS' => (object) $map])];
        });
    }
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    fakeDatabases($this->fake);
    fakeCronJobs($this->fake);
    fakeBackupData($this->fake);
    $this->fake->on('v-list-node-apps')->using(fn (array $args) => [0, (string) json_encode(nodeAppList($args[0], $args[0] === 'bob'
        ? [nodeApp('shop.example'), nodeApp('api.example')] : []))]);
    fakeSysLists($this->fake);
});

/** path => [command, per-user path, the name field of an item] */
dataset('cross-user lists', [
    'web domains' => ['/web-domains', 'v-list-sys-web-domains', 'web-domains', 'domain'],
    'databases' => ['/databases', 'v-list-sys-databases', 'databases', 'name'],
    'cron jobs' => ['/cron-jobs', 'v-list-sys-cron-jobs', 'cron-jobs', 'id'],
    'node apps' => ['/node-apps', 'v-list-sys-node-apps', 'node-apps', 'domain'],
    'backups' => ['/backups', 'v-list-sys-backups', 'backups', 'name'],
]);

it('lists every user for an administrator and the tree for a reseller, and refuses a user key', function (string $path, string $command) {
    $all = $this->getJson("/api/v2{$path}", apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.users', sysListScopes()[''])
        ->assertJsonPath('meta.changed_users', sysListScopes()['']);
    $this->fake->assertRan($command, fn (array $a) => $a === ['', '', 'json']);
    expect($all->json('meta.total'))->toBe(count($all->json('data')));

    $this->getJson("/api/v2{$path}", apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.users', ['bob', 'hostco']);
    $this->fake->assertRan($command, fn (array $a) => $a === ['hostco', '', 'json']);
    $reseller = $this->getJson("/api/v2{$path}", apiAs($this->fake, 'resb', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.users', ['carol', 'dave', 'resb']);
    expect(collect($reseller->json('data'))->pluck('user')->unique()->diff(['carol', 'dave', 'resb'])->all())->toBe([]);
})->with('cross-user lists');

it('refuses a user key before any listing runs', function (string $path, string $command) {
    $this->getJson("/api/v2{$path}", apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN');
    $this->fake->assertNotRan($command);
})->with('cross-user lists');

it('answers the items of the per-user list with their user, sorted by user, then name', function (string $path, string $command, string $perUser, string $name) {
    $all = $this->getJson("/api/v2{$path}", apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()->json('data');
    expect($all)->not->toBeEmpty();
    $order = array_map(fn (array $item) => [$item['user'], $item[$name]], $all);
    $sorted = $order;
    usort($sorted, fn (array $a, array $b) => [$a[0], is_int($a[1]) ? $a[1] : (string) $a[1]] <=> [$b[0], is_int($b[1]) ? $b[1] : (string) $b[1]]);
    expect($order)->toBe($sorted);

    foreach (['bob', 'carol'] as $user) {
        $mine = $this->getJson("/api/v2/users/{$user}/{$perUser}", apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()->json('data');
        $theirs = array_values(array_filter($all, fn (array $item) => $item['user'] === $user));
        $byName = fn (array $items) => collect($items)->sortBy($name)->values()->map(fn (array $item) => $path === '/node-apps'
            ? array_intersect_key($item, array_flip(['user', 'domain', 'name', 'start', 'cwd', 'node_version', 'port', 'instances', 'max_memory_mb', 'template', 'state', 'url', 'ssl', 'suspended', 'created_at']))
            : $item)->all();
        expect($byName($theirs))->toBe($byName($mine), "{$path} of {$user}");
    }
})->with('cross-user lists');

it('pages the list and keeps as_of, users and changed_users in meta', function () {
    $this->getJson('/api/v2/web-domains?limit=1&offset=1', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.limit', 1)
        ->assertJsonPath('meta.offset', 1)
        ->assertJsonPath('meta.as_of', gmdate('Y-m-d\TH:i:s\Z', SYS_AS_OF))
        ->assertJsonPath('meta.users', sysListScopes()[''])
        ->assertJsonPath('meta.changed_users', sysListScopes()['']);
    $this->getJson('/api/v2/cron-jobs', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.timezone', 'UTC');
    $this->getJson('/api/v2/web-domains?limit=0', apiAs($this->fake, 'arcadmin', 'admin'))->assertUnprocessable();
});

it('passes updated_since to the command as a Unix time and answers only the changed users', function () {
    $this->getJson('/api/v2/databases?updated_since=2026-10-17T10:00:00%2B02:00', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.users', ['bob', 'hostco'])
        ->assertJsonPath('meta.changed_users', ['bob']);
    $this->fake->assertRan('v-list-sys-databases', fn (array $a) => $a === ['hostco', (string) strtotime('2026-10-17T08:00:00Z'), 'json']);
    $this->getJson('/api/v2/databases?updated_since=2026-10-17T08:00:00', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-list-sys-databases', fn (array $a) => $a === ['', (string) strtotime('2026-10-17T08:00:00Z'), 'json']);
});

it('refuses an updated_since that is not an ISO 8601 time', function (string $value) {
    $this->getJson('/api/v2/databases?updated_since='.rawurlencode($value), apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['updated_since']]]);
    $this->fake->assertNotRan('v-list-sys-databases');
})->with(['next friday', '17/10/2026', '1792231200', '2026-13-40T10:00:00Z']);

it('narrows the list to one user of the scope and answers 404 outside it', function () {
    $this->getJson('/api/v2/web-domains?user=bob', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.users', ['bob']);
    $this->fake->assertRan('v-list-sys-web-domains', fn (array $a) => $a === ['bob', '', 'json']);

    // A reseller's own name lists the reseller only, not its customers
    $own = $this->getJson('/api/v2/web-domains?user=hostco', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.users', ['hostco'])
        ->assertJsonPath('meta.changed_users', ['hostco']);
    expect(collect($own->json('data'))->pluck('user')->unique()->values()->all())->toBeIn([[], ['hostco']]);

    $this->getJson('/api/v2/web-domains?user=carol', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertNotFound()
        ->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->getJson('/api/v2/web-domains?user=ghost', apiAs($this->fake, 'arcadmin', 'admin'))->assertNotFound();
    $this->fake->assertRan('v-list-sys-web-domains', fn (array $a) => in_array($a[0], ['carol', 'ghost'], true), times: 0);
});

it('answers node apps from their records, with the live state of pm2 null', function () {
    $apps = $this->getJson('/api/v2/node-apps', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()->json('data');
    expect($apps)->toHaveCount(2);
    foreach ($apps as $app) {
        expect($app)->toMatchArray([
            'user' => 'bob', 'state' => 'running', 'runtime' => null, 'status' => null, 'online' => null, 'reason' => null,
            'pid' => null, 'cpu' => null, 'memory_mb' => null, 'restarts' => null, 'uptime_seconds' => null,
        ]);
    }
    expect(array_column($apps, 'domain'))->toBe(['api.example', 'shop.example']);
    $this->getJson('/api/v2/users/bob/node-apps', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.0.status', 'online')
        ->assertJsonPath('data.0.user', 'bob');
});

it('lists with a key that holds only the area set, v2-read or v2-observe, and refuses another area', function (string $path, string $command) {
    $area = ['/web-domains' => 'web', '/databases' => 'db', '/cron-jobs' => 'cron', '/node-apps' => 'apps', '/backups' => 'backup'][$path];
    $this->getJson("/api/v2{$path}", apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertOk();
    $this->getJson("/api/v2{$path}", apiKey($this->fake, 'hostco', 'reseller', ['v2-read']))->assertOk();
    $this->getJson("/api/v2{$path}", apiKey($this->fake, 'hostco', 'reseller', ['v2-observe']))->assertOk();
    $this->getJson("/api/v2{$path}", apiAs($this->fake, 'arcadmin', 'admin', area: 'mail'))->assertForbidden();
})->with('cross-user lists');
