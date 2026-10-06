<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// IP address pages (E7 batch 2 spec §7).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $ips = commandFixture('v-list-sys-ips/default.json');
    $ips['10.0.0.12'] = ['OWNER' => 'bob', 'STATUS' => 'dedicated', 'NAME' => 'example.com', 'U_SYS_USERS' => 'bob', 'U_WEB_DOMAINS' => '2',
        'INTERFACE' => 'eth0', 'NETMASK' => '255.255.255.0', 'NAT' => '', 'TIME' => '11:00:00', 'DATE' => '2026-09-02'];
    $this->fake->on('v-list-sys-ips')->returnsJson($ips);
    $this->fake->on('v-list-sys-ip')->using(fn (array $args) => isset($ips[$args[0]])
        ? [0, (string) json_encode([$args[0] => $ips[$args[0]]])]
        : [ExitCode::NotExist->value, 'Error: IP not found']);
    $this->fake->on('v-list-sys-interfaces')->returnsJson(['eth0', 'docker0']);
});

it('lists the addresses with NAT, status, domains and owner', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/ips')->assertOk()
        ->assertSeeInOrder(['10.0.0.10', '→ 203.0.113.10', '10.0.0.11', '10.0.0.12', 'example.com'])
        ->assertSee('Refresh IP Addresses')->assertSee('Add IP Address')
        ->assertSee('Are you sure you want to delete IP address 10.0.0.12?')
        ->assertSee('3 IP addresses');
});

it('keeps the IP pages for administrators', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/ips')->assertForbidden();
        $this->get('/server/ips/create')->assertForbidden();
        $this->get('/server/ips/10.0.0.10/edit')->assertForbidden();
        $this->post('/server/ips/refresh')->assertForbidden();
        $this->delete('/server/ips/10.0.0.12')->assertForbidden();
    }
    $this->fake->assertNotRan('v-update-sys-ip');
    $this->fake->assertNotRan('v-delete-sys-ip');
});

it('adds an address with the arguments of web/add/ip', function () {
    $this->fake->on('v-add-sys-ip')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/ips/create')->assertOk()->assertSee('docker0')->assertSee('Assigned User')->assertSee('NAT IP Association');

    $this->post('/server/ips', ['ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'eth0', 'owner' => 'arcadmin', 'shared' => '1'])
        ->assertRedirect('/server/ips')->assertSessionHas('ok', 'IP address 10.0.0.20 has been created successfully.');
    $this->fake->assertRan('v-add-sys-ip', fn ($a) => $a === ['10.0.0.20', '255.255.255.0', 'eth0', 'arcadmin', 'shared', '', '']);

    $this->post('/server/ips', ['ip' => '10.0.0.21', 'netmask' => '255.255.255.0', 'interface' => 'eth0', 'owner' => 'carol',
        'name' => 'carol.example', 'nat' => '203.0.113.21'])->assertRedirect('/server/ips');
    $this->fake->assertRan('v-add-sys-ip', fn ($a) => $a === ['10.0.0.21', '255.255.255.0', 'eth0', 'carol', 'dedicated', 'carol.example', '203.0.113.21']);
});

it('requires address, netmask, interface and owner', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/ips', ['ip' => '', 'netmask' => '', 'interface' => '', 'owner' => ''])->assertSessionHasErrors([
        'ip' => 'Field "IP Address" can not be blank.',
        'netmask' => 'Field "Netmask" can not be blank.',
        'interface' => 'Field "Interface" can not be blank.',
        'owner' => 'Field "Assigned User" can not be blank.',
    ]);
    $this->post('/server/ips', ['ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'wlan9', 'owner' => 'ghost'])
        ->assertSessionHasErrors(['interface', 'owner']);
    $this->fake->assertNotRan('v-add-sys-ip');
});

it('changes only what changed, in the order of web/edit/ip', function () {
    foreach (['status', 'owner', 'name', 'nat'] as $what) {
        $this->fake->on('v-change-sys-ip-'.$what)->returns();
    }
    signIn('arcadmin', 'admin');

    $this->get('/server/ips/10.0.0.12/edit')->assertOk()->assertSee('10.0.0.12')->assertSee('example.com');

    // dedicated → shared, same owner and name, new NAT
    $this->post('/server/ips/10.0.0.12/edit', ['shared' => '1', 'owner' => 'bob', 'name' => 'example.com', 'nat' => '203.0.113.12'])
        ->assertRedirect('/server/ips/10.0.0.12/edit')->assertSessionHas('ok', 'Changes have been saved.');
    $calls = array_map(fn ($call) => [$call['command'], $call['args']], array_values(array_filter(
        $this->fake->calls(), fn ($call) => str_starts_with($call['command'], 'v-change-sys-ip-'))));
    expect($calls)->toBe([
        ['v-change-sys-ip-status', ['10.0.0.12', 'shared']],
        ['v-change-sys-ip-nat', ['10.0.0.12', '203.0.113.12']],
    ]);

    // shared → dedicated for carol, without a name
    $this->post('/server/ips/10.0.0.10/edit', ['owner' => 'carol', 'name' => '', 'nat' => '203.0.113.10']);
    $this->fake->assertRan('v-change-sys-ip-status', fn ($a) => $a === ['10.0.0.10', 'dedicated']);
    $this->fake->assertRan('v-change-sys-ip-owner', fn ($a) => $a === ['10.0.0.10', 'carol']);
    $this->fake->assertNotRan('v-change-sys-ip-name');

    $this->get('/server/ips/10.9.9.9/edit')->assertNotFound();
});

it('stops at the first failing step and says why', function () {
    $this->fake->on('v-change-sys-ip-owner')->returnsExit(ExitCode::Invalid, 'Error: IP is used by web domains');
    signIn('arcadmin', 'admin');

    $this->post('/server/ips/10.0.0.12/edit', ['shared' => '', 'owner' => 'carol', 'name' => 'example.com', 'nat' => ''])
        ->assertSessionHas('error', 'Error: IP is used by web domains');
});

it('deletes, bulk deletes and refreshes addresses', function () {
    $this->fake->on('v-delete-sys-ip')->returns();
    $this->fake->on('v-update-sys-ip')->returns();
    signIn('arcadmin', 'admin');

    $this->delete('/server/ips/10.0.0.12')->assertRedirect('/server/ips')->assertSessionHas('ok', 'IP address 10.0.0.12 has been deleted.');
    $this->fake->assertRan('v-delete-sys-ip', fn ($a) => $a === ['10.0.0.12']);
    $this->post('/server/ips/bulk', ['action' => 'delete', 'items' => ['10.0.0.11', '10.0.0.12']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->post('/server/ips/bulk', ['action' => 'delete', 'items' => ['10.9.9.9']])->assertNotFound();

    // no selection needed (spec §12.4)
    $this->post('/server/ips/refresh')->assertRedirect('/server/ips')->assertSessionHas('ok', 'IP addresses have been refreshed.');
    $this->fake->assertRan('v-update-sys-ip', fn ($a) => $a === []);
});

it('has a capability for every IP route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.ips'));

    expect($routes)->toContain('server.ips', 'server.ips.create', 'server.ips.store', 'server.ips.edit', 'server.ips.update',
        'server.ips.destroy', 'server.ips.bulk', 'server.ips.refresh')
        ->and(Capabilities::names())->toContain('server.ips');
});
