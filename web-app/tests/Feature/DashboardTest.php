<?php

use App\Arc\ExitCode;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
});

function fakeServerHealth($fake): void
{
    $dir = base_path('tests/Fixtures/commands/v-list-sys-status/');
    $fake->on('v-list-sys-info', ['json'])->returnsFixture('v-list-sys-info/default.json');
    $fake->on('v-list-sys-memory-status')->returns((string) file_get_contents($dir.'memory.txt'));
    $fake->on('v-list-sys-disk-status')->returns((string) file_get_contents($dir.'disk.txt'));
    $fake->on('v-list-sys-cpu-status')->returns((string) file_get_contents($dir.'cpu.txt'));
    $fake->on('v-list-sys-services', ['json'])->returnsFixture('v-list-sys-services/default.json');
    $fake->on('v-list-sys-rrd', ['json'])->returnsFixture('v-list-sys-rrd/default.json');
    $fake->on('v-list-user-auth-log', ['arcadmin', 'json'])->returnsFixture('v-list-user-auth-log/arcadmin.json');
    $fake->on('v-list-sys-arcpanel-updates', ['json'])->returnsFixture('v-list-sys-arcpanel-updates/default.json');
}

it('shows the admin dashboard: health, services, graphs, accounts, sign-ins, certificates and updates', function () {
    fakeServerHealth($this->fake);
    signIn('arcadmin', 'admin');

    $this->get('/')->assertOk()
        ->assertSee('srv01.example.net · Ubuntu 24.04')
        ->assertSee('aria-valuenow="23"', false)   // CPU 100 − 77 % idle
        ->assertSee('aria-valuenow="61"', false)   // memory
        ->assertSee('aria-valuenow="82"', false)   // disk
        ->assertSee('fail2ban')->assertSee('Stopped')
        ->assertSee('Load Average')->assertSee('Memory Usage')->assertDontSee('Bandwidth Usage eth0')
        ->assertSee('192.0.2.191')->assertSee('Failed')
        ->assertSee('arcpanel-php')
        ->assertSee('No certificate expires soon');
});

it('lists stopped services first with a count, and links the account figures', function () {
    fakeServerHealth($this->fake);
    signIn('arcadmin', 'admin');

    $this->get('/')->assertOk()
        ->assertSee('1 stopped')
        ->assertSeeInOrder(['fail2ban', 'nginx', 'apache2'])
        ->assertSee(route('users.index', ['role' => 'reseller']), false)
        ->assertSee(route('users.index', ['role' => 'admin']), false);
});

it('lists certificates of every user expiring within 14 days', function () {
    fakeServerHealth($this->fake);
    $soon = gmdate('M j H:i:s Y', time() + 5 * 86400).' GMT';
    $this->fake->on('v-list-web-domain-ssl', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => ['CRT' => 'x', 'KEY' => 'k', 'CA' => '', 'NOT_AFTER' => $soon]]);
    signIn('arcadmin', 'admin');

    $this->get('/')->assertOk()->assertSee('example.com')->assertSee('4 days');
    // Only SSL domains are asked
    $this->fake->assertRan('v-list-web-domain-ssl', times: 1);
});

it('renders the dashboard when one widget fails', function () {
    fakeServerHealth($this->fake);
    $this->fake->on('v-list-sys-services', ['json'])->returnsExit(ExitCode::Disabled, 'Error: services are not available');
    signIn('arcadmin', 'admin');

    $this->get('/')->assertOk()->assertSee('Error: services are not available')->assertSee('Recent sign-ins');
});

it('shows the user dashboard: usage, domains, last backup and notifications', function () {
    $this->fake->on('v-list-user-backups', ['bob', 'json'])->returnsFixture('v-list-user-backups/bob.json');
    $this->fake->on('v-list-user-notifications', ['bob', 'json'])->returnsFixture('v-list-user-notifications/bob.json');
    fakeServerUsers($this->fake, ['bob' => ['U_DISK' => '800', 'DISK_QUOTA' => '1000', 'U_WEB_DOMAINS' => '2', 'WEB_DOMAINS' => '2']]);
    signIn('bob', 'user');

    $this->get('/')->assertOk()
        ->assertSee('Welcome, bob')
        ->assertSee('aria-valuenow="80"', false)
        ->assertSee('aria-valuenow="100"', false)
        ->assertSee('example.com')->assertSee('Let’s Encrypt')
        ->assertSee('bob.2026-10-03_05-10-09.tar')
        ->assertSee('Maintenance tonight')->assertSee('Read the guide to get started.')
        ->assertSee('Quick actions')->assertSee(route('db.create'), false)->assertSee(route('cron.create'), false)
        ->assertDontSee('Recent sign-ins');
    $this->fake->assertNotRan('v-list-sys-info');
});

it('shows the user dashboard of the effective user while an admin impersonates', function () {
    $this->fake->on('v-list-user-backups')->returnsJson([]);
    $this->fake->on('v-list-user-notifications')->returnsJson([]);
    signIn('arcadmin', 'admin', ['look' => 'bob']);

    $this->get('/')->assertOk()->assertSee('Welcome, bob')->assertSee('Logged in as')->assertDontSee('Recent sign-ins');
});

it('sends a logged-in user from the login page to the dashboard', function () {
    signIn('bob', 'user');

    $this->get('/login')->assertRedirect('/');
});
