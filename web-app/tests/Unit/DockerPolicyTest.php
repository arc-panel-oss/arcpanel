<?php

use App\Arc\ArcCommand;
use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\DockerPolicy;
use App\Policies\Scope;
use App\Services\Docker\DockerAccess;
use App\Services\Docker\DockerApp;
use App\Services\Docker\DockerApps;
use App\Services\Docker\DockerAppService;
use App\Tenancy\TenancyDirectory;

// DockerPolicy, the app records and DockerAppService patterns (E4 spec §6):
// hostco owns bob (developer package with Docker apps), resb owns carol
// (default package without them).

beforeEach(function () {
    $this->fake = fakeArc(['DOCKER_SYSTEM' => 'yes']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['DOCKER' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['DOCKER' => 'no']]);
});

function dockerPolicy(Principal $principal): DockerPolicy
{
    return new DockerPolicy(new OwnerScope($principal, new TenancyDirectory(app(ArcCommand::class))),
        new DockerAccess(app(ArcCommand::class), app(SysConfig::class)));
}

it('gives every role Docker apps on the accounts it can see and the engine to administrators', function () {
    foreach ([[Role::Admin, Scope::All], [Role::Reseller, Scope::Tree], [Role::User, Scope::Self]] as [$role, $scope]) {
        expect(Capabilities::scopeOf($role, 'docker.apps.view'))->toBe($scope)
            ->and(Capabilities::scopeOf($role, 'docker.apps.manage'))->toBe($scope);
    }
    expect(Capabilities::scopeOf(Role::Admin, 'docker.engine.manage'))->toBe(Scope::All)
        ->and(Capabilities::scopeOf(Role::Reseller, 'docker.engine.manage'))->toBeNull()
        ->and(Capabilities::scopeOf(Role::User, 'docker.engine.manage'))->toBeNull();

    $bob = principal('bob', Role::User);
    $hostco = principal('hostco', Role::Reseller);
    $resb = principal('resb', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);

    expect(dockerPolicy($bob)->viewApps($bob, 'bob'))->toBeTrue()
        ->and(dockerPolicy($bob)->viewApps($bob, 'carol'))->toBeFalse()
        ->and(dockerPolicy($hostco)->manageApps($hostco, 'bob'))->toBeTrue()
        ->and(dockerPolicy($resb)->manageApps($resb, 'bob'))->toBeFalse()
        ->and(dockerPolicy($resb)->removeApps($resb, 'bob'))->toBeFalse()
        ->and(dockerPolicy($resb)->removeApps($resb, 'carol'))->toBeTrue()
        ->and(dockerPolicy($admin)->manageApps($admin, 'bob'))->toBeTrue()
        ->and(dockerPolicy($admin)->manageEngine($admin))->toBeTrue()
        ->and(dockerPolicy($hostco)->manageEngine($hostco))->toBeFalse()
        ->and(dockerPolicy($bob)->installAdminOnly($bob))->toBeFalse()
        ->and(dockerPolicy($admin)->installAdminOnly($admin))->toBeTrue();
});

it('needs DOCKER=yes and the engine to install but not to stop or delete', function () {
    $carol = principal('carol', Role::User);
    $admin = principal('arcadmin', Role::Admin);

    expect(dockerPolicy($carol)->appAbilities($carol, 'carol'))->toBe(['manage' => false, 'remove' => true, 'engine' => true, 'available' => false])
        ->and(dockerPolicy($admin)->manageApps($admin, 'carol'))->toBeFalse();

    // without the engine nobody installs
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['DOCKER_SYSTEM' => 'no']));
    app()->forgetInstance(SysConfig::class);
    cache()->flush();
    $bob = principal('bob', Role::User);
    expect(dockerPolicy($bob)->appAbilities($bob, 'bob'))->toBe(['manage' => false, 'remove' => true, 'engine' => false, 'available' => true]);
});

it('allows no app changes in read-only mode', function () {
    $bob = principal('bob', Role::User, readOnly: true);

    expect(dockerPolicy($bob)->viewApps($bob, 'bob'))->toBeTrue()
        ->and(dockerPolicy($bob)->appAbilities($bob, 'bob'))->toMatchArray(['manage' => false, 'remove' => false]);
});

it('parses the apps with their totals and the room left', function () {
    $apps = DockerApps::from(dockerAppList('bob', [
        dockerApp('kuma'),
        dockerApp('n8n', ['STATUS' => 'stopped', 'STATE' => 'stopped', 'DOCKER_STATE' => 'exited']),
        dockerApp('git', ['STATUS' => 'failed', 'REASON' => 'health']),
    ], ['LIMIT' => '3']));

    expect($apps->hasRoom())->toBeFalse()
        ->and($apps->totals())->toBe(['running' => 1, 'stopped' => 1, 'failed' => 1, 'busy' => 0, 'suspended' => 0])
        ->and($apps->find('kuma')?->running())->toBeTrue()
        ->and($apps->find('git')?->reason)->toBe('health')
        ->and($apps->find('nope'))->toBeNull()
        ->and($apps->domains())->toBe(['kuma.example', 'n8n.example', 'git.example'])
        ->and(DockerApps::from(dockerAppList('bob', [dockerApp('a', ['STATUS' => 'installing'])], ['LIMIT' => 'unlimited']))->totals()['busy'])->toBe(1)
        ->and(DockerApps::from(dockerAppList('bob', [dockerApp('a', ['SUSPENDED' => 'user'])]))->totals()['suspended'])->toBe(1)
        ->and(DockerApps::from(dockerAppList('bob', [], ['LIMIT' => 'unlimited']))->hasRoom())->toBeTrue();

    $app = DockerApp::from(dockerApp('kuma', [
        'SERVICES' => [['SERVICE' => 'app', 'IMAGE' => 'x@sha256:1', 'STATE' => 'running', 'HEALTH' => '', 'STATUS' => 'Up 2 minutes']],
        'PORTS' => [['NAME' => 'web', 'PORT' => '30020', 'PUBLIC' => 'no', 'CONTAINER' => '3001', 'DESCRIPTION' => '']],
        'TASK' => ['ID' => '1', 'STATUS' => 'done'], 'ROLLBACK' => 'yes', 'LAST_DUMP' => '2026-10-05 04:55:01',
    ]))->toArray();
    expect($app['services'][0]['service'])->toBe('app')
        ->and($app['ports'][0])->toBe(['name' => 'web', 'port' => '30020', 'container' => '3001', 'public' => false, 'description' => ''])
        ->and($app['task'])->toBe(['id' => '1', 'status' => 'done'])
        ->and($app['rollback'])->toBeTrue()
        ->and($app['memoryMb'])->toBe(96)
        ->and($app['busy'])->toBeFalse();
});

it('validates names, variables and cursors like func/docker.sh', function () {
    foreach (['kuma', 'n8n', 'my-app-2', 'ab'] as $name) {
        expect(preg_match(DockerAppService::NAME, $name))->toBe(1, $name);
    }
    foreach (['Kuma', '2app', 'a', 'app-', 'a_b', str_repeat('a', 33)] as $name) {
        expect(preg_match(DockerAppService::NAME, $name))->toBe(0, $name);
    }
    expect(preg_match(DockerAppService::VARIABLE, 'ADMIN_TOKEN'))->toBe(1)
        ->and(preg_match(DockerAppService::VARIABLE, 'admin'))->toBe(0);
    foreach (["it's", 'a"b', 'a$b', 'a`b', 'a\\b', "a\nb"] as $value) {
        expect(preg_match(DockerAppService::FORBIDDEN_VALUE, $value))->toBe(1, $value);
    }
    expect(preg_match(DockerAppService::FORBIDDEN_VALUE, 'Europe/Berlin user@example.com https://x.example/a?b=c'))->toBe(0)
        ->and(preg_match(DockerAppService::SINCE, '2026-10-05T10:00:00.123456789Z'))->toBe(1)
        ->and(preg_match(DockerAppService::SINCE, 'yesterday'))->toBe(0)
        ->and(preg_match(DockerAppService::CPUS, '0.5'))->toBe(1)
        ->and(preg_match(DockerAppService::CPUS, '0.5x'))->toBe(0);
});
