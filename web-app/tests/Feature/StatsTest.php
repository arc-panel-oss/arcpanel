<?php

use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Statistics and the Task Monitor (E7 batch 2 spec §9.4, §9.5).

/** One month of the stats commands */
function statsMonth(string $disk, string $quota = '1000', string $web = '1', array $overrides = []): array
{
    return array_merge([
        'TIME' => '01:00:00', 'PACKAGE' => 'default', 'IP_OWNED' => '0', 'DISK_QUOTA' => $quota, 'U_DISK' => $disk,
        'U_DISK_DIRS' => '1', 'U_DISK_WEB' => '2', 'U_DISK_MAIL' => '3', 'U_DISK_DB' => '4', 'BANDWIDTH' => 'unlimited',
        'U_BANDWIDTH' => '2048', 'U_WEB_DOMAINS' => $web, 'U_WEB_SSL' => '0', 'U_WEB_ALIASES' => '0', 'U_DNS_DOMAINS' => '0',
        'U_DNS_RECORDS' => '0', 'U_MAIL_DOMAINS' => '0', 'U_MAIL_DKIM' => '0', 'U_MAIL_ACCOUNTS' => '0', 'U_DATABASES' => '0',
        'U_CRON_JOBS' => '0', 'U_BACKUPS' => '1',
    ], $overrides);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-user-stats')->using(fn (array $args) => [0, (string) json_encode([
        '2026-09-01' => statsMonth('10', web: $args[0] === 'bob' ? '7' : '1'),
        '2026-10-01' => statsMonth($args[0] === 'bob' ? '512' : '64'),
    ])]);
    $this->fake->on('v-list-users-stats')->returnsJson([
        '2026-10-01' => statsMonth('4096', '0', '42', ['U_USERS' => '3', 'IP_OWNED' => '2']),
    ]);
    $this->fake->on('v-list-reseller-users-stats')->returnsJson([
        '2026-10-01' => statsMonth('700', 'unlimited', '9', ['U_USERS' => '2']),
    ]);
});

it('shows the months of a user, newest first', function () {
    signIn('bob', 'user');

    $this->get('/stats')->assertOk()
        ->assertSeeInOrder(['Oct 2026', '512 MB', 'Sep 2026'])
        ->assertSee('User Directory')->assertSee('Web Domains')->assertSee('2 months')
        ->assertDontSee('IP Addresses')->assertDontSee('Overall Statistics');
    $this->fake->assertRan('v-list-user-stats', fn ($a) => $a === ['bob', 'json']);
    $this->get('/stats?owner=carol')->assertNotFound();
    $this->fake->assertNotRan('v-list-users-stats');
});

it('shows the server totals to administrators and one user on request', function () {
    signIn('arcadmin', 'admin');

    $this->get('/stats')->assertOk()->assertSee('4 GB')->assertSee('Overall Statistics')->assertSee('IP Addresses')
        ->assertSee('Totals of every account on this server, per month.');
    $this->get('/stats?owner=carol')->assertOk()->assertSee('64 MB')->assertSee('Usage of carol, per month.');
    $this->fake->assertRan('v-list-user-stats', fn ($a) => $a === ['carol', 'json']);
    $this->get('/stats?owner=ghost')->assertNotFound();
});

it('hides the root user from the picker with POLICY_SYSTEM_HIDE_ADMIN', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_HIDE_ADMIN' => 'yes']);
    signIn('arcadmin', 'admin');

    $this->get('/stats')->assertOk()->assertDontSee('<option value="arcadmin"', false)->assertSee('<option value="carol"', false);
});

it('shows a reseller the totals of its tree and its customers one by one', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/stats')->assertOk()->assertSee('700 MB')->assertSee('Totals of your account and your customers, per month.');
    $this->fake->assertRan('v-list-reseller-users-stats', fn ($a) => $a === ['hostco', 'json']);
    $this->get('/stats?owner=bob')->assertOk()->assertSee('512 MB');
    $this->get('/stats?owner=carol')->assertNotFound();
    $this->fake->assertNotRan('v-list-users-stats');
});

it('shows the own months of a reseller acting as a customer', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller', ['arc_look' => 'bob']);

    $this->get('/stats')->assertOk()->assertSee('512 MB');
    $this->fake->assertNotRan('v-list-reseller-users-stats');
});

// Task Monitor (§9.5)

it('shows every server graph to administrators with the chosen period', function () {
    $this->fake->on('v-list-sys-rrd')->returnsFixture('v-list-sys-rrd/default.json');
    signIn('arcadmin', 'admin');

    $this->get('/server/rrd?period=triennially')->assertOk()
        ->assertSee('Load Average')->assertSee('Bandwidth Usage eth0')
        ->assertSee('net_eth0', false)->assertSee('triennially', false)->assertSee('Biennially');
    $this->get('/server/rrd?period=hourly')->assertOk()->assertSee('aria-current="page" >Daily', false);

    signIn('bob', 'user');
    $this->get('/server/rrd')->assertForbidden();
});

it('exports only the graphs the server lists', function () {
    $this->fake->on('v-list-sys-rrd')->returnsFixture('v-list-sys-rrd/default.json');
    $this->fake->on('v-export-rrd')->returnsJson(['meta' => ['start' => 1, 'step' => 300, 'legend' => ['in', 'out']], 'data' => [[1, 2]]]);
    signIn('arcadmin', 'admin');

    $this->getJson('/_rrd?service=net_eth0&period=biennially')->assertOk()->assertJsonPath('unit', 'KBytes');
    $this->fake->assertRan('v-export-rrd', fn ($a) => $a === ['net_eth0', 'biennially', 'json']);
    $this->getJson('/_rrd?service=mysql_localhost')->assertNotFound();
});

it('has a capability for the statistics and graph pages', function () {
    expect(collect(Route::getRoutes()->getRoutesByName())->keys())->toContain('stats.index', 'server.rrd')
        ->and(Capabilities::names())->toContain('stats.view', 'server.rrd');
});
