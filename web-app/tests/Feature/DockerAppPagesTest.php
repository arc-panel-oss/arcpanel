<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;

// Docker apps pages, navigation, Dashboard card, user detail card and the
// engine page (E4 spec §7). hostco owns bob (developer package
// with Docker apps, one app), resb owns carol (default package without).

beforeEach(function () {
    $this->fake = fakeArc(['DOCKER_SYSTEM' => 'yes', 'WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['DOCKER' => 'yes', 'DOCKER_APPS' => '3']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['DOCKER' => 'no']]);
    $this->fake->on('v-list-docker-apps', ['bob', 'json'])->returnsJson(dockerAppList('bob', [dockerApp('kuma', [
        'EXTRA_PORTS' => '', 'UPDATE' => 'yes', 'LATEST' => '2.6.0',
    ])]));
    $this->fake->on('v-list-docker-app', ['bob', 'kuma', 'json'])->returnsJson(dockerApp('kuma', [
        'PORTS' => [['NAME' => 'web', 'PORT' => '30020', 'PUBLIC' => 'no', 'CONTAINER' => '3001', 'DESCRIPTION' => '']],
        'SERVICES' => [['SERVICE' => 'app', 'IMAGE' => 'louislam/uptime-kuma:2.5.5-rootless@sha256:1', 'STATE' => 'running', 'HEALTH' => '', 'STATUS' => 'Up 1 minute']],
        'CATALOG' => ['name' => 'Uptime Kuma', 'changelog' => 'https://example.com/releases', 'dumps' => false],
    ]));
    $this->fake->on('v-list-docker-app', ['bob', 'none', 'json'])->returnsExit(ExitCode::NotExist, 'Error: bob has no Docker app none');
    $this->fake->on('v-list-docker-app-task')->returnsJson(dockerTask());
    $this->fake->on('v-list-docker-catalog', ['all', 'json'])->returnsJson([
        dockerCatalogRow('uptime-kuma', ['NAME' => 'Uptime Kuma']), dockerCatalogRow('portainer', ['ADMIN_ONLY' => true]),
    ]);
    $this->fake->on('v-list-docker-catalog-app', ['uptime-kuma', 'latest', 'json'])->returnsJson([
        'slug' => 'uptime-kuma', 'name' => 'Uptime Kuma', 'summary' => 'Monitoring.', 'description' => "First.\n\nSecond.",
        'version' => '2.5.5', 'versions' => ['2.5.5'], 'latest' => '2.5.5', 'license' => 'MIT',
        'ports' => ['web' => ['service' => 'app', 'container' => 3001]], 'requirements' => ['memory' => '256M', 'disk' => '1G'],
        'limits' => ['memory' => '512M', 'cpus' => '0.5'],
        'variables' => [['name' => 'TZ', 'label' => 'Time zone', 'type' => 'string', 'default' => 'UTC', 'required' => true, 'secret' => false]],
    ]);
    $this->fake->on('v-list-web-domains', ['bob', 'json'])->returnsJson([
        'kuma.example' => ['SUSPENDED' => 'no'], 'free.bob.example' => ['SUSPENDED' => 'no'],
    ]);
});

function dockerIslandProps(string $html): array
{
    return json_decode(html_entity_decode((string) str($html)->match('/data-island="docker-apps"[^>]*data-props="([^"]*)"/')), true)
        ?? json_decode(html_entity_decode((string) str($html)->match('/data-props="([^"]*)"[^>]*data-island="docker-apps"/')), true);
}

it('shows My apps with the island, the apps and the links', function () {
    signIn('bob', 'user');

    $html = $this->get('/docker/bob/apps')->assertOk()
        ->assertSee('data-island="docker-apps"', false)
        ->assertSee('kuma.example')
        ->assertSee('href="'.route('docker-apps').'"', false)
        ->getContent();
    $props = dockerIslandProps($html);
    expect($props['view'])->toBe('list')
        ->and($props['account']['count'])->toBe(1)
        ->and($props['account']['apps'][0]['update'])->toBeTrue()
        ->and($props['base'])->toEndWith('/docker/bob/api')
        ->and($props['pages']['show'])->toEndWith('/docker/bob/apps/__NAME__')
        ->and($props['pages']['engine'])->toBeNull()
        ->and($props['abilities'])->toBe(['manage' => true, 'remove' => true, 'engine' => true, 'available' => true])
        ->and($props['strings'])->toHaveKey('Open the store');
});

it('sends /docker-apps to the list of the effective account', function () {
    signIn('bob', 'user');
    $this->get('/docker-apps')->assertRedirect('/docker/bob/apps');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/docker-apps')->assertRedirect('/docker/bob/apps');
});

it('shows the store, a catalog app with its form and the page of an app', function () {
    signIn('bob', 'user');

    $props = dockerIslandProps($this->get('/docker/bob/store')->assertOk()->assertSee('Uptime Kuma')->assertDontSee('Portainer')->getContent());
    expect($props['view'])->toBe('store')
        ->and(array_column($props['catalog'], 'slug'))->toBe(['uptime-kuma'])
        ->and($props['installed'])->toBe(['uptime-kuma' => 1]);

    $props = dockerIslandProps($this->get('/docker/bob/store/uptime-kuma')->assertOk()->assertSee('Second.')->getContent());
    expect($props['view'])->toBe('detail')
        ->and($props['catalogApp']['variables'][0]['name'])->toBe('TZ')
        ->and($props['domains'])->toBe(['free.bob.example'])
        ->and($props['names'])->toBe(['kuma'])
        ->and($props['hasRoom'])->toBeTrue();
    $this->get('/docker/bob/store/portainer')->assertNotFound();
    $this->get('/docker/bob/store/nothing')->assertNotFound();

    $props = dockerIslandProps($this->get('/docker/bob/apps/kuma')->assertOk()->assertSee('30020')->getContent());
    expect($props['view'])->toBe('app')
        ->and($props['app']['ports'][0]['container'])->toBe('3001')
        ->and($props['app']['catalog']['changelog'])->toBe('https://example.com/releases')
        ->and($props['task']['status'])->toBe('')
        ->and($props['pages']['files'])->toBe('http://localhost/files/bob');
    $this->get('/docker/bob/apps/none')->assertNotFound();
});

it('scopes the pages and shows the reason without Docker apps', function () {
    signIn('bob', 'user');
    $this->get('/docker/carol/apps')->assertNotFound();

    signIn('resb', 'reseller');
    $this->get('/docker/bob/apps')->assertNotFound();
    $props = dockerIslandProps($this->get('/docker/carol/apps')->assertOk()->getContent());
    expect($props['abilities']['available'])->toBeFalse()->and($props['abilities']['manage'])->toBeFalse();
    $props = dockerIslandProps($this->get('/docker/carol/store/uptime-kuma')->assertOk()->getContent());
    expect($props['domains'])->toBe([]);
});

it('shows Docker apps in the navigation and on the dashboard with the engine and the package', function () {
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-user-backups')->returnsJson([]);
    $this->fake->on('v-list-user-notifications')->returnsJson([]);

    signIn('bob', 'user');
    $this->get('/')->assertOk()
        ->assertSee('href="'.route('docker-apps').'"', false)
        ->assertSee('All Docker apps')
        ->assertSee('1 running');

    signIn('carol', 'user');
    $this->get('/')->assertOk()->assertDontSee('href="'.route('docker-apps').'"', false)->assertDontSee('All Docker apps');

    signIn('arcadmin', 'admin');
    $this->get('/docker/bob/apps')->assertOk()
        ->assertSee('href="'.route('docker-apps').'"', false)
        ->assertSee('href="'.route('server.docker').'"', false);

    // without the engine, nobody gets the entry
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['DOCKER_SYSTEM' => 'no']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    signIn('bob', 'user');
    $this->get('/')->assertOk()->assertDontSee('href="'.route('docker-apps').'"', false);
});

it('lists the Docker apps on the user detail page', function () {
    signIn('hostco', 'reseller');
    $this->get('/users/bob')->assertOk()
        ->assertSee('href="'.route('docker.apps.show', ['user' => 'bob', 'name' => 'kuma']).'"', false);
});

it('shows the engine page to administrators only', function () {
    $this->fake->on('v-list-sys-docker', ['json'])->returnsJson([
        'SYSTEM' => 'yes', 'RUNNING' => 'yes', 'VERSION' => '29.8.2', 'DATA_ROOT' => '/var/lib/docker', 'APPS' => '4',
        'REPORT' => ['TIME' => '2026-10-05 10:00:00', 'FINDINGS' => [['USER' => 'bob', 'APP' => 'kuma', 'TYPE' => 'stopped', 'DETAIL' => '', 'REPAIRED' => 'yes']]],
    ]);
    $this->fake->on('v-list-sys-docker-task', ['0', 'json'])->returnsJson(dockerTask());

    signIn('hostco', 'reseller');
    $this->get('/server/docker')->assertForbidden();

    signIn('arcadmin', 'admin');
    $props = dockerIslandProps($this->get('/server/docker')->assertOk()->assertSee('29.8.2')->getContent());
    expect($props['view'])->toBe('engine')
        ->and($props['status']['report']['findings'][0]['type'])->toBe('stopped')
        ->and($props['base'])->toEndWith('/server/docker/api');
});
