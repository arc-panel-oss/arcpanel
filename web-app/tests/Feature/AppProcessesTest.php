<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\Scope;
use Illuminate\Support\Facades\Route;

// App processes: JSON routes of the island (E42 spec §8). hostco owns bob
// (developer package, with app processes and a web process), resb owns carol
// (default package, without the grant, with a worker left from before).

beforeEach(function () {
    $this->fake = fakeArc(['WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['APP_PROCESSES' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['APP_PROCESSES' => 'no']]);
    $this->fake->on('v-list-app-processes', ['bob', 'json'])->returnsJson(appProcessList('bob', [appProcessRow('shop.bob.example')]));
    $this->fake->on('v-list-app-processes', ['carol', 'json'])->returnsJson(appProcessList('carol', [appProcessRow('old.carol.example', 'queue', ['STATUS' => 'failed'])], ['ENABLED' => 'no']));
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(appProcessRow('shop.bob.example', 'web', ['RELOAD' => 'command']));
    $this->fake->on('v-list-app-process', ['carol', 'old.carol.example', 'queue', 'json'])->returnsJson(appProcessRow('old.carol.example', 'queue'));
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'none', 'json'])->returnsExit(ExitCode::NotExist, 'Error: shop.bob.example has no app process named none');
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-app-process-task', [$user, 'json'])->returnsJson(['TASK' => null]);
    }
});

it('names a capability for every app process route', function () {
    $names = collect(Route::getRoutes())->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'app-processes.'));
    expect($names)->not->toBeEmpty();
    expect(Capabilities::names())->toContain('apps.processes.view', 'apps.processes.manage');
    expect(Capabilities::scopeOf(Role::User, 'apps.processes.manage'))->toBe(Scope::Self)
        ->and(Capabilities::scopeOf(Role::Reseller, 'apps.processes.view'))->toBe(Scope::Tree);
});

it('lists the processes of an account with totals and abilities', function () {
    signIn('bob', 'user');

    $this->getJson('/app-processes/bob/api')->assertOk()
        ->assertJsonPath('account.count', 1)
        ->assertJsonPath('account.allowed', true)
        ->assertJsonPath('account.hasRoom', true)
        ->assertJsonPath('account.totals.running', 1)
        ->assertJsonPath('account.processes.0.domain', 'shop.bob.example')
        ->assertJsonPath('account.processes.0.kind', 'web')
        ->assertJsonPath('task', null)
        ->assertJsonPath('abilities', ['manage' => true, 'remove' => true]);

    $this->getJson('/app-processes/bob/api/shop.bob.example/web')->assertOk()
        ->assertJsonPath('process.reload', 'command')
        ->assertJsonPath('process.port', '30001');
    $this->getJson('/app-processes/bob/api/shop.bob.example/none')->assertNotFound();
});

it('answers 404 outside the scope and 403 without the grant in the package', function () {
    signIn('bob', 'user');
    $this->getJson('/app-processes/carol/api')->assertNotFound();
    $this->postJson('/app-processes/carol/api/old.carol.example/queue/start')->assertNotFound();

    signIn('hostco', 'reseller');
    $this->getJson('/app-processes/bob/api')->assertOk();
    $this->getJson('/app-processes/carol/api')->assertNotFound();

    signIn('resb', 'reseller');
    $this->getJson('/app-processes/bob/api/shop.bob.example/web')->assertNotFound();
    $this->getJson('/app-processes/carol/api')->assertOk()->assertJsonPath('abilities', ['manage' => false, 'remove' => true]);

    // carol's package lost the grant: no creating or starting, stopping and deleting stay
    signIn('carol', 'user');
    $this->fake->on('v-stop-app-process')->returns();
    $this->fake->on('v-delete-app-process')->returns();
    $this->postJson('/app-processes/carol/api', ['domain' => 'old.carol.example', 'name' => 'web', 'preset' => 'octane-swoole'])->assertForbidden();
    $this->postJson('/app-processes/carol/api/old.carol.example/queue/start')->assertForbidden();
    $this->putJson('/app-processes/carol/api/old.carol.example/queue/env', ['vars' => []])->assertForbidden();
    $this->postJson('/app-processes/carol/api/old.carol.example/queue/stop')->assertOk();
    $this->fake->assertRan('v-stop-app-process', fn ($a) => $a === ['carol', 'old.carol.example', 'queue']);
    $this->deleteJson('/app-processes/carol/api/old.carol.example/queue')->assertOk();
    $this->fake->assertRan('v-delete-app-process', fn ($a) => $a === ['carol', 'old.carol.example', 'queue']);

    signIn('arcadmin', 'admin');
    $this->getJson('/app-processes/carol/api')->assertOk();
});

it('creates a process with the wizard values', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-app-process')->returns();
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'queue', 'json'])->returnsJson(appProcessRow('shop.bob.example', 'queue'));
    $this->fake->on('v-list-app-process', ['bob', 'api.bob.example', 'web', 'json'])->returnsJson(appProcessRow('api.bob.example'));

    $this->postJson('/app-processes/bob/api', [
        'domain' => 'Shop.Bob.Example', 'name' => 'queue', 'preset' => 'laravel-queue', 'cwd' => 'app', 'memory_mb' => 256, 'cpus' => '0.5',
    ])->assertCreated()->assertJsonPath('process.name', 'queue');
    $this->fake->assertRan('v-add-app-process', fn ($a) => $a === ['bob', 'shop.bob.example', 'queue', 'laravel-queue', '', 'app', '256', '0.5']);

    $this->postJson('/app-processes/bob/api', [
        'domain' => 'api.bob.example', 'name' => 'web', 'preset' => 'custom', 'start' => 'python3 -m http.server %port%',
        'kind' => 'web', 'template' => 'flask-fastapi',
    ])->assertCreated();
    $this->fake->assertRan('v-add-app-process', fn ($a) => $a === ['bob', 'api.bob.example', 'web', 'custom', 'python3 -m http.server %port%', 'public_html', '', '1', 'web', 'flask-fastapi']);
});

it('validates the wizard values before any command', function (array $body, string $field) {
    signIn('bob', 'user');
    $this->postJson('/app-processes/bob/api', array_merge(['domain' => 'shop.bob.example', 'name' => 'web', 'preset' => 'custom', 'start' => 'true'], $body))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->fake->assertNotRan('v-add-app-process');
})->with([
    'name' => [['name' => 'Bad_Name'], 'name'],
    'preset' => [['preset' => '../x'], 'preset'],
    'custom without a command' => [['start' => null], 'start'],
    'line break in the command' => [['start' => "true\nreboot"], 'start'],
    'cwd with ..' => [['cwd' => '../etc'], 'cwd'],
    'cpus' => [['cpus' => 'lots'], 'cpus'],
    'web without a template' => [['kind' => 'web'], 'template'],
]);

it('runs the lifecycle actions and the settings', function () {
    signIn('bob', 'user');
    foreach (['start', 'stop', 'restart', 'reload'] as $action) {
        $this->fake->on('v-'.$action.'-app-process')->returns();
        $this->postJson('/app-processes/bob/api/shop.bob.example/web/'.$action)->assertOk()->assertJsonPath('process.name', 'web');
        $this->fake->assertRan('v-'.$action.'-app-process', fn ($a) => $a === ['bob', 'shop.bob.example', 'web']);
    }
    $this->postJson('/app-processes/bob/api/shop.bob.example/web/explode')->assertNotFound();

    $this->fake->on('v-change-app-process-start')->returns();
    $this->putJson('/app-processes/bob/api/shop.bob.example/web/start', ['start' => 'php artisan octane:start --port=%port%', 'cwd' => 'app'])->assertOk();
    $this->fake->assertRan('v-change-app-process-start', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', 'php artisan octane:start --port=%port%', 'app']);

    $this->fake->on('v-change-app-process-limits')->returns();
    $this->putJson('/app-processes/bob/api/shop.bob.example/web/limits', ['memory_mb' => 640, 'cpus' => '1.5'])->assertOk();
    $this->fake->assertRan('v-change-app-process-limits', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', '640', '1.5']);
});

it('saves the environment through a temp file and refuses the panel keys', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-app-process-env', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(['VARS' => [['KEY' => 'APP_ENV', 'VALUE' => 'production']]]);
    $this->fake->on('v-change-app-process-env')->returns();

    $this->getJson('/app-processes/bob/api/shop.bob.example/web/env')->assertOk()->assertJsonPath('vars.0.key', 'APP_ENV');
    $this->putJson('/app-processes/bob/api/shop.bob.example/web/env', ['vars' => [
        ['key' => 'APP_ENV', 'value' => 'production'],
        ['key' => 'DB_PASSWORD', 'value' => '{{secret:/shop/db}}'],
    ]])->assertOk();
    $this->fake->assertRan('v-change-app-process-env', function ($a) {
        return $a[0] === 'bob' && $a[1] === 'shop.bob.example' && $a[2] === 'web'
            && ! in_array('APP_ENV=production', $a, true) && count($a) === 4;
    });

    $this->putJson('/app-processes/bob/api/shop.bob.example/web/env', ['vars' => [['key' => 'PORT', 'value' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('vars.0.key');
});

it('reads logs by offset and starts tasks', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-app-process-logs', ['bob', 'shop.bob.example', 'web', 'process', '120', '200', 'json'])
        ->returnsJson(['STREAM' => 'process', 'SIZE' => '200', 'OFFSET' => '200', 'LINES' => ['ready']]);
    $this->getJson('/app-processes/bob/api/shop.bob.example/web/logs?offset=120')->assertOk()
        ->assertJsonPath('lines', ['ready'])->assertJsonPath('offset', 200);
    $this->getJson('/app-processes/bob/api/shop.bob.example/web/logs?stream=out')->assertUnprocessable();

    $this->fake->on('v-start-app-process-task')->returns("17606214250042\n");
    $this->postJson('/app-processes/bob/api/shop.bob.example/web/tasks', ['task' => 'migrate'])->assertStatus(202)
        ->assertJsonPath('id', '17606214250042');
    $this->fake->assertRan('v-start-app-process-task', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', 'migrate']);
    $this->postJson('/app-processes/bob/api/shop.bob.example/web/tasks', ['task' => 'custom', 'command' => 'php artisan optimize'])->assertStatus(202);
    $this->fake->assertRan('v-start-app-process-task', fn ($a) => $a === ['bob', 'shop.bob.example', 'web', 'custom', 'php artisan optimize']);
    $this->postJson('/app-processes/bob/api/shop.bob.example/web/tasks', ['task' => 'rm-rf'])->assertUnprocessable();
});

it('refuses changes in read-only mode', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes', 'WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->getJson('/app-processes/bob/api')->assertOk()->assertJsonPath('abilities', ['manage' => false, 'remove' => false]);
    $this->postJson('/app-processes/bob/api/shop.bob.example/web/stop')->assertForbidden();
    $this->fake->assertNotRan('v-stop-app-process');
});
