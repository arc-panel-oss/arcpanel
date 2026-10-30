<?php

use App\Arc\ExitCode;

// REST API v2, Git Deploy of a web domain (E20,
// docs/features/specs/2026-10-29-001-e20-git-deploy-design.md §8): bob
// (customer of hostco, reseller A) has example.com, carol (customer of
// resb, reseller B) has carol.example. Every command is answered by the fake.

const API_DEPLOY = '/api/v2/users/bob/web-domains/example.com/deploy';

const API_DEPLOY_TASK = '17617400001234';

const API_DEPLOY_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIM6v0x9Qf3kq2r1mJt8Yb7nZ0wXcL4pEo5sHd2aG9uVf arcpanel-deploy@example.com';

/** A deploy in pull mode with a release live, over $overrides. */
function apiDeployPull(array $overrides = []): array
{
    return deployRow(array_replace([
        'ID' => 'k3v9q2m7x1c8', 'MODE' => 'pull', 'URL' => 'git@github.com:acme/shop.git', 'BRANCH' => 'main', 'AUTH' => 'key',
        'PRESET' => 'laravel', 'SHARED' => 'storage/ .env', 'KEEP' => '5', 'WEBROOT' => 'public', 'MIGRATE' => 'yes',
        'HEALTH_PATH' => '/up', 'STATE' => 'active', 'CURRENT' => '20261029-142501', 'RUNNING' => API_DEPLOY_TASK,
        'WEBHOOK_URL' => 'https://panel.example.com:8088/hooks/k3v9q2m7x1c8', 'DOCROOT' => '/home/bob/web/example.com/current/public',
        'PORT_OPEN' => 'yes', 'PUBLIC_KEY' => API_DEPLOY_KEY,
    ], $overrides));
}

/** A deploy in push mode with one trusted publisher, before its first deploy. */
function apiDeployPush(array $overrides = []): array
{
    return deployRow(array_replace([
        'ID' => 'p8x2m4k6q1z9', 'MODE' => 'push', 'PRESET' => 'none', 'KEEP' => '5', 'WEBROOT' => '.', 'MIGRATE' => 'no',
        'STATE' => 'new', 'UPLOAD_URL' => 'https://panel.example.com:8088/upload/p8x2m4k6q1z9', 'PORT_OPEN' => 'yes',
        'PUBLISHERS' => [['PUB' => 'p1', 'ISSUER' => 'https://token.actions.githubusercontent.com', 'SUBJECT_REPO' => 'acme/shop',
            'REF' => 'refs/heads/main', 'ENVIRONMENT' => '', 'WORKFLOW' => '.github/workflows/deploy.yml']],
    ], $overrides));
}

/** Let `v-list-web-domain-deploy bob example.com json` answer $row. */
function apiDeployIs($test, array $row): void
{
    $test->fake->on('v-list-web-domain-deploy', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => $row]);
}

function apiDeployReleases(): array
{
    return [
        'releases' => [
            ['ID' => '20261029-142501', 'SOURCE' => 'pull', 'COMMIT' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'REF' => 'refs/heads/main', 'CREATED' => '2026-10-29 14:25:01', 'TASK' => '17617400001234', 'CURRENT' => 'yes'],
            ['ID' => '20261028-091744', 'SOURCE' => 'archive', 'COMMIT' => '', 'REF' => '', 'CREATED' => '2026-10-28 09:17:44', 'TASK' => '', 'CURRENT' => 'no'],
        ],
        'tasks' => [
            ['ID' => '17617400001234', 'ACTION' => 'pull', 'VALUE' => 'main', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '', 'QUEUED' => '2026-10-29 14:24:58', 'START' => '2026-10-29 14:24:58', 'END' => '2026-10-29 14:26:10'],
        ],
    ];
}

/** The commands that change a deploy; none may run in a dry run or for a refused key. */
function apiDeployWrites(): array
{
    return [
        'v-add-web-domain-deploy', 'v-change-web-domain-deploy', 'v-change-web-domain-deploy-credential', 'v-schedule-web-domain-deploy',
        'v-rollback-web-domain-deploy', 'v-add-web-domain-deploy-publisher', 'v-delete-web-domain-deploy-publisher', 'v-delete-web-domain-deploy',
    ];
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-web-domain-deploys')->returnsJson(apiDeployReleases());
    foreach (['v-change-web-domain-deploy', 'v-delete-web-domain-deploy', 'v-delete-web-domain-deploy-publisher'] as $command) {
        $this->fake->on($command)->returns();
    }
    $this->fake->on('v-schedule-web-domain-deploy')->returnsJson(['ID' => API_DEPLOY_TASK]);
    $this->fake->on('v-rollback-web-domain-deploy')->returnsJson(['ID' => API_DEPLOY_TASK]);
    $this->fake->on('v-add-web-domain-deploy-publisher')->returnsJson(['ID' => 'p2']);
});

// ---------------------------------------------------------------- permission sets

it('ships v2-deploy with the commands of §4 but the server, endpoint and runner ones, inside the unions', function () {
    $deploy = apiSetCommands('v2-deploy');
    $own = ['v-list-web-domain-deploy', 'v-list-web-domain-deploys', 'v-add-web-domain-deploy', 'v-change-web-domain-deploy',
        'v-change-web-domain-deploy-credential', 'v-add-web-domain-deploy-publisher', 'v-delete-web-domain-deploy-publisher',
        'v-schedule-web-domain-deploy', 'v-rollback-web-domain-deploy', 'v-delete-web-domain-deploy'];

    expect($deploy)->toContain(...$own)
        ->and(array_values(array_intersect($deploy, ['v-change-sys-deploy', 'v-list-sys-deploy', 'v-get-web-domain-deploy-endpoint', 'v-run-web-domain-deploy'])))->toBe([])
        ->and(array_values(array_diff($own, apiSetCommands('v2-write'))))->toBe([])
        ->and(apiSetCommands('v2-read'))->toContain('v-list-web-domain-deploy', 'v-list-web-domain-deploys')
        ->and(apiSetCommands('v2-observe'))->toContain('v-list-web-domain-deploy', 'v-list-web-domain-deploys')
        ->and(array_values(array_intersect(array_diff($own, ['v-list-web-domain-deploy', 'v-list-web-domain-deploys']), apiSetCommands('v2-read'))))->toBe([])
        // Neither the endpoint nor the runner is in any set of the API
        ->and(array_values(array_intersect(apiSetCommands('v2-write'), ['v-get-web-domain-deploy-endpoint', 'v-run-web-domain-deploy'])))->toBe([]);
});

it('declares the deploy area on every deploy route', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), '/web-domains/{domain}/deploy'));

    expect($routes)->toHaveCount(8);
    foreach ($routes as $route) {
        expect($route->defaults['api_area'] ?? null)->toBe('deploy', $route->uri());
    }
});

// ---------------------------------------------------------------- getWebDomainDeploy

it('reads the deploy of a web domain within the owner tree', function (string $principal, string $role, string $owner, int $status) {
    $this->fake->on('v-list-web-domain-deploy', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => apiDeployPull()]);
    $domain = $owner === 'bob' ? 'example.com' : 'carol.example';

    $response = $this->getJson("/api/v2/users/{$owner}/web-domains/{$domain}/deploy", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('data.domain', $domain);
    } else {
        $response->assertJsonPath('error.code', 'E_NOTEXIST');
        $this->fake->assertNotRan('v-list-web-domain-deploy');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 'bob', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['carol', 'user', 'bob', 404],
]);

it('describes an active pull deploy with typed fields and no secret', function () {
    apiDeployIs($this, apiDeployPull());

    $response = $this->getJson(API_DEPLOY, apiAs($this->fake, 'bob', 'user', area: 'deploy'))->assertOk()
        ->assertJsonPath('data', [
            'domain' => 'example.com', 'state' => 'active', 'mode' => 'pull', 'id' => 'k3v9q2m7x1c8',
            'url' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'auth' => 'key', 'preset' => 'laravel',
            'shared' => ['storage/', '.env'], 'keep' => 5, 'webroot' => 'public', 'migrate' => true, 'health_path' => '/up',
            'current_release' => '20261029-142501', 'running_task' => 'queue:bob:'.API_DEPLOY_TASK, 'pending_task' => null,
            'supported' => true, 'reason' => null, 'webhook_url' => 'https://panel.example.com:8088/hooks/k3v9q2m7x1c8', 'upload_url' => null,
            'document_root' => '/home/bob/web/example.com/current/public', 'deploy_port' => 8088, 'port_open' => true,
            'publishers' => [], 'public_key' => null, 'webhook_secret' => null,
        ]);
    // The public key is shown once, by the request that created it (D4)
    expect($response->getContent())->not->toContain('AAAAC3NzaC1lZDI1NTE5');
});

it('describes a deploy that is off, and a push deploy with its publishers', function () {
    $this->getJson(API_DEPLOY, apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.state', 'off')
        ->assertJsonPath('data.mode', null)
        ->assertJsonPath('data.shared', [])
        ->assertJsonPath('data.keep', null)
        ->assertJsonPath('data.migrate', null)
        ->assertJsonPath('data.supported', true)
        ->assertJsonPath('data.port_open', false);

    apiDeployIs($this, apiDeployPush());
    $this->getJson(API_DEPLOY, apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.state', 'new')
        ->assertJsonPath('data.mode', 'push')
        ->assertJsonPath('data.url', null)
        ->assertJsonPath('data.upload_url', 'https://panel.example.com:8088/upload/p8x2m4k6q1z9')
        ->assertJsonPath('data.publishers', [[
            'id' => 'p1', 'issuer' => 'https://token.actions.githubusercontent.com', 'repository' => 'acme/shop',
            'ref' => 'refs/heads/main', 'environment' => null, 'workflow' => '.github/workflows/deploy.yml',
        ]]);
});

it('answers 404 for an unknown domain or user', function () {
    $this->fake->on('v-list-web-domain-deploy', ['bob', 'nope.example', 'json'])->returnsExit(ExitCode::NotExist, "Error: web domain nope.example doesn't exist");
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/users/bob/web-domains/nope.example/deploy', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->getJson('/api/v2/users/ghost/web-domains/ghost.example/deploy', $headers)->assertNotFound();
});

// ---------------------------------------------------------------- configureWebDomainDeploy: turn on

it('turns on pull mode with a key holding only v2-deploy and answers the public key and webhook secret once', function () {
    $this->fake->on('v-add-web-domain-deploy')->returns("PUBLIC_KEY='".API_DEPLOY_KEY."'\nWEBHOOK_SECRET='whsec0123456789abcdef'\n");
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');
    $body = ['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'preset' => 'laravel', 'webroot' => 'public'];

    $this->putJson(API_DEPLOY.'?dry_run=1', $body, $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'pull', 'git@github.com:acme/shop.git', 'main', 'laravel', 'key'])
        ->assertJsonPath('data.steps.1.args', ['bob', 'example.com', 'WEBROOT', 'public'])
        ->assertJsonCount(2, 'data.steps');
    foreach (apiDeployWrites() as $command) {
        $this->fake->assertNotRan($command);
    }

    // The configuration after the add: pull mode with the default web root
    $this->fake->on('v-add-web-domain-deploy')->using(function (array $args) {
        apiDeployIs($this, apiDeployPull(['STATE' => 'new', 'CURRENT' => '', 'RUNNING' => '', 'WEBROOT' => '.']));

        return [0, "PUBLIC_KEY='".API_DEPLOY_KEY."'\nWEBHOOK_SECRET='whsec0123456789abcdef'\n"];
    });
    $this->putJson(API_DEPLOY, $body, $headers)->assertOk()
        ->assertJsonPath('data.state', 'new')
        ->assertJsonPath('data.public_key', API_DEPLOY_KEY)
        ->assertJsonPath('data.webhook_secret', 'whsec0123456789abcdef');
    $this->fake->assertRan('v-add-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', 'pull', 'git@github.com:acme/shop.git', 'main', 'laravel', 'key']);
    $this->fake->assertRan('v-change-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', 'WEBROOT', 'public'], 1);
    $this->fake->assertNotRan('v-change-web-domain-deploy-credential');

    // A later read never shows them again
    $this->getJson(API_DEPLOY, $headers)->assertOk()->assertJsonPath('data.public_key', null)->assertJsonPath('data.webhook_secret', null);
});

it('turns on pull mode with a token, which travels as a secret file and never comes back', function () {
    $this->fake->on('v-add-web-domain-deploy')->using(function () {
        apiDeployIs($this, apiDeployPull(['STATE' => 'new', 'URL' => 'https://gitlab.com/acme/shop.git', 'AUTH' => 'token', 'PUBLIC_KEY' => '', 'WEBROOT' => '.']));

        return [0, "WEBHOOK_SECRET='whsec0123456789abcdef'\n"];
    });
    $this->fake->on('v-change-web-domain-deploy-credential')->returns();
    $headers = apiAs($this->fake, 'bob', 'user');
    $body = ['mode' => 'pull', 'url' => 'https://gitlab.com/acme/shop.git', 'auth' => 'token', 'token' => 'glpat-0123456789abcdef'];

    $this->putJson(API_DEPLOY.'?dry_run=1', $body, $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'pull', 'https://gitlab.com/acme/shop.git', 'main', 'none', 'token'])
        ->assertJsonPath('data.steps.1.args', ['bob', 'example.com', 'token', '******'])
        ->assertJsonCount(2, 'data.steps');
    $this->fake->assertNotRan('v-add-web-domain-deploy');

    $response = $this->putJson(API_DEPLOY, $body, $headers)->assertOk()
        ->assertJsonPath('data.auth', 'token')
        ->assertJsonPath('data.public_key', null)
        ->assertJsonPath('data.webhook_secret', 'whsec0123456789abcdef');
    expect($response->getContent())->not->toContain('glpat-');
    $this->fake->assertRan('v-change-web-domain-deploy-credential', fn ($a) => $a[2] === 'token' && $a[3] === 'glpat-0123456789abcdef');
});

it('turns off again what it turned on when a later step fails', function () {
    $this->fake->on('v-add-web-domain-deploy')->returns("PUBLIC_KEY='".API_DEPLOY_KEY."'\n");
    $this->fake->on('v-change-web-domain-deploy')->returnsExit(ExitCode::Invalid, 'Error: invalid WEBROOT :: x');

    $this->putJson(API_DEPLOY, ['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'webroot' => 'dist'], apiAs($this->fake, 'bob', 'user', area: 'deploy'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'E_INVALID')
        ->assertJsonPath('error.step.command', 'v-change-web-domain-deploy');
    $this->fake->assertRan('v-delete-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com'], 1);
});

it('turns on push mode with its first trusted publisher, and without one', function () {
    $this->fake->on('v-add-web-domain-deploy')->using(function () {
        apiDeployIs($this, apiDeployPush());

        return [0, ''];
    });
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');
    $body = ['mode' => 'push', 'webroot' => 'public', 'publisher' => [
        'issuer' => 'https://token.actions.githubusercontent.com', 'repository' => 'acme/shop', 'ref' => 'main', 'workflow' => '.github/workflows/deploy.yml',
    ]];

    $this->putJson(API_DEPLOY.'?dry_run=1', $body, $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'push'])
        ->assertJsonPath('data.steps.1.args', ['bob', 'example.com', 'WEBROOT', 'public'])
        ->assertJsonPath('data.steps.2.args', ['bob', 'example.com', 'https://token.actions.githubusercontent.com', 'acme/shop', 'refs/heads/main', '', '.github/workflows/deploy.yml', 'json']);
    $this->fake->assertNotRan('v-add-web-domain-deploy');

    $this->putJson(API_DEPLOY, $body, $headers)->assertOk()
        ->assertJsonPath('data.mode', 'push')
        ->assertJsonPath('data.public_key', null)
        ->assertJsonPath('data.webhook_secret', null);
    $this->fake->assertRan('v-add-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', 'push']);
    $this->fake->assertRan('v-add-web-domain-deploy-publisher', fn ($a) => $a[4] === 'refs/heads/main', 1);

    apiDeployIs($this, deployRow());
    $this->putJson(API_DEPLOY.'?dry_run=1', ['mode' => 'push'], $headers)->assertOk()
        ->assertJsonCount(1, 'data.steps')
        ->assertJsonPath('data.steps.0.command', 'v-add-web-domain-deploy');
});

it('validates the settings as the commands do before any command', function (array $body, string $field) {
    $this->putJson(API_DEPLOY, $body, apiAs($this->fake, 'bob', 'user'))->assertStatus(422)
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonPath('error.fields', fn ($fields) => isset($fields[$field]));
    foreach (apiDeployWrites() as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'no mode to turn on' => [['url' => 'git@github.com:acme/shop.git'], 'mode'],
    'unknown mode' => [['mode' => 'ftp'], 'mode'],
    'pull without URL' => [['mode' => 'pull'], 'url'],
    'file URL' => [['mode' => 'pull', 'url' => 'file:///srv/repo.git'], 'url'],
    'local path' => [['mode' => 'pull', 'url' => '/srv/repo.git'], 'url'],
    'scp URL with ..' => [['mode' => 'pull', 'url' => 'git@github.com:acme/../shop.git'], 'url'],
    'http URL' => [['mode' => 'pull', 'url' => 'http://github.com/acme/shop.git'], 'url'],
    'branch with ..' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'branch' => 'a..b'], 'branch'],
    'branch starting with -' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'branch' => '-x'], 'branch'],
    'branch ending with .lock' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'branch' => 'main.lock'], 'branch'],
    'unknown preset' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'preset' => 'symfony'], 'preset'],
    'unknown auth' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'auth' => 'password'], 'auth'],
    'token without token' => [['mode' => 'pull', 'url' => 'https://github.com/acme/shop.git', 'auth' => 'token'], 'token'],
    'token over SSH' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'auth' => 'token', 'token' => 't0k3n'], 'auth'],
    'push with a URL' => [['mode' => 'push', 'url' => 'git@github.com:acme/shop.git'], 'url'],
    'push with a preset' => [['mode' => 'push', 'preset' => 'laravel'], 'preset'],
    'web root outside' => [['mode' => 'push', 'webroot' => '../www'], 'webroot'],
    'absolute shared path' => [['mode' => 'push', 'shared' => ['/etc']], 'shared.0'],
    'shared path with ..' => [['mode' => 'push', 'shared' => ['storage/../../x']], 'shared.0'],
    '21 shared paths' => [['mode' => 'push', 'shared' => array_map(fn ($i) => "d{$i}/", range(1, 21))], 'shared'],
    'keep 0' => [['mode' => 'push', 'keep' => 0], 'keep'],
    'keep 21' => [['mode' => 'push', 'keep' => 21], 'keep'],
    'health path without /' => [['mode' => 'push', 'health_path' => 'up'], 'health_path'],
    'publisher without branch and environment' => [['mode' => 'push', 'publisher' => ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop']], 'publisher.ref'],
    'publisher repository without owner' => [['mode' => 'push', 'publisher' => ['issuer' => 'https://gitlab.com', 'repository' => 'shop', 'ref' => 'main']], 'publisher.repository'],
    'publisher workflow not YAML' => [['mode' => 'push', 'publisher' => ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop', 'ref' => 'main', 'workflow' => 'deploy.sh']], 'publisher.workflow'],
    'publisher on pull mode' => [['mode' => 'pull', 'url' => 'git@github.com:acme/shop.git', 'publisher' => ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop', 'ref' => 'main']], 'publisher'],
]);

it('refuses to turn on deploy where the package or the template does not allow it, before any command', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    apiDeployIs($this, deployRow(['SUPPORTED' => 'no', 'REASON' => 'package']));
    $this->putJson(API_DEPLOY, ['mode' => 'push'], $headers)->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN');
    apiDeployIs($this, deployRow(['SUPPORTED' => 'no', 'REASON' => 'template']));
    $this->putJson(API_DEPLOY.'?dry_run=1', ['mode' => 'push'], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_DISABLED');
    $this->fake->assertNotRan('v-add-web-domain-deploy');
});

// ---------------------------------------------------------------- configureWebDomainDeploy: change

it('changes only the settings that differ', function () {
    apiDeployIs($this, apiDeployPull());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');
    $body = ['mode' => 'pull', 'branch' => 'develop', 'keep' => 10, 'shared' => ['storage/', '.env'], 'migrate' => false, 'health_path' => null, 'preset' => 'laravel'];

    $this->putJson(API_DEPLOY.'?dry_run=1', $body, $headers)->assertOk()
        ->assertJsonPath('data.steps.*.args', [
            ['bob', 'example.com', 'BRANCH', 'develop'],
            ['bob', 'example.com', 'KEEP', '10'],
            ['bob', 'example.com', 'MIGRATE', 'no'],
            ['bob', 'example.com', 'HEALTH_PATH', ''],
        ]);
    $this->fake->assertNotRan('v-change-web-domain-deploy');

    $this->putJson(API_DEPLOY, $body, $headers)->assertOk()->assertJsonPath('data.state', 'active')->assertJsonPath('data.public_key', null);
    expect(collect($this->fake->calls('v-change-web-domain-deploy'))->pluck('args')->all())->toBe([
        ['bob', 'example.com', 'BRANCH', 'develop'],
        ['bob', 'example.com', 'KEEP', '10'],
        ['bob', 'example.com', 'MIGRATE', 'no'],
        ['bob', 'example.com', 'HEALTH_PATH', ''],
    ]);

    // The current values change nothing
    $this->putJson(API_DEPLOY, ['branch' => 'main', 'shared' => ['storage/', '.env']], $headers)->assertOk();
    $this->fake->assertRan('v-change-web-domain-deploy', null, 4);
});

it('refuses another mode and the settings of pull mode on a push deploy', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    apiDeployIs($this, apiDeployPull());
    $this->putJson(API_DEPLOY, ['mode' => 'push'], $headers)->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['mode']));
    apiDeployIs($this, apiDeployPush());
    $this->putJson(API_DEPLOY, ['branch' => 'main'], $headers)->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['branch']));
    $this->putJson(API_DEPLOY, ['migrate' => true], $headers)->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['migrate']));
    $this->putJson(API_DEPLOY, ['auth' => 'key'], $headers)->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['auth']));
    $this->putJson(API_DEPLOY, ['publisher' => ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop', 'ref' => 'main']], $headers)
        ->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['publisher']));
    foreach (apiDeployWrites() as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('renews the deploy key of a pull deploy and answers the new public key once', function () {
    apiDeployIs($this, apiDeployPull(['URL' => 'https://github.com/acme/shop.git', 'AUTH' => 'token', 'PUBLIC_KEY' => '']));
    $this->fake->on('v-change-web-domain-deploy-credential')->using(fn (array $a) => [0, $a[2] === 'key' ? "PUBLIC_KEY='".API_DEPLOY_KEY."'\n" : '']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');

    $this->putJson(API_DEPLOY.'?dry_run=1', ['auth' => 'key'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'key']);
    $this->fake->assertNotRan('v-change-web-domain-deploy-credential');
    $this->putJson(API_DEPLOY, ['auth' => 'key'], $headers)->assertOk()->assertJsonPath('data.public_key', API_DEPLOY_KEY);
    $this->putJson(API_DEPLOY, ['token' => 'ghp_new0123456789'], $headers)->assertOk()->assertJsonPath('data.public_key', null);
    $this->fake->assertRan('v-change-web-domain-deploy-credential', fn ($a) => $a[2] === 'token' && $a[3] === 'ghp_new0123456789', 1);
});

it('configures the deploy within the owner tree only', function (string $principal, string $role, string $owner, int $status) {
    $this->fake->on('v-add-web-domain-deploy')->returns('');
    $domain = $owner === 'bob' ? 'example.com' : 'carol.example';

    $this->putJson("/api/v2/users/{$owner}/web-domains/{$domain}/deploy", ['mode' => 'push'], apiAs($this->fake, $principal, $role))->assertStatus($status);
    if ($status === 404) {
        $this->fake->assertNotRan('v-add-web-domain-deploy');
        $this->fake->assertNotRan('v-list-web-domain-deploy');
    } else {
        $this->fake->assertRan('v-add-web-domain-deploy', fn ($a) => $a === [$owner, $domain, 'push']);
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 'bob', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['carol', 'user', 'bob', 404],
]);

// ---------------------------------------------------------------- disableWebDomainDeploy

it('turns the deploy off', function () {
    apiDeployIs($this, apiDeployPull());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');

    $this->deleteJson(API_DEPLOY.'?dry_run=1', [], $headers)->assertOk()->assertJsonPath('data.steps.0.args', ['bob', 'example.com']);
    $this->fake->assertNotRan('v-delete-web-domain-deploy');

    $this->fake->on('v-delete-web-domain-deploy')->using(function () {
        apiDeployIs($this, deployRow());

        return [0, ''];
    });
    $this->deleteJson(API_DEPLOY, [], $headers)->assertOk()->assertJsonPath('data.state', 'off');
    $this->fake->assertRan('v-delete-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com'], 1);

    // Off already
    $this->deleteJson(API_DEPLOY, [], $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->deleteJson('/api/v2/users/carol/web-domains/carol.example/deploy', [], $headers)->assertNotFound();
    $this->fake->assertRan('v-delete-web-domain-deploy', null, 1);
});

// ---------------------------------------------------------------- runWebDomainDeploy

it('starts a deploy and answers 202 with the task', function () {
    apiDeployIs($this, apiDeployPull());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');

    $this->postJson(API_DEPLOY.'/runs?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-schedule-web-domain-deploy')
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', '', 'json']);
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');

    $this->postJson(API_DEPLOY.'/runs', [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:'.API_DEPLOY_TASK.'>; rel="monitor"')
        ->assertHeaderMissing('Location')
        ->assertJsonPath('data.id', 'queue:bob:'.API_DEPLOY_TASK)
        ->assertJsonPath('data.kind', 'deploy')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.args', 'example.com pull main')
        ->assertJsonPath('data.resource', '/users/bob/web-domains/example.com/deploy');
    $this->fake->assertRan('v-schedule-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', '', 'json']);

    $commit = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $this->postJson(API_DEPLOY.'/runs', ['ref' => $commit], $headers)->assertStatus(202)->assertJsonPath('data.args', "example.com pull {$commit}");
    $this->fake->assertRan('v-schedule-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', $commit, 'json']);
    $this->postJson(API_DEPLOY.'/runs', ['ref' => 'a..b'], $headers)->assertStatus(422);
    $this->fake->assertRan('v-schedule-web-domain-deploy', null, 2);
});

it('refuses a run where there is nothing to deploy from the API', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson(API_DEPLOY.'/runs', [], $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    apiDeployIs($this, apiDeployPush());
    $this->postJson(API_DEPLOY.'/runs', [], $headers)->assertStatus(422)->assertJsonPath('error.code', 'E_INVALID');
    $this->postJson('/api/v2/users/carol/web-domains/carol.example/deploy/runs', [], $headers)->assertNotFound();
    $this->fake->assertNotRan('v-schedule-web-domain-deploy');
});

it('answers a failed schedule with the status of its exit code', function () {
    apiDeployIs($this, apiDeployPull());
    $this->fake->on('v-schedule-web-domain-deploy')->returnsExit(ExitCode::Forbidden, 'Error: the package of bob does not allow Git Deploy');

    $this->postJson(API_DEPLOY.'/runs', [], apiAs($this->fake, 'bob', 'user'))->assertForbidden()
        ->assertJsonPath('error.message', 'Error: the package of bob does not allow Git Deploy');
});

// ---------------------------------------------------------------- listWebDomainDeployReleases, rollbackWebDomainDeploy

it('lists the releases newest first', function (string $principal, string $role, int $status) {
    apiDeployIs($this, apiDeployPull());

    $response = $this->getJson(API_DEPLOY.'/releases', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0', [
                'id' => '20261029-142501', 'source' => 'pull', 'commit' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'ref' => 'refs/heads/main',
                'created_at' => '2026-10-29T14:25:01Z', 'task' => 'queue:bob:17617400001234', 'current' => true,
            ])
            ->assertJsonPath('data.1.commit', null)
            ->assertJsonPath('data.1.task', null)
            ->assertJsonPath('data.1.current', false);
    } else {
        $this->fake->assertNotRan('v-list-web-domain-deploys');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 200],
    'reseller B' => ['resb', 'reseller', 404],
    'user' => ['bob', 'user', 200],
    'other user' => ['carol', 'user', 404],
]);

it('pages the releases', function () {
    $this->getJson(API_DEPLOY.'/releases?limit=1&offset=1', apiAs($this->fake, 'bob', 'user', area: 'deploy'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', '20261028-091744')
        ->assertJsonPath('meta', ['total' => 2, 'limit' => 1, 'offset' => 1]);
});

it('rolls back to a release and answers 202 with the task', function () {
    apiDeployIs($this, apiDeployPull());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');
    $rollback = API_DEPLOY.'/releases/20261028-091744/rollback';

    $this->postJson($rollback.'?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', '20261028-091744', 'json']);
    $this->fake->assertNotRan('v-rollback-web-domain-deploy');

    $this->postJson($rollback, [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:'.API_DEPLOY_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.kind', 'deploy')
        ->assertJsonPath('data.args', 'example.com rollback 20261028-091744')
        ->assertJsonPath('data.resource', '/users/bob/web-domains/example.com/deploy');
    $this->fake->assertRan('v-rollback-web-domain-deploy', fn ($a) => $a === ['bob', 'example.com', '20261028-091744', 'json']);

    $this->postJson(API_DEPLOY.'/releases/20200101-000000/rollback', [], $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->postJson(API_DEPLOY.'/releases/latest/rollback', [], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/carol/web-domains/carol.example/deploy/releases/20261028-091744/rollback', [], $headers)->assertNotFound();
    $this->fake->assertRan('v-rollback-web-domain-deploy', null, 1);
});

it('limits deploys and rollbacks to 10 a minute per account', function () {
    apiDeployIs($this, apiDeployPull());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');

    foreach (range(1, 10) as $i) {
        $this->postJson(API_DEPLOY.'/runs', [], $headers)->assertStatus(202);
    }
    $this->postJson(API_DEPLOY.'/releases/20261028-091744/rollback', [], $headers)->assertStatus(429);
    $this->fake->assertNotRan('v-rollback-web-domain-deploy');
});

// ---------------------------------------------------------------- publishers

it('adds a trusted publisher to a push deploy', function () {
    apiDeployIs($this, apiDeployPush());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');
    $body = ['issuer' => 'https://gitlab.com', 'repository' => 'acme/group/shop', 'environment' => 'production'];

    $this->postJson(API_DEPLOY.'/publishers?dry_run=1', $body, $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'https://gitlab.com', 'acme/group/shop', '', 'production', '', 'json']);
    $this->fake->assertNotRan('v-add-web-domain-deploy-publisher');

    $this->postJson(API_DEPLOY.'/publishers', $body + ['ref' => 'refs/tags/v1.0'], $headers)->assertOk()->assertJsonPath('data.mode', 'push');
    $this->fake->assertRan('v-add-web-domain-deploy-publisher', fn ($a) => $a === ['bob', 'example.com', 'https://gitlab.com', 'acme/group/shop', 'refs/tags/v1.0', 'production', '', 'json']);

    $this->postJson(API_DEPLOY.'/publishers', ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields', fn ($f) => isset($f['ref']));
    $this->postJson(API_DEPLOY.'/publishers', ['issuer' => 'gitlab', 'repository' => 'acme/shop', 'ref' => 'main'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields', fn ($f) => isset($f['issuer']));
    $this->postJson(API_DEPLOY.'/publishers', ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop', 'ref' => 'refs/pull/1'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields', fn ($f) => isset($f['ref']));
    $this->fake->assertRan('v-add-web-domain-deploy-publisher', null, 1);

    // An issuer the server does not allow is the command's answer
    $this->fake->on('v-add-web-domain-deploy-publisher')->returnsExit(ExitCode::Invalid, 'Error: the issuer is not allowed on this server :: https://git.example.com');
    $this->postJson(API_DEPLOY.'/publishers', ['issuer' => 'https://git.example.com', 'repository' => 'acme/shop', 'ref' => 'main'], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'E_INVALID');
});

it('refuses publishers on a pull deploy and on a deploy that is off', function () {
    $headers = apiAs($this->fake, 'bob', 'user');
    $body = ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop', 'ref' => 'main'];

    $this->postJson(API_DEPLOY.'/publishers', $body, $headers)->assertNotFound();
    apiDeployIs($this, apiDeployPull());
    $this->postJson(API_DEPLOY.'/publishers', $body, $headers)->assertStatus(422)->assertJsonPath('error.code', 'E_INVALID');
    $this->deleteJson(API_DEPLOY.'/publishers/p1', [], $headers)->assertNotFound();
    $this->fake->assertNotRan('v-add-web-domain-deploy-publisher');
    $this->fake->assertNotRan('v-delete-web-domain-deploy-publisher');
});

it('removes a trusted publisher', function () {
    apiDeployIs($this, apiDeployPush());
    $headers = apiAs($this->fake, 'bob', 'user', area: 'deploy');

    $this->deleteJson(API_DEPLOY.'/publishers/p1?dry_run=1', [], $headers)->assertOk()->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'p1']);
    $this->fake->assertNotRan('v-delete-web-domain-deploy-publisher');
    $this->deleteJson(API_DEPLOY.'/publishers/p1', [], $headers)->assertOk()->assertJsonPath('data.domain', 'example.com');
    $this->fake->assertRan('v-delete-web-domain-deploy-publisher', fn ($a) => $a === ['bob', 'example.com', 'p1'], 1);

    $this->deleteJson(API_DEPLOY.'/publishers/p9', [], $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->deleteJson(API_DEPLOY.'/publishers/x1', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/users/carol/web-domains/carol.example/deploy/publishers/p1', [], $headers)->assertNotFound();
    $this->fake->assertRan('v-delete-web-domain-deploy-publisher', null, 1);
});

// ---------------------------------------------------------------- keys

it('reads with a v2-read key and refuses every change before any command', function () {
    apiDeployIs($this, apiDeployPush());
    $headers = apiKey($this->fake, 'bob', 'user', ['v2-read']);

    $this->getJson(API_DEPLOY, $headers)->assertOk();
    $this->getJson(API_DEPLOY.'/releases', $headers)->assertOk();
    $this->putJson(API_DEPLOY, ['keep' => 3], $headers)->assertForbidden()->assertJsonPath('error.commands', ['v-change-web-domain-deploy']);
    $this->putJson(API_DEPLOY.'?dry_run=1', ['keep' => 3], $headers)->assertForbidden();
    $this->deleteJson(API_DEPLOY, [], $headers)->assertForbidden();
    $this->postJson(API_DEPLOY.'/runs', [], $headers)->assertForbidden();
    $this->postJson(API_DEPLOY.'/releases/20261028-091744/rollback', [], $headers)->assertForbidden();
    $this->postJson(API_DEPLOY.'/publishers', ['issuer' => 'https://gitlab.com', 'repository' => 'acme/shop', 'ref' => 'main'], $headers)->assertForbidden();
    $this->deleteJson(API_DEPLOY.'/publishers/p1', [], $headers)->assertForbidden();
    foreach (apiDeployWrites() as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('keeps the deploy out of the other areas: a v2-web key may not use it, a v2-deploy key may not list web domains', function () {
    $this->getJson(API_DEPLOY, apiAs($this->fake, 'bob', 'user', area: 'web'))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-list-web-domain-deploy']);
    $this->getJson('/api/v2/users/bob/web-domains', apiAs($this->fake, 'bob', 'user', area: 'deploy'))->assertForbidden();
});
