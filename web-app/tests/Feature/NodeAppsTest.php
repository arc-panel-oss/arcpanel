<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;

// Node.js apps: JSON routes of the island (E14 spec §6). hostco owns bob (on
// the developer package, with Node.js and one app), resb owns carol (default
// package, without Node.js, with an app left from before).

beforeEach(function () {
    $this->fake = fakeArc(['WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes', 'NODE_APPS' => '3']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
    $this->fake->on('v-list-node-apps', ['bob', 'json'])->returnsJson(nodeAppList('bob', [nodeApp('api.bob.example')]));
    $this->fake->on('v-list-node-apps', ['carol', 'json'])->returnsJson(nodeAppList('carol', [nodeApp('old.carol.example', ['STATUS' => 'errored'])], ['NODE' => 'no']));
    $this->fake->on('v-list-node-app', ['bob', 'api.bob.example', 'json'])->returnsJson(['api.bob.example' => nodeApp('api.bob.example', ['URL' => 'https://api.bob.example/', 'SSL' => 'yes'])]);
    $this->fake->on('v-list-node-app', ['carol', 'old.carol.example', 'json'])->returnsJson(['old.carol.example' => nodeApp('old.carol.example')]);
    $this->fake->on('v-list-node-app', ['bob', 'none.bob.example', 'json'])->returnsExit(ExitCode::NotExist, 'Error: none.bob.example has no Node.js app');
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user));
    }
});

it('lists the apps of an account with totals and abilities', function () {
    signIn('bob', 'user');

    $this->getJson('/node/bob/api/apps')->assertOk()
        ->assertJsonPath('account.count', 1)
        ->assertJsonPath('account.hasRoom', true)
        ->assertJsonPath('account.totals.online', 1)
        ->assertJsonPath('account.apps.0.domain', 'api.bob.example')
        ->assertJsonPath('account.apps.0.runtime', '24.21.0')
        ->assertJsonPath('abilities', ['manage' => true, 'remove' => true]);

    $this->getJson('/node/bob/api/apps/api.bob.example')->assertOk()
        ->assertJsonPath('app.url', 'https://api.bob.example/')
        ->assertJsonPath('app.ssl', true);
    $this->getJson('/node/bob/api/apps/none.bob.example')->assertNotFound();
});

it('answers 404 outside the scope and 403 without Node.js in the package', function () {
    signIn('bob', 'user');
    $this->getJson('/node/carol/api/apps')->assertNotFound();
    $this->postJson('/node/carol/api/apps/old.carol.example/start')->assertNotFound();

    signIn('hostco', 'reseller');
    $this->getJson('/node/bob/api/apps')->assertOk();
    $this->getJson('/node/carol/api/apps')->assertNotFound();

    signIn('resb', 'reseller');
    $this->getJson('/node/bob/api/apps/api.bob.example')->assertNotFound();
    $this->getJson('/node/carol/api/apps')->assertOk()->assertJsonPath('abilities', ['manage' => false, 'remove' => true]);

    // carol's package has no Node.js: no creating or starting, stopping and deleting stay
    signIn('carol', 'user');
    $this->fake->on('v-stop-node-app')->returns();
    $this->fake->on('v-delete-node-app')->returns();
    $this->postJson('/node/carol/api/apps', ['domain' => 'new.carol.example'])->assertForbidden();
    $this->postJson('/node/carol/api/apps/old.carol.example/start')->assertForbidden();
    $this->putJson('/node/carol/api/apps/old.carol.example/env', ['vars' => []])->assertForbidden();
    $this->postJson('/node/carol/api/apps/old.carol.example/stop')->assertOk();
    $this->deleteJson('/node/carol/api/apps/old.carol.example')->assertOk();

    signIn('arcadmin', 'admin');
    $this->getJson('/node/carol/api/apps')->assertOk();
});

it('creates an app with the wizard values', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-node-app')->returns();
    $this->fake->on('v-list-node-app', ['bob', 'shop.bob.example', 'json'])->returnsJson(['shop.bob.example' => nodeApp('shop.bob.example')]);

    $this->postJson('/node/bob/api/apps', [
        'domain' => 'Shop.Bob.Example', 'start' => 'npm:start', 'version' => '24.21.0', 'port' => 'auto',
        'cwd' => 'app', 'instances' => 1, 'template' => 'nextjs', 'name' => 'shop',
    ])->assertCreated()->assertJsonPath('app.domain', 'shop.bob.example');
    $this->fake->assertRan('v-add-node-app', fn ($a) => $a === ['bob', 'shop.bob.example', 'npm:start', '24.21.0', 'auto', 'app', '1', 'nextjs', 'shop']);

    $this->postJson('/node/bob/api/apps', ['domain' => 'shop.bob.example'])->assertCreated();
    $this->fake->assertRan('v-add-node-app', fn ($a) => $a === ['bob', 'shop.bob.example', '', 'default', 'auto', 'public_html', '1', 'nodejs-proxy', '']);

    foreach ([
        ['domain' => 'shop.bob.example', 'start' => '../../etc/passwd'],
        ['domain' => 'shop.bob.example', 'start' => 'npm:start;id'],
        ['domain' => 'shop.bob.example', 'cwd' => '/etc'],
        ['domain' => 'shop.bob.example', 'version' => 'lts'],
        ['domain' => 'shop.bob.example', 'port' => '80;'],
        ['domain' => 'shop.bob.example', 'name' => 'Bad Name'],
        ['domain' => 'a b'],
    ] as $input) {
        $this->postJson('/node/bob/api/apps', $input)->assertUnprocessable();
    }
});

it('passes command refusals on with the error contract', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-node-app')->returnsExit(ExitCode::Limit, 'Error: the package of bob allows 3 Node.js apps (NODE_APPS)');

    $this->postJson('/node/bob/api/apps', ['domain' => 'shop.bob.example'])->assertStatus(429)
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'Error: the package of bob allows 3 Node.js apps (NODE_APPS)');
});

it('runs the lifecycle actions and changes the settings', function () {
    signIn('bob', 'user');
    foreach (['v-start-node-app', 'v-stop-node-app', 'v-restart-node-app', 'v-reload-node-app', 'v-change-node-app-start',
        'v-change-node-app-version', 'v-change-node-app-scale', 'v-delete-node-app'] as $command) {
        $this->fake->on($command)->returns();
    }

    foreach (['start', 'stop', 'restart', 'reload'] as $action) {
        $this->postJson("/node/bob/api/apps/api.bob.example/$action")->assertOk()->assertJsonPath('app.domain', 'api.bob.example');
        $this->fake->assertRan("v-$action-node-app", fn ($a) => $a === ['bob', 'api.bob.example']);
    }
    $this->postJson('/node/bob/api/apps/api.bob.example/kill')->assertNotFound();

    $this->putJson('/node/bob/api/apps/api.bob.example/start', ['start' => 'dist/server.js', 'cwd' => '.'])->assertOk();
    $this->fake->assertRan('v-change-node-app-start', fn ($a) => $a === ['bob', 'api.bob.example', 'dist/server.js', '.']);
    $this->putJson('/node/bob/api/apps/api.bob.example/version', ['version' => 'default'])->assertOk();
    $this->fake->assertRan('v-change-node-app-version', fn ($a) => $a === ['bob', 'api.bob.example', 'default']);
    $this->putJson('/node/bob/api/apps/api.bob.example/scale', ['instances' => 2, 'memory' => 768])->assertOk();
    $this->fake->assertRan('v-change-node-app-scale', fn ($a) => $a === ['bob', 'api.bob.example', '2', '768']);
    $this->putJson('/node/bob/api/apps/api.bob.example/scale', ['instances' => 0, 'memory' => 768])->assertUnprocessable();

    $this->deleteJson('/node/bob/api/apps/api.bob.example', ['keep_files' => true])->assertOk()->assertJsonPath('account.user', 'bob');
    $this->fake->assertRan('v-delete-node-app', fn ($a) => $a === ['bob', 'api.bob.example', 'keep-files']);
    $this->deleteJson('/node/bob/api/apps/api.bob.example')->assertOk();
    $this->fake->assertRan('v-delete-node-app', fn ($a) => $a === ['bob', 'api.bob.example']);
});

it('saves the environment through a temp file and refuses panel keys', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-node-app-env')->returns();
    $this->fake->on('v-list-node-app-env', ['bob', 'api.bob.example', 'json'])->returnsJson(['VARS' => [['KEY' => 'API_KEY', 'VALUE' => 's3cr3t']]]);

    $this->getJson('/node/bob/api/apps/api.bob.example/env')->assertOk()->assertJsonPath('vars.0', ['key' => 'API_KEY', 'value' => 's3cr3t']);
    $this->putJson('/node/bob/api/apps/api.bob.example/env', ['vars' => [['key' => 'API_KEY', 'value' => 's3cr3t'], ['key' => 'EMPTY', 'value' => '']]])->assertOk();
    $this->fake->assertRan('v-change-node-app-env', fn ($a) => $a === ['bob', 'api.bob.example', "API_KEY=s3cr3t\nEMPTY=\n"]);

    foreach ([[['key' => 'PORT', 'value' => '1']], [['key' => '1BAD', 'value' => 'x']], [['key' => 'A', 'value' => "x\ny"]],
        [['key' => 'A', 'value' => '1'], ['key' => 'A', 'value' => '2']]] as $vars) {
        $this->putJson('/node/bob/api/apps/api.bob.example/env', ['vars' => $vars])->assertUnprocessable();
    }
});

it('reads logs by stream and offset', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-node-app-logs', ['bob', 'api.bob.example', 'out', '', '200', 'json'])->returnsJson(['STREAM' => 'out', 'SIZE' => '20', 'OFFSET' => '20', 'LINES' => ['request /']]);
    $this->fake->on('v-list-node-app-logs', ['bob', 'api.bob.example', 'err', '20', '50', 'json'])->returnsJson(['STREAM' => 'err', 'SIZE' => '40', 'OFFSET' => '40', 'LINES' => ['boom']]);

    $this->getJson('/node/bob/api/apps/api.bob.example/logs')->assertOk()->assertJsonPath('offset', 20)->assertJsonPath('lines', ['request /']);
    $this->getJson('/node/bob/api/apps/api.bob.example/logs?stream=err&offset=20&lines=50')->assertOk()->assertJsonPath('lines', ['boom']);
    $this->getJson('/node/bob/api/apps/api.bob.example/logs?stream=secrets')->assertUnprocessable();
    $this->getJson('/node/bob/api/apps/api.bob.example/logs?lines=5000')->assertUnprocessable();
});

it('starts install and build tasks', function () {
    signIn('bob', 'user');
    $this->fake->on('v-start-user-node-task')->returns('17286400004321');

    $this->postJson('/node/bob/api/apps/api.bob.example/install')->assertStatus(202)->assertJsonPath('id', '17286400004321');
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'app-install', 'api.bob.example']);
    $this->postJson('/node/bob/api/apps/api.bob.example/build', ['script' => 'build:prod'])->assertStatus(202);
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'app-build', 'api.bob.example', 'build:prod']);
    $this->postJson('/node/bob/api/apps/api.bob.example/build')->assertStatus(202);
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'app-build', 'api.bob.example', 'build']);
    $this->postJson('/node/bob/api/apps/api.bob.example/build', ['script' => 'rm -rf'])->assertUnprocessable();
});

it('offers the free domains, the runtimes and the Node.js templates to the wizard', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-web-templates-meta', ['proxy', 'json'])->returnsFixture('v-list-web-templates-meta/proxy.json');
    $this->fake->on('v-list-web-domains', ['bob', 'json'])->returnsJson([
        'api.bob.example' => ['SUSPENDED' => 'no'], 'shop.bob.example' => ['SUSPENDED' => 'no'], 'old.bob.example' => ['SUSPENDED' => 'yes'],
    ]);
    $this->fake->on('v-list-user-node-versions', ['bob', 'json'])->returnsJson(nodeAccount('bob', [], [nodeRuntime('24.21.0', ['DEFAULT' => 'yes'])]));

    $response = $this->getJson('/node/bob/api/apps/options')->assertOk()
        ->assertJsonPath('domains', ['shop.bob.example'])
        ->assertJsonPath('default', '24.21.0')
        ->assertJsonPath('runtimes.0.version', '24.21.0');
    expect(collect($response->json('templates'))->pluck('name')->all())->toContain('nodejs-proxy')->not->toContain('default');
});

it('proposes the start command and the template from package.json', function () {
    signIn('bob', 'user');
    $this->fake->on('v-open-fs-file', ['bob', '/home/bob/web/shop.bob.example/public_html/package.json'])
        ->returns(json_encode(['scripts' => ['start' => 'next start', 'build' => 'next build'], 'dependencies' => ['next' => '15']]));
    $this->fake->on('v-open-fs-file', ['bob', '/home/bob/web/shop.bob.example/api/package.json'])->returnsExit(2, 'Error: file not found');

    $this->getJson('/node/bob/api/apps/detect/shop.bob.example')->assertOk()
        ->assertJson(['packageJson' => true, 'start' => 'npm:start', 'template' => 'nextjs', 'scripts' => ['start', 'build']]);
    $this->getJson('/node/bob/api/apps/detect/shop.bob.example?cwd=api')->assertOk()
        ->assertJson(['packageJson' => false, 'start' => 'server.js', 'template' => 'nodejs-proxy']);
    $this->getJson('/node/bob/api/apps/detect/shop.bob.example?cwd=../..')->assertUnprocessable();
});

it('refuses changes in read-only mode', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes', 'WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->getJson('/node/bob/api/apps')->assertOk()->assertJsonPath('abilities', ['manage' => false, 'remove' => false]);
    $this->postJson('/node/bob/api/apps/api.bob.example/stop')->assertForbidden();
    $this->fake->assertNotRan('v-stop-node-app');
});
