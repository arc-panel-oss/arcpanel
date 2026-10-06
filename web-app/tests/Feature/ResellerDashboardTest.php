<?php

// Reseller dashboard and "Your provider" (E11 spec §9).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeWebDomains($this->fake);
    fakeResellers($this->fake, [
        'resb' => ['WEB_DOMAINS' => '10', 'U_WEB_DOMAINS' => '1', 'DISK_QUOTA' => '10000', 'U_DISK' => '100', 'CONTACT' => 'help@resb.example'],
        'carol' => ['WEB_DOMAINS' => '3', 'U_WEB_DOMAINS' => '1', 'DISK_QUOTA' => '2000', 'U_DISK' => '500', 'DATE' => '2026-10-01'],
        'dave' => ['WEB_DOMAINS' => '2', 'U_WEB_DOMAINS' => '0', 'DISK_QUOTA' => '1000', 'U_DISK' => '50', 'DATE' => '2026-10-03', 'SUSPENDED' => 'yes'],
    ]);
    $this->fake->on('v-list-package-tenancy', ['reseller', 'json'])->returnsJson(['reseller' => ['USERS' => '5']]);
    $this->fake->on('v-list-user-backups')->returnsJson([]);
    $this->fake->on('v-list-user-notifications')->returnsJson([]);
});

it('shows the plan allocation, customers and own hosting to a reseller', function () {
    signIn('resb', 'reseller');

    $response = $this->get('/')->assertOk()
        ->assertSee('Welcome, resb')
        ->assertSee('Add Customer')
        ->assertSee('My hosting')
        ->assertSeeInOrder(['dave', 'carol'])
        ->assertSee('1 suspended');

    // Allocated web domains: own usage 1 + carol 3 + dave 2 = 6 of 10; customers 2 of 5
    $response->assertSee('6 / 10')->assertSee('2 / 5');
});

it('shows the reseller as provider to its customers', function () {
    signIn('carol', 'user');
    $this->get('/')->assertOk()->assertSee('Your provider')->assertSee('Reseller B')->assertSee('help@resb.example');
});

it('shows no provider to users of the administrator', function () {
    $this->fake = fakeArc();
    fakeWebDomains($this->fake);
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-user-backups')->returnsJson([]);
    $this->fake->on('v-list-user-notifications')->returnsJson([]);
    signIn('carol', 'user');
    $this->get('/')->assertOk()->assertDontSee('Your provider');
});

it('counts resellers on the admin dashboard', function () {
    $dir = base_path('tests/Fixtures/commands/v-list-sys-status/');
    $this->fake->on('v-list-sys-info', ['json'])->returnsFixture('v-list-sys-info/default.json');
    $this->fake->on('v-list-sys-memory-status')->returns((string) file_get_contents($dir.'memory.txt'));
    $this->fake->on('v-list-sys-disk-status')->returns((string) file_get_contents($dir.'disk.txt'));
    $this->fake->on('v-list-sys-cpu-status')->returns((string) file_get_contents($dir.'cpu.txt'));
    $this->fake->on('v-list-sys-services', ['json'])->returnsFixture('v-list-sys-services/default.json');
    $this->fake->on('v-list-sys-rrd', ['json'])->returnsFixture('v-list-sys-rrd/default.json');
    $this->fake->on('v-list-user-auth-log', ['arcadmin', 'json'])->returnsFixture('v-list-user-auth-log/arcadmin.json');
    $this->fake->on('v-list-sys-arcpanel-updates', ['json'])->returnsFixture('v-list-sys-arcpanel-updates/default.json');
    signIn('arcadmin', 'admin');

    $this->get('/')->assertOk()->assertSee('2 resellers');
});
