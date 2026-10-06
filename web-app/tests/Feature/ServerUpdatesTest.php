<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Updates page (E7 batch 3 spec §10).

beforeEach(function () {
    $this->fake = fakeArc(['UPGRADE_SEND_EMAIL' => 'true', 'UPGRADE_SEND_EMAIL_LOG' => 'false']);
    fakeServerUsers($this->fake);
    $package = fn (string $version, string $updated, string $descr) => [
        'VERSION' => $version, 'ARCH' => 'amd64', 'UPDATED' => $updated, 'DESCR' => $descr, 'TIME' => '21:41:08', 'DATE' => '2026-10-04',
    ];
    $this->fake->on('v-list-sys-arcpanel-updates')->returnsJson([
        'arcpanel' => $package('1.0.0-1+ubuntu24.04', 'yes', 'ArcPanel core package'),
        'arcpanel-php' => $package('8.5.11-1+ubuntu24.04', 'no', 'ArcPanel internal php interpreter'),
    ]);
    $this->fake->on('v-list-sys-arcpanel-autoupdate', ['plain'])->returns("Enabled\n");
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
});

it('lists the packages with their state, the automatic updates and the notifications', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/updates')->assertOk()
        ->assertSeeInOrder(['arcpanel', 'Package up-to-date', 'arcpanel-php', 'Update available'])
        ->assertSee('Update arcpanel-php now? The panel restarts during the update.')
        ->assertDontSee('Update arcpanel now?')
        ->assertSee('Update all')->assertSee('Disable Automatic Updates')
        ->assertSee('1 update available');
});

it('updates one package or every package in the background', function () {
    $this->fake->on('v-start-sys-task')->returns("1\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/update', ['package' => 'arcpanel-php'])
        ->assertRedirect('/server/updates')->assertSessionHas('ok', 'arcpanel-php is being updated.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-update', 'arcpanel-php']);

    $this->post('/server/updates/update')->assertSessionHas('ok', 'The packages are being updated.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-update']);

    $this->post('/server/updates/update', ['package' => 'bash'])->assertSessionHasErrors('package');
});

it('refuses an update while another task runs', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/update')->assertSessionHas('error', 'Another task is running. Wait until it has finished.');
});

it('turns the automatic updates on and off and saves the notifications', function () {
    $this->fake->on('v-add-cron-arcpanel-autoupdate')->returns();
    $this->fake->on('v-delete-cron-arcpanel-autoupdate')->returns();
    $this->fake->on('v-change-sys-config-value')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/autoupdate', ['state' => 'off'])->assertSessionHas('ok', 'Automatic updates are off.');
    $this->fake->assertRan('v-delete-cron-arcpanel-autoupdate');
    $this->post('/server/updates/autoupdate', ['state' => 'on'])->assertSessionHas('ok', 'Automatic updates are on.');
    $this->fake->assertRan('v-add-cron-arcpanel-autoupdate');

    $this->post('/server/updates/notifications', ['send_email' => '1', 'send_log' => '1'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['UPGRADE_SEND_EMAIL_LOG', 'true']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'UPGRADE_SEND_EMAIL', 0);
});

it('keeps the updates for administrators and read-only in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/updates')->assertForbidden();
        $this->post('/server/updates/update')->assertForbidden();
        $this->post('/server/updates/autoupdate', ['state' => 'off'])->assertForbidden();
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    $this->get('/server/updates')->assertOk()->assertDontSee('Update all');
    $this->post('/server/updates/update')->assertForbidden();
    $this->fake->assertNotRan('v-start-sys-task');
});

it('has a capability for every updates route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.updates'));

    expect($routes)->not->toBeEmpty()->and(Capabilities::names())->toContain('server.updates');
});
