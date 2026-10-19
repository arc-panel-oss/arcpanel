<?php

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\ErrorRenderer;
use App\Api\KeyPermissions;
use App\Api\KeyScopedArcCommand;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Secret;
use App\Auth\Role;
use App\Http\Middleware\Api\ApiEnabled;
use App\Http\Middleware\Api\AuthenticateAccessKey;
use App\Http\Resources\V2\DnsRecordResource;
use App\Http\Resources\V2\DnsZoneResource;
use App\Services\Dns\DnsRecordRecord;
use App\Services\Dns\DnsZoneRecord;
use App\Services\Keys\AccessKeySets;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// REST API v2 building blocks (E34 spec §4, D5, D10, D11).

function keyWith(array $commands): KeyPermissions
{
    return new KeyPermissions(API_KEY_ID, 'bob', Role::User, ['v2-write'], $commands);
}

it('reads the key from v-check-access-key-permissions', function () {
    $key = KeyPermissions::fromCommand(['ACCESS_KEY_ID' => API_KEY_ID, 'USER' => 'hostco', 'ROLE' => 'reseller', 'PERMISSIONS' => ['v2-read'], 'COMMANDS' => ['v-list-user', '']]);

    expect($key->user)->toBe('hostco')
        ->and($key->role)->toBe(Role::Reseller)
        ->and($key->commands)->toBe(['v-list-user'])
        ->and($key->allows('v-list-user'))->toBeTrue()
        ->and($key->allows('v-add-user'))->toBeFalse()
        ->and($key->missing(['v-list-user', 'v-add-user', 'v-add-user']))->toBe(['v-add-user'])
        ->and(keyWith(['*'])->allows('v-anything'))->toBeTrue();
});

it('parses the bearer credentials', function () {
    expect(AuthenticateAccessKey::credentials('Bearer '.API_KEY_ID.':'.API_SECRET))->toBe([API_KEY_ID, API_SECRET])
        ->and(AuthenticateAccessKey::credentials('bearer '.API_KEY_ID.':'.API_SECRET))->toBeNull()
        ->and(AuthenticateAccessKey::credentials('Bearer '.API_KEY_ID.':short'))->toBeNull()
        ->and(AuthenticateAccessKey::credentials('Bearer '.API_KEY_ID.':'.API_SECRET.' x'))->toBeNull();
});

it('applies API_ALLOWED_IP like API v1', function () {
    expect(ApiEnabled::ipAllowed('allow-all', '198.51.100.1'))->toBeTrue()
        ->and(ApiEnabled::ipAllowed('198.51.100.1, 10.0.0.1', '10.0.0.1'))->toBeTrue()
        ->and(ApiEnabled::ipAllowed('0.0.0.0', '10.0.0.1'))->toBeTrue()
        ->and(ApiEnabled::ipAllowed('198.51.100.1', '10.0.0.1'))->toBeFalse()
        ->and(ApiEnabled::ipAllowed('', ''))->toBeFalse();
});

it('refuses a command outside the sets before it runs', function () {
    $fake = fakeArc();
    $fake->on('v-list-user')->returnsJson(['bob' => userRecord()]);
    $arc = new KeyScopedArcCommand(app(ArcCommand::class), keyWith(['v-list-user']));

    expect($arc->json('v-list-user', ['bob']))->toHaveKey('bob');
    expect(fn () => $arc->run('v-delete-user', ['bob']))->toThrow(ApiException::class, 'v-delete-user');
    $fake->assertNotRan('v-delete-user');
});

it('checks a whole plan before its first step and runs nothing when refused', function () {
    $gate = new PlanGate(keyWith(['v-add-user']), Request::create('/api/v2/users', 'POST'));
    $steps = [new Step('Add', 'v-add-user', ['x']), new Step('Role', 'v-change-user-role', ['x', 'reseller'])];
    $ran = false;

    try {
        $gate->execute($steps, function () use (&$ran) {
            $ran = true;

            return new ApplyResult([]);
        }, ['v-update-reseller-counters']);
        $this->fail('The plan was not refused.');
    } catch (ApiException $e) {
        expect($e->status)->toBe(403)
            ->and($e->body()['error']['code'])->toBe('E_FORBIDDEN')
            ->and($e->body()['error']['commands'])->toBe(['v-change-user-role', 'v-update-reseller-counters']);
    }
    expect($ran)->toBeFalse();
});

it('answers a dry run with the masked steps and runs nothing', function () {
    $gate = new PlanGate(keyWith(['*']), Request::create('/api/v2/users?dry_run=1', 'POST'));
    $steps = [new Step('Add user x', 'v-add-user', ['x', Secret::file('hunter2'), 'x@example.com'])];

    $response = $gate->execute($steps, fn () => throw new LogicException('ran'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['data' => ['dry_run' => true, 'steps' => [
            ['label' => 'Add user x', 'command' => 'v-add-user', 'args' => ['x', '******', 'x@example.com']],
        ]]])
        ->and((string) $response->getContent())->not->toContain('hunter2');
});

it('turns a failed step into an error naming it and the steps before it', function () {
    $gate = new PlanGate(keyWith(['*']), Request::create('/api/v2/users', 'POST'));
    $add = new Step('Add user x', 'v-add-user', ['x']);
    $role = new Step('Set the role', 'v-change-user-role', ['x', 'reseller']);

    try {
        $gate->assertApplied(new ApplyResult([$add], $role, 'Error: no such role', ExitCode::Invalid->value));
        $this->fail('No error.');
    } catch (ApiException $e) {
        expect($e->status)->toBe(422)->and($e->body())->toBe(['error' => [
            'code' => 'E_INVALID',
            'message' => 'Error: no such role',
            'step' => ['label' => 'Set the role', 'command' => 'v-change-user-role'],
            'applied' => [['label' => 'Add user x', 'command' => 'v-add-user']],
        ]]);
    }
});

it('answers a step whose argument was refused before it ran with 422, not 500', function () {
    $gate = new PlanGate(keyWith(['*']), Request::create('/api/v2/users', 'POST'));
    $add = new Step('Add user x', 'v-add-user', ['x']);
    $name = new Step('Set the name', 'v-change-user-name', ['x', "X\n"]);

    try {
        $gate->assertApplied(new ApplyResult([$add], $name, 'Error code: 0', invalidArgument: true));
        $this->fail('No error.');
    } catch (ApiException $e) {
        expect($e->status)->toBe(422)->and($e->body())->toBe(['error' => [
            'code' => 'E_INVALID',
            'message' => 'A value contains a character the command does not accept.',
            'step' => ['label' => 'Set the name', 'command' => 'v-change-user-name'],
            'applied' => [['label' => 'Add user x', 'command' => 'v-add-user']],
        ]]);
    }
});

it('maps exceptions to the error codes and statuses of D10', function (Throwable $e, string $code, int $status) {
    $error = ErrorRenderer::toApiException($e);

    expect($error->errorCode->value)->toBe($code)->and($error->status)->toBe($status);
})->with([
    'exists' => [fn () => ArcCommandException::failed(new CommandResult('v-add-user', [], 4, 'Error: exists')), 'E_EXISTS', 409],
    'forbidden is spelled right' => [fn () => ArcCommandException::failed(new CommandResult('v-add-user', [], 10, 'Error: no')), 'E_FORBIDDEN', 403],
    'limit' => [fn () => ArcCommandException::failed(new CommandResult('v-add-user', [], 8, 'Error: limit')), 'E_LIMIT', 422],
    'not run' => [fn () => ArcCommandException::refused('v-x', 'timed out'), 'E_SERVER', 500],
    'argument refused' => [fn () => ArcCommandException::invalidArgument('v-x', 'argument contains a control character'), 'E_INVALID', 422],
    'validation' => [fn () => ValidationException::withMessages(['name' => 'bad']), 'E_VALIDATION', 422],
    '404' => [fn () => new NotFoundHttpException, 'E_NOTEXIST', 404],
    'other' => [fn () => new RuntimeException('boom'), 'E_SERVER', 500],
]);

it('keeps the error codes of the command exit codes', function () {
    foreach (ExitCode::cases() as $exit) {
        expect(ErrorCode::fromExit($exit))->toBeInstanceOf(ErrorCode::class);
    }
});

it('ships v2-read as a part of v2-write, with commands of bin/ only', function () {
    $read = apiSetCommands('v2-read');
    $write = apiSetCommands('v2-write');

    expect(array_diff($read, $write))->toBe([]);
    foreach ([...$write, ...array_merge(...array_map(apiSetCommands(...), apiAreaSets()))] as $command) {
        expect(is_file(base_path('../bin/'.$command)))->toBeTrue("{$command} is not a command of bin/");
    }
});

it('keeps every area set inside v2-write and its reads inside v2-read', function () {
    $read = apiSetCommands('v2-read');
    $write = apiSetCommands('v2-write');
    $areas = apiAreaSets();
    $union = [];

    expect($areas)->toContain('v2-accounts', 'v2-web', 'v2-backup', 'v2-apps', 'v2-server', 'v2-files', 'v2-secrets');
    foreach ($areas as $area) {
        $commands = apiSetCommands($area);
        expect(array_values(array_diff($commands, $write)))->toBe([], "{$area} is not part of v2-write");
        // v-check-sys-ports runs only as `repair` (repairPorts), a write: it stays out of v2-read.
        $reads = array_values(array_filter(
            $commands,
            fn (string $command) => (str_starts_with($command, 'v-list-') || str_starts_with($command, 'v-check-')) && $command !== 'v-check-sys-ports',
        ));
        expect(array_values(array_diff($reads, $read)))->toBe([], "{$area} has a read command outside v2-read");
        $content = (string) file_get_contents(base_path('../install/common/api/'.AccessKeySets::fileOfSet($area)));
        expect($content)->toContain("ROLE='user'")->toContain("API_VERSION='2'");
        $union = array_merge($union, $commands);
    }
    expect(array_values(array_diff($write, $union)))->toBe([]);
});

it('names app processes in the descriptions of v2-apps, which grants them', function () {
    expect(apiSetCommands('v2-apps'))->toContain('v-add-app-process');
    expect(AccessKeySets::hints()['v2-apps'])->toContain('app processes');
    expect(AccessKeySets::summary('v2-apps'))->toContain('app processes');
});

it('puts the file reads of v2-files in v2-read and its changes in v2-write only (E34 batch 5 D56)', function () {
    $files = apiSetCommands('v2-files');
    // Reads named explicitly: the rule above finds v-list-* and v-check-* only. A download
    // (v-add-fs-download) writes nothing into the home, only a ticket into the panel's staging folder.
    $reads = ['v-list-fs-directory-json', 'v-get-fs-file-info', 'v-read-fs-file', 'v-search-fs-object', 'v-list-fs-disk-usage', 'v-add-fs-download'];
    $changes = array_values(array_diff(array_filter($files, fn (string $c) => str_contains($c, '-fs-')), $reads));

    expect($files)->toContain(...$reads)
        ->and(array_values(array_diff($reads, apiSetCommands('v2-read'))))->toBe([])
        ->and(array_values(array_intersect($changes, apiSetCommands('v2-read'))))->toBe([])
        ->and($changes)->toContain('v-save-fs-file', 'v-delete-fs-directory', 'v-extract-fs-archive', 'v-add-fs-upload');
});

it('pages a list and refuses a limit or offset outside its range', function () {
    $pages = new Paginates;
    $items = ['a', 'b', 'c', 'd'];

    expect($pages->slice(Request::create('/api/v2/users', 'GET'), $items))
        ->toBe(['items' => $items, 'meta' => ['total' => 4]])
        ->and($pages->slice(Request::create('/api/v2/users?limit=2&offset=1', 'GET'), $items))
        ->toBe(['items' => ['b', 'c'], 'meta' => ['total' => 4, 'limit' => 2, 'offset' => 1]])
        ->and($pages->slice(Request::create('/api/v2/users?limit=2', 'GET'), $items)['meta'])
        ->toBe(['total' => 4, 'limit' => 2, 'offset' => 0])
        ->and($pages->slice(Request::create('/api/v2/users?offset=3', 'GET'), $items))
        ->toBe(['items' => $items, 'meta' => ['total' => 4]]);

    expect(fn () => $pages->slice(Request::create('/api/v2/users?limit=0', 'GET'), $items))->toThrow(ValidationException::class);
    expect(fn () => $pages->slice(Request::create('/api/v2/users?limit=1001', 'GET'), $items))->toThrow(ValidationException::class);
    expect(fn () => $pages->slice(Request::create('/api/v2/users?offset=-1', 'GET'), $items))->toThrow(ValidationException::class);
    expect(fn () => $pages->slice(Request::create('/api/v2/users?limit=no', 'GET'), $items))->toThrow(ValidationException::class);
});

it('points a duplicate at the existing object', function () {
    $error = new ApiException(ErrorCode::Exists, 'DNS record exists.', 409, existing: '/api/v2/users/bob/dns-zones/example.com/records/3');

    expect($error->body())->toBe(['error' => [
        'code' => 'E_EXISTS',
        'message' => 'DNS record exists.',
        'existing' => '/api/v2/users/bob/dns-zones/example.com/records/3',
    ]]);
});

it('documents pagination and error.existing', function () {
    $spec = json_decode((string) file_get_contents(base_path('../docs/api/v2/openapi.json')), true);

    $existing = $spec['components']['schemas']['Error']['properties']['error']['properties']['existing'] ?? null;
    expect($existing)->toHaveKey('oneOf')
        ->and($existing['oneOf'])->toHaveCount(2)
        ->and($spec['components']['schemas'])->toHaveKey('ListMeta');
    foreach ($spec['paths'] as $operations) {
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! str_starts_with((string) ($operation['operationId'] ?? ''), 'list')) {
                continue;
            }
            $id = (string) ($operation['operationId'] ?? '');
            // The system task list returns the full set without paginated meta (E34 batch 3 §5).
            if (in_array($id, ['listSystemTasks'], true)) {
                continue;
            }
            if (in_array($id, ['listNodeApps', 'listAppProcesses', 'listDockerApps', 'listPorts', 'listServices', 'listUpdates'], true)) {
                $ref = ['listNodeApps' => '#/components/schemas/NodeAppListMeta', 'listAppProcesses' => '#/components/schemas/AppProcessListMeta', 'listDockerApps' => '#/components/schemas/DockerAppListMeta', 'listPorts' => '#/components/schemas/PortListMeta', 'listServices' => '#/components/schemas/ServiceListMeta', 'listUpdates' => '#/components/schemas/UpdateSettings'][$id];
                $schema = $operation['responses']['200']['content']['application/json']['schema']['properties']['meta'] ?? null;
                expect($schema)->toBe(['$ref' => $ref]);
                $names = array_column($operation['parameters'] ?? [], 'name');
                expect($names)->not->toContain('limit')->not->toContain('offset');

                continue;
            }
            if (in_array($id, ['listUserLogs', 'listUserAuthLogs'], true)) {
                $schema = $operation['responses']['200']['content']['application/json']['schema']['properties']['meta'] ?? null;
                expect($schema)->toBe(['$ref' => '#/components/schemas/LogListMeta'], $id);

                continue;
            }
            // Cross-user lists page and carry the change filter (E43 spec §5)
            if (str_starts_with($id, 'listAll')) {
                $schema = $operation['responses']['200']['content']['application/json']['schema']['properties']['meta'] ?? null;
                expect($schema)->toBe(['$ref' => '#/components/schemas/CrossUserListMeta'], $id);
                expect(array_column($operation['parameters'] ?? [], 'name'))->toContain('user', 'updated_since', 'limit', 'offset');

                continue;
            }
            if ($id === 'listDockerCatalog') {
                $names = array_column($operation['parameters'] ?? [], 'name');
                expect($names)->not->toContain('limit')->not->toContain('offset');

                continue;
            }
            $names = array_column($operation['parameters'] ?? [], 'name');
            expect($names)->toContain('limit', 'offset');
            $schema = $operation['responses']['200']['content']['application/json']['schema']['properties']['meta']
                ?? $operation['responses']['200']['content']['application/json']['schema']['allOf'][0]['properties']['meta']
                ?? null;
            expect($schema)->toBe(['$ref' => '#/components/schemas/ListMeta'], $operation['operationId']);
        }
    }
});

it('documents getRequest with the journal fields and meta.duplicates (E43 B4)', function () {
    $spec = json_decode((string) file_get_contents(base_path('../docs/api/v2/openapi.json')), true);
    $operation = $spec['paths']['/requests/{id}']['get'] ?? null;

    expect($operation['operationId'] ?? null)->toBe('getRequest');
    $schema = $operation['responses']['200']['content']['application/json']['schema'];
    expect($schema['properties']['meta'])->toBe(['$ref' => '#/components/schemas/RequestMeta'])
        ->and($spec['components']['schemas']['RequestMeta']['required'])->toBe(['duplicates'])
        ->and(array_keys($spec['components']['schemas']['ApiRequest']['properties']))->toBe([
            'id', 'operation', 'method', 'path', 'state', 'status', 'error_code', 'task_id', 'location', 'started_at', 'finished_at',
        ])
        ->and($spec['components']['schemas']['ApiRequest']['properties']['state']['enum'])->toBe(['running', 'finished', 'unknown']);
});

it('documents getServiceLogs with its lines parameter (E43 B5)', function () {
    $spec = json_decode((string) file_get_contents(base_path('../docs/api/v2/openapi.json')), true);
    $operation = $spec['paths']['/system/services/{service}/logs']['get'] ?? null;

    expect($operation['operationId'] ?? null)->toBe('getServiceLogs');
    $lines = array_values(array_filter($operation['parameters'], fn (array $p) => $p['name'] === 'lines'))[0] ?? null;
    expect($lines['schema'] ?? null)->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100])
        ->and($operation['responses']['200']['content']['application/json']['schema']['properties']['data'])->toBe(['$ref' => '#/components/schemas/ServiceLog'])
        ->and(array_keys($spec['components']['schemas']['ServiceLog']['properties']))->toBe(['service', 'lines']);
});

it('documents regex rules without their PHP delimiters', function () {
    // Scramble strips only "/" delimiters: "#^a$#" would reach the clients as a
    // pattern that matches nothing
    $json = (string) file_get_contents(base_path('../docs/api/v2/openapi.json'));
    preg_match_all('/"pattern": ("(?:[^"\\\\]|\\\\.)*")/', $json, $matches);
    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $pattern) {
        expect(json_decode($pattern))->not->toMatch('/^[#~!@%]/');
    }
});

it('types a DNS zone and a record, with nulls where a value does not apply', function () {
    fakeArc();
    $zone = (new DnsZoneResource(DnsZoneRecord::fromArray('bob', 'example.com', [
        'IP' => '203.0.113.10', 'TPL' => 'default', 'TTL' => '14400', 'EXP' => '2027-10-06', 'SOA' => 'ns1.example.net',
        'SERIAL' => '2026100601', 'DNSSEC' => 'yes', 'RECORDS' => '6', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '10:00:00',
    ])))->resolve(Request::create('/api/v2/users/bob/dns-zones/example.com'));
    $record = (new DnsRecordResource(DnsRecordRecord::fromArray('bob', 'example.com', '5', [
        'RECORD' => '@', 'TYPE' => 'TXT', 'PRIORITY' => '10', 'VALUE' => 'v', 'ID' => '5', 'TTL' => '', 'SUSPENDED' => 'no',
        'DATE' => '2026-10-06', 'TIME' => '10:00:00',
    ])))->resolve(Request::create('/'));

    expect($zone['dnssec'])->toBeNull()
        ->and($zone['ttl'])->toBe(14400)
        ->and($zone['serial'])->toBe(2026100601)
        ->and($zone['expiry'])->toBe('2027-10-06')
        ->and($zone['created_at'])->toBe('2026-10-06T10:00:00')
        ->and($record)->toMatchArray(['id' => 5, 'name' => '@', 'type' => 'TXT', 'priority' => null, 'ttl' => null, 'suspended' => false]);
});

it('declares an area on the batch 1 routes', function () {
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v2/')) {
            continue;
        }
        $area = match (true) {
            // The settings host path names a database host, not the db area
            str_starts_with($uri, 'api/v2/system/settings/') => 'server',
            str_contains($uri, '/app-process') => 'apps',
            str_contains($uri, '/web-domains') => 'web',
            str_contains($uri, '/secrets') => 'secrets',
            str_contains($uri, '/malware') => 'security',
            str_contains($uri, '/dns-zones') => 'dns',
            str_contains($uri, '/mail-domains') => 'mail',
            str_contains($uri, '/databases') => 'db',
            str_contains($uri, '/cron-jobs'), str_contains($uri, '/cron-reports') => 'cron',
            str_contains($uri, '/backups'), str_contains($uri, '/snapshots'), str_contains($uri, '/backup-exclusions') => 'backup',
            str_contains($uri, '/tasks') => 'backup',
            str_contains($uri, '/files') => 'files',
            str_contains($uri, '/system/docker') || str_contains($uri, '/system/ports') => 'server',
            str_starts_with($uri, 'api/v2/system/') => 'server',
            str_contains($uri, '/docker-catalog') || str_contains($uri, '/docker-apps') => 'apps',
            str_contains($uri, '/ports') => 'apps',
            str_contains($uri, '/node') => 'apps',
            str_starts_with($uri, 'api/v2/users'), str_starts_with($uri, 'api/v2/packages') => 'accounts',
            in_array($uri, ['api/v2/stats', 'api/v2/search', 'api/v2/access-key-sets'], true), str_starts_with($uri, 'api/v2/notifications') => 'accounts',
            default => null,
        };
        expect($route->defaults['api_area'] ?? null)->toBe($area, $uri);
    }
});

it('describes the secret name of the path, with or without its leading slash', function () {
    $spec = json_decode((string) file_get_contents(base_path('../docs/api/v2/openapi.json')), true);
    foreach ($spec['paths'] as $path => $operations) {
        if (! str_contains($path, '/secrets/{name}')) {
            continue;
        }
        foreach ($operations as $operation) {
            $name = collect($operation['parameters'] ?? [])->firstWhere('name', 'name');
            expect($name['description'] ?? '')->toContain('leading slash');
        }
    }
});
