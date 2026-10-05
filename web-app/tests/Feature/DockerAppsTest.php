<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;

// Docker apps: JSON routes of the island (E4 spec §6). hostco owns bob (on
// the developer package, with Docker apps and one app), resb owns carol
// (default package, without Docker apps, with an app left from before).

beforeEach(function () {
    $this->fake = fakeArc(['DOCKER_SYSTEM' => 'yes', 'WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['DOCKER' => 'yes', 'DOCKER_APPS' => '3']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['DOCKER' => 'no']]);
    $this->fake->on('v-list-docker-apps', ['bob', 'json'])->returnsJson(dockerAppList('bob', [dockerApp('kuma')]));
    $this->fake->on('v-list-docker-apps', ['carol', 'json'])->returnsJson(dockerAppList('carol', [dockerApp('old', ['STATUS' => 'failed'])], ['DOCKER' => 'no']));
    $this->fake->on('v-list-docker-app', ['bob', 'kuma', 'json'])->returnsJson(dockerApp('kuma', ['URL' => 'https://kuma.example', 'SSL' => 'yes']));
    $this->fake->on('v-list-docker-app', ['carol', 'old', 'json'])->returnsJson(dockerApp('old'));
    $this->fake->on('v-list-docker-app', ['bob', 'none', 'json'])->returnsExit(ExitCode::NotExist, 'Error: bob has no Docker app none');
    $this->fake->on('v-list-docker-catalog', ['all', 'json'])->returnsJson([
        dockerCatalogRow('uptime-kuma'), dockerCatalogRow('portainer', ['ADMIN_ONLY' => true]), dockerCatalogRow('redis', ['WEB' => false]),
    ]);
    $this->fake->on('v-list-docker-app-task')->returnsJson(dockerTask());
});

it('lists the apps of an account with totals and abilities', function () {
    signIn('bob', 'user');

    $this->getJson('/docker/bob/api/apps')->assertOk()
        ->assertJsonPath('account.count', 1)
        ->assertJsonPath('account.hasRoom', true)
        ->assertJsonPath('account.totals.running', 1)
        ->assertJsonPath('account.apps.0.name', 'kuma')
        ->assertJsonPath('abilities', ['manage' => true, 'remove' => true, 'engine' => true, 'available' => true]);

    $this->getJson('/docker/bob/api/apps/kuma')->assertOk()
        ->assertJsonPath('app.url', 'https://kuma.example')
        ->assertJsonPath('app.ssl', true);
    $this->getJson('/docker/bob/api/apps/none')->assertNotFound();
    $this->getJson('/docker/bob/api/apps/Bad_Name')->assertNotFound();
});

it('answers 404 outside the scope and 403 without Docker apps in the package', function () {
    signIn('bob', 'user');
    $this->getJson('/docker/carol/api/apps')->assertNotFound();
    $this->postJson('/docker/carol/api/apps/old/start')->assertNotFound();

    signIn('hostco', 'reseller');
    $this->getJson('/docker/bob/api/apps')->assertOk();
    $this->getJson('/docker/carol/api/apps')->assertNotFound();

    signIn('resb', 'reseller');
    $this->getJson('/docker/bob/api/apps/kuma')->assertNotFound();
    $this->getJson('/docker/carol/api/apps')->assertOk()->assertJsonPath('abilities.manage', false)->assertJsonPath('abilities.remove', true);

    // carol's package has no Docker apps: no installing or starting, stopping and deleting stay
    signIn('carol', 'user');
    $this->fake->on('v-stop-docker-app')->returns();
    $this->fake->on('v-delete-docker-app')->returns();
    $this->postJson('/docker/carol/api/apps', ['slug' => 'uptime-kuma', 'name' => 'kuma', 'domain' => 'a.carol.example'])->assertForbidden();
    $this->postJson('/docker/carol/api/apps/old/start')->assertForbidden();
    $this->putJson('/docker/carol/api/apps/old/env', ['vars' => []])->assertForbidden();
    $this->postJson('/docker/carol/api/apps/old/tasks', ['action' => 'update'])->assertForbidden();
    $this->postJson('/docker/carol/api/apps/old/stop')->assertOk();
    $this->deleteJson('/docker/carol/api/apps/old')->assertOk();

    signIn('arcadmin', 'admin');
    $this->getJson('/docker/carol/api/apps')->assertOk();
});

it('shows the store without admin-only apps for others', function () {
    signIn('bob', 'user');
    $this->getJson('/docker/bob/api/catalog')->assertOk()
        ->assertJsonCount(2, 'apps')
        ->assertJsonPath('apps.0.slug', 'uptime-kuma')
        ->assertJsonPath('apps.1.web', false);
    $this->fake->on('v-list-docker-catalog-app', ['portainer', 'latest', 'json'])->returnsJson(['slug' => 'portainer', 'admin_only' => true]);
    $this->getJson('/docker/bob/api/catalog/portainer')->assertNotFound();

    signIn('arcadmin', 'admin');
    $this->getJson('/docker/arcadmin/api/catalog')->assertOk()->assertJsonCount(3, 'apps');
    $this->getJson('/docker/arcadmin/api/catalog/portainer')->assertOk()->assertJsonPath('app.adminOnly', true);
});

it('shows a catalog app with its variables', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-docker-catalog-app', ['uptime-kuma', 'latest', 'json'])->returnsJson([
        'slug' => 'uptime-kuma', 'name' => 'Uptime Kuma', 'version' => '2.5.5', 'versions' => ['2.5.5'], 'latest' => '2.5.5',
        'ports' => ['web' => ['service' => 'app', 'container' => 3001]], 'requirements' => ['memory' => '256M', 'disk' => '1G'],
        'variables' => [['name' => 'TZ', 'label' => 'Time zone', 'type' => 'string', 'default' => 'UTC', 'required' => true, 'secret' => false]],
    ]);

    $this->getJson('/docker/bob/api/catalog/uptime-kuma')->assertOk()
        ->assertJsonPath('app.web', true)
        ->assertJsonPath('app.variables.0.name', 'TZ')
        ->assertJsonPath('app.variables.0.default', 'UTC')
        ->assertJsonPath('app.requirements.memory', '256M');
    $this->getJson('/docker/bob/api/catalog/uptime-kuma?version=../x')->assertUnprocessable();
});

it('installs an app with variables through a temp file', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-docker-app')->returns("17286400001234\n");

    $this->postJson('/docker/bob/api/apps', [
        'slug' => 'uptime-kuma', 'name' => 'kuma', 'domain' => 'Status.Bob.Example', 'vars' => ['TZ' => 'Europe/Berlin', 'EMPTY' => ''],
    ])->assertStatus(202)->assertJsonPath('id', '17286400001234')->assertJsonPath('app.name', 'kuma');
    $this->fake->assertRan('v-add-docker-app', fn ($a) => $a === ['bob', 'uptime-kuma', 'kuma', 'status.bob.example', "TZ=Europe/Berlin\n", '']);

    foreach ([
        ['slug' => 'uptime-kuma', 'name' => 'Bad Name'],
        ['slug' => 'uptime-kuma', 'name' => 'kuma-'],
        ['slug' => '../x', 'name' => 'kuma'],
        ['slug' => 'uptime-kuma', 'name' => 'kuma', 'domain' => 'a b'],
        ['slug' => 'uptime-kuma', 'name' => 'kuma', 'vars' => ['TZ' => "it's"]],
        ['slug' => 'uptime-kuma', 'name' => 'kuma', 'vars' => ['TZ' => 'a$b']],
        ['slug' => 'uptime-kuma', 'name' => 'kuma', 'vars' => ['ARC_UID' => '0']],
        ['slug' => 'uptime-kuma', 'name' => 'kuma', 'vars' => ['lower' => 'x']],
        ['slug' => 'uptime-kuma', 'name' => 'kuma', 'version' => '1.0;id'],
    ] as $input) {
        $this->postJson('/docker/bob/api/apps', $input)->assertUnprocessable();
    }
    $this->postJson('/docker/bob/api/apps', ['slug' => 'nothing', 'name' => 'x1'])->assertNotFound();
    $this->postJson('/docker/bob/api/apps', ['slug' => 'portainer', 'name' => 'port'])->assertForbidden();
});

it('passes command refusals on with the error contract', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-docker-app')->returnsExit(ExitCode::Limit, 'Error: the package of bob allows 3 Docker apps (DOCKER_APPS)');

    $this->postJson('/docker/bob/api/apps', ['slug' => 'uptime-kuma', 'name' => 'kuma2', 'domain' => 'x.bob.example'])->assertStatus(429)
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'Error: the package of bob allows 3 Docker apps (DOCKER_APPS)');
});

it('runs the lifecycle actions, the tasks and the settings', function () {
    signIn('bob', 'user');
    foreach (['v-start-docker-app', 'v-stop-docker-app', 'v-restart-docker-app', 'v-change-docker-app-limits',
        'v-change-docker-app-domain', 'v-delete-docker-app'] as $command) {
        $this->fake->on($command)->returns();
    }
    $this->fake->on('v-start-docker-app-task')->returns("1234\n");

    foreach (['start', 'stop', 'restart'] as $action) {
        $this->postJson("/docker/bob/api/apps/kuma/$action")->assertOk()->assertJsonPath('app.name', 'kuma');
        $this->fake->assertRan("v-$action-docker-app", fn ($a) => $a === ['bob', 'kuma']);
    }
    $this->postJson('/docker/bob/api/apps/kuma/kill')->assertNotFound();

    $this->postJson('/docker/bob/api/apps/kuma/tasks', ['action' => 'update', 'version' => '2.6.0'])->assertStatus(202)->assertJsonPath('id', '1234');
    $this->fake->assertRan('v-start-docker-app-task', fn ($a) => $a === ['bob', 'kuma', 'update', '2.6.0']);
    $this->postJson('/docker/bob/api/apps/kuma/tasks', ['action' => 'rollback'])->assertStatus(202);
    $this->fake->assertRan('v-start-docker-app-task', fn ($a) => $a === ['bob', 'kuma', 'rollback']);
    $this->postJson('/docker/bob/api/apps/kuma/tasks', ['action' => 'shell'])->assertUnprocessable();

    $this->putJson('/docker/bob/api/apps/kuma/limits', ['cpus' => '1.5', 'memory' => 768])->assertOk();
    $this->fake->assertRan('v-change-docker-app-limits', fn ($a) => $a === ['bob', 'kuma', '1.5', '768']);
    $this->putJson('/docker/bob/api/apps/kuma/limits', ['cpus' => 'all', 'memory' => 768])->assertUnprocessable();
    $this->putJson('/docker/bob/api/apps/kuma/domain', ['domain' => 'New.Bob.Example'])->assertOk();
    $this->fake->assertRan('v-change-docker-app-domain', fn ($a) => $a === ['bob', 'kuma', 'new.bob.example']);

    $this->deleteJson('/docker/bob/api/apps/kuma', ['keep_data' => true])->assertOk()->assertJsonPath('account.user', 'bob');
    $this->fake->assertRan('v-delete-docker-app', fn ($a) => $a === ['bob', 'kuma', 'keep-data']);
    $this->deleteJson('/docker/bob/api/apps/kuma')->assertOk();
    $this->fake->assertRan('v-delete-docker-app', fn ($a) => $a === ['bob', 'kuma']);
});

it('reads variables without secrets and saves them through a temp file', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-docker-app-env')->returns();
    $this->fake->on('v-list-docker-app-env', ['bob', 'kuma', 'json'])->returnsJson(['VARIABLES' => [
        ['NAME' => 'TZ', 'LABEL' => 'Time zone', 'TYPE' => 'string', 'SECRET' => 'no', 'SET' => 'yes', 'VALUE' => 'UTC'],
        ['NAME' => 'TOKEN', 'LABEL' => 'Token', 'TYPE' => 'password', 'SECRET' => 'yes', 'SET' => 'yes', 'VALUE' => ''],
    ]]);

    $this->getJson('/docker/bob/api/apps/kuma/env')->assertOk()
        ->assertJsonPath('variables.0.value', 'UTC')
        ->assertJsonPath('variables.1.secret', true)
        ->assertJsonPath('variables.1.value', '');
    $this->putJson('/docker/bob/api/apps/kuma/env', ['vars' => ['TZ' => 'Europe/Berlin', 'TOKEN' => '']])->assertOk();
    $this->fake->assertRan('v-change-docker-app-env', fn ($a) => $a === ['bob', 'kuma', "TZ=Europe/Berlin\nTOKEN=\n"]);
    $this->putJson('/docker/bob/api/apps/kuma/env', ['vars' => ['TZ' => "a\nb"]])->assertUnprocessable();
});

it('reads logs with a cursor and task output with an offset', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-docker-app-logs', ['bob', 'kuma', 'all', '', '200', 'json'])->returnsJson([
        'LINES' => [['TIME' => '2026-10-05T10:00:00.1Z', 'SERVICE' => 'app', 'TEXT' => 'ready']], 'NEXT' => '2026-10-05T10:00:00.1Z',
    ]);
    $this->fake->on('v-list-docker-app-logs', ['bob', 'kuma', 'app', '2026-10-05T10:00:00.1Z', '50', 'json'])->returnsJson(['LINES' => [], 'NEXT' => '']);
    $this->fake->on('v-list-docker-app-task', ['bob', 'kuma', '120', 'json'])->returnsJson(dockerTask(['STATUS' => 'running', 'LOG' => 'pulling', 'OFFSET' => '127']));

    $this->getJson('/docker/bob/api/apps/kuma/logs')->assertOk()
        ->assertJsonPath('lines.0', ['time' => '2026-10-05T10:00:00.1Z', 'service' => 'app', 'text' => 'ready'])
        ->assertJsonPath('next', '2026-10-05T10:00:00.1Z');
    $this->getJson('/docker/bob/api/apps/kuma/logs?service=app&since=2026-10-05T10:00:00.1Z&lines=50')->assertOk()->assertJsonPath('lines', []);
    $this->getJson('/docker/bob/api/apps/kuma/logs?since=yesterday')->assertUnprocessable();
    $this->getJson('/docker/bob/api/apps/kuma/logs?service=../x')->assertUnprocessable();
    $this->getJson('/docker/bob/api/apps/kuma/task?offset=120')->assertOk()
        ->assertJsonPath('task.running', true)
        ->assertJsonPath('task.log', 'pulling')
        ->assertJsonPath('task.offset', 127);
});

it('offers the free domains of an account', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-web-domains', ['bob', 'json'])->returnsJson([
        'kuma.example' => ['SUSPENDED' => 'no'], 'free.example' => ['SUSPENDED' => 'no'], 'off.example' => ['SUSPENDED' => 'yes'],
        'node.example' => ['SUSPENDED' => 'no'],
    ]);
    $this->fake->on('v-list-node-apps', ['bob', 'json'])->returnsJson(nodeAppList('bob', [nodeApp('node.example')]));

    $this->getJson('/docker/bob/api/options')->assertOk()
        ->assertJsonPath('domains', ['free.example'])
        ->assertJsonPath('names', ['kuma'])
        ->assertJsonPath('hasRoom', true);
});

it('lets administrators install the engine', function () {
    $this->fake->on('v-list-sys-docker', ['json'])->returnsJson(['SYSTEM' => 'no', 'RUNNING' => 'no', 'REPORT' => []]);
    $this->fake->on('v-list-sys-docker-task', ['0', 'json'])->returnsJson(dockerTask(['STATUS' => 'running', 'ACTION' => 'install']));
    $this->fake->on('v-start-sys-docker-task')->returns("99\n");

    signIn('hostco', 'reseller');
    $this->getJson('/server/docker/api/status')->assertForbidden();
    $this->postJson('/server/docker/api/install')->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->getJson('/server/docker/api/status')->assertOk()->assertJsonPath('status.installed', false);
    $this->postJson('/server/docker/api/install')->assertStatus(202)->assertJsonPath('id', '99')->assertJsonPath('task.running', true);
    $this->fake->assertRan('v-start-sys-docker-task', fn ($a) => $a === []);
});

it('has capabilities for the Docker routes', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map->getName()
        ->filter(fn ($n) => is_string($n) && (str_starts_with($n, 'docker.') || str_starts_with($n, 'server.docker')));
    expect($names->count())->toBeGreaterThanOrEqual(20);
    foreach (['docker.apps.view', 'docker.apps.manage', 'docker.engine.manage'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});
