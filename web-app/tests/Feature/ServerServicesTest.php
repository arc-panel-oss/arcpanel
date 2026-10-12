<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Services page (E7 batch 3 spec §7).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => [
        'HOSTNAME' => 'srv1.example.net', 'OS' => 'Ubuntu', 'VERSION' => '24.04', 'ARCH' => 'x86_64',
        'ARCPANEL' => '2.0.0', 'RELEASE' => 'release', 'UPTIME' => '3000', 'LOADAVERAGE' => '0.28 / 0.10 / 0.07',
    ]]);
    $this->fake->on('v-list-sys-php')->returnsJson(['8.4', '8.5']);
    $service = fn (string $system, string $state = 'running', string $cpu = '0', string $mem = '10', string $rtime = '45') => [
        'SYSTEM' => $system, 'STATE' => $state, 'CPU' => $cpu, 'MEM' => $mem, 'RTIME' => $rtime,
    ];
    $this->fake->on('v-list-sys-services')->returnsJson([
        'nginx' => $service('reverse proxy', 'running', '12', '46', '120'),
        'php8.4-fpm' => $service('php interpreter'),
        'exim4' => $service('mail server', 'stopped'),
        'iptables' => $service('firewall'),
        'arcpanel' => $service('panel'),
        'memcached' => $service('cache'),
    ]);
});

it('lists the services with the system facts and the config editors', function () {
    signIn('arcadmin', 'admin');

    $response = $this->get('/server/services')->assertOk()
        ->assertSee('srv1.example.net')->assertSee('Ubuntu 24.04 (x86_64)')->assertSee('2 days')
        ->assertSee('0.28 / 0.10 / 0.07')->assertSee('8.4, 8.5')
        ->assertSeeInOrder(['nginx', 'php8.4-fpm', 'exim4', 'iptables'])
        ->assertSee('1.2%')->assertSee('46 MB')
        ->assertSee('Are you sure you want to restart the nginx service?')
        ->assertSee('Are you sure you want to stop the nginx service?')
        ->assertSee('Are you sure you want to start the exim4 service?')
        ->assertDontSee('Are you sure you want to stop the arcpanel service?')
        ->assertSee('This service is required by the panel.')
        ->assertSee('Restart server')
        ->assertSee('6 services');

    if (Route::has('server.services.config')) {
        $response->assertSee(route('server.php.show', ['version' => '8.4', 'tab' => 'ini-fpm']), false)
            ->assertDontSee(route('server.services.config', ['service' => 'memcached']), false);
    }
});

it('shows a status report', function () {
    $this->fake->on('v-list-sys-memory-status')->returns("MiB Mem :  15921.5 total\n");
    signIn('arcadmin', 'admin');

    $this->get('/server/services/status/mem')->assertOk()->assertSee('MiB Mem :  15921.5 total');
    $this->get('/server/services/status/swap')->assertNotFound();
    $this->fake->assertRan('v-list-sys-memory-status');
});

it('starts, stops and restarts a service with the legacy commands', function () {
    foreach (['v-start-service', 'v-stop-service', 'v-restart-service', 'v-update-firewall', 'v-stop-firewall'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('arcadmin', 'admin');

    $this->post('/server/services/nginx/restart')->assertRedirect('/server/services')->assertSessionHas('ok', 'Service nginx has been restarted.');
    $this->fake->assertRan('v-restart-service', fn ($a) => $a === ['nginx', 'yes']);
    $this->post('/server/services/exim4/start')->assertSessionHas('ok', 'Service exim4 has been started.');
    $this->fake->assertRan('v-start-service', fn ($a) => $a === ['exim4']);
    $this->post('/server/services/nginx/stop')->assertSessionHas('ok', 'Service nginx has been stopped.');
    $this->fake->assertRan('v-stop-service', fn ($a) => $a === ['nginx']);

    // The firewall restarts with v-update-firewall and stops with v-stop-firewall
    $this->post('/server/services/iptables/restart')->assertSessionHas('ok');
    $this->fake->assertRan('v-update-firewall');
    $this->post('/server/services/iptables/stop')->assertSessionHas('ok');
    $this->fake->assertRan('v-stop-firewall');

    $this->post('/server/services/unknown/restart')->assertNotFound();
});

it('shows the command error of a failed action', function () {
    $this->fake->on('v-restart-service')->returnsExit(ExitCode::Restart, 'Error: nginx restart failed');
    signIn('arcadmin', 'admin');

    $this->post('/server/services/nginx/restart')->assertSessionHas('error', 'Error: nginx restart failed');
});

it('never stops a protected service, alone or in bulk', function () {
    $this->fake->on('v-stop-service')->returns();
    $this->fake->on('v-restart-service')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/services/arcpanel/stop')->assertForbidden();
    $this->post('/server/services/arcpanel/restart')->assertSessionHas('ok');

    $this->post('/server/services/bulk', ['action' => 'stop', 'items' => ['nginx', 'arcpanel']])
        ->assertSessionHas('error', '1 done, 1 failed')
        ->assertSessionHas('error_detail', 'arcpanel: This service is required by the panel.');
    $this->fake->assertRan('v-stop-service', fn ($a) => $a === ['nginx'], 1);
    $this->fake->assertRan('v-stop-service', fn ($a) => $a === ['arcpanel'], 0);

    $this->post('/server/services/bulk', ['action' => 'restart', 'items' => ['nginx', 'arcpanel']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->post('/server/services/bulk', ['action' => 'restart', 'items' => ['nope']])->assertNotFound();
    $this->post('/server/services/bulk', ['action' => 'reload', 'items' => ['nginx']])->assertSessionHasErrors('action');
});

it('restarts the server only with the host name typed in', function () {
    $this->fake->on('v-restart-system')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/services/restart-server', ['hostname' => 'srv1'])->assertSessionHasErrors('hostname');
    $this->fake->assertNotRan('v-restart-system');

    $this->post('/server/services/restart-server', ['hostname' => 'srv1.example.net'])
        ->assertRedirect('/server/services')->assertSessionHas('ok', 'The system is going down for reboot NOW!');
    $this->fake->assertRan('v-restart-system', fn ($a) => $a === ['yes']);
});

it('keeps the services for administrators and refuses changes in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/services')->assertForbidden();
        $this->get('/server/services/status/cpu')->assertForbidden();
        $this->post('/server/services/nginx/restart')->assertForbidden();
        $this->post('/server/services/bulk', ['action' => 'restart', 'items' => ['nginx']])->assertForbidden();
        $this->post('/server/services/restart-server', ['hostname' => 'srv1.example.net'])->assertForbidden();
    }
    $this->fake->assertNotRan('v-restart-service');
    $this->fake->assertNotRan('v-restart-system');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/server/services')->assertForbidden();

    // An administrator looking at the protected ROOT_USER reads only
    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    $this->get('/server/services')->assertOk()->assertDontSee('Restart server');
    $this->post('/server/services/nginx/restart')->assertForbidden();
    $this->fake->assertNotRan('v-restart-service');
});

it('hides the services from other administrators with POLICY_SYSTEM_HIDE_SERVICES', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_HIDE_SERVICES' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);

    signIn('admin2', 'admin');
    $this->get('/server/services')->assertForbidden();
    signIn('arcadmin', 'admin');
    $this->get('/server/services')->assertOk();
});

it('has a capability for every services route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.services'));

    expect($routes)->not->toBeEmpty()
        ->and(Capabilities::names())->toContain('server.services');
});
