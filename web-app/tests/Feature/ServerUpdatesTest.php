<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// The Updates page of the Update Center (E33 phase 1 spec §8). Nothing here
// updates anything: every command is answered by the fake.

beforeEach(function () {
    $this->fake = fakeArc(['UPGRADE_SEND_EMAIL' => 'true', 'UPGRADE_SEND_EMAIL_LOG' => 'false']);
    fakeServerUsers($this->fake);
    $package = fn (string $version) => ['VERSION' => $version, 'ARCH' => 'amd64', 'UPDATED' => 'yes', 'DESCR' => '', 'TIME' => '21:41:08', 'DATE' => '2026-10-04'];
    $this->fake->on('v-list-sys-arcpanel-updates')->returnsJson([
        'arcpanel' => $package('2.5.0'),
        'arcpanel-php' => $package('8.5.11-1-1'),
    ]);
    $this->fake->on('v-list-sys-update-policy')->returnsFixture('v-list-sys-update-policy/default.json');
    $this->fake->on('v-list-sys-arcpanel-releases')->returnsFixture('v-list-sys-arcpanel-releases/default.json');
    $this->fake->on('v-check-sys-arcpanel-update')->returnsFixture('v-check-sys-arcpanel-update/ready.json');
    $this->fake->on('v-list-sys-update-history')->returnsFixture('v-list-sys-update-history/default.json');
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-start-sys-task')->returns("17925168372499\n");
    foreach (['v-change-sys-update-policy', 'v-resume-sys-arcpanel-autoupdate', 'v-change-sys-config-value'] as $command) {
        $this->fake->on($command)->returns();
    }
});

it('shows the status, the releases with their reasons, the policy, the packages and the history', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/updates')->assertOk()
        ->assertSeeInOrder(['Installed', '2.5.0', 'Line 2.5', 'Policy', 'Patch releases', 'Mon–Sun 02:00–04:00, after 2 days', 'Release manifest', 'Verified'])
        // 2.6.0 needs attention, 2.5.2 is withdrawn, 2.5.1 installs on its own
        ->assertSeeInOrder(['2.6.0', 'minor', 'attention', 'A new line needs an administrator. Automatic updates stay on 2.5.', 'PHP 8.2 is no longer installed by default', 'Review and update'])
        ->assertSeeInOrder(['2.5.2', 'withdrawn', "Withdrawn on Oct 31, 2026: breaks the panel's NGINX configuration. It will not be installed.", 'Not installable'])
        ->assertSeeInOrder(['2.5.1', 'security', 'Installs on its own in the next window, Nov 3, 02:00. Security release, so the delay does not apply.', 'Update to 2.5.1'])
        ->assertSee('https://arcpanel.org/changelog#2.6.0', false)
        ->assertSeeInOrder(['Installed packages', 'arcpanel', '2.5.0', 'arcpanel-php', '8.5.11-1-1'])
        ->assertSeeInOrder(['#3', '2.5.0', '2.5.1', 'Done', 'Policy', 'Nov 3, 02:14', '2 min 35 s', 'Roll back'])
        ->assertSeeInOrder(['#2', 'Skipped', 'activity: a backup is running'])
        ->assertSeeInOrder(['#1', 'Rolled back', 'API', 'APIKEY0123456789abcd'])
        ->assertSee('Roll back to 2.5.0?')
        ->assertDontSee('Automatic updates are paused')
        ->assertDontSee('The release manifest could not be verified');
});

it('shows the paused banner with Resume and resumes the automatic updates', function () {
    $this->fake->on('v-list-sys-update-policy')->returnsFixture('v-list-sys-update-policy/paused.json');
    signIn('arcadmin', 'admin');

    $this->get('/server/updates')->assertOk()
        ->assertSeeInOrder(['Automatic updates are paused', 'ArcPanel 2.5.2 failed (failed): apt-get failed with exit code 100', 'Resume']);

    $this->post('/server/updates/resume')->assertRedirect('/server/updates')->assertSessionHas('ok', 'Automatic updates have been resumed.');
    $this->fake->assertRan('v-resume-sys-arcpanel-autoupdate');
});

it('warns without a verified manifest and offers the newest packages behind a confirmation', function () {
    $this->fake->on('v-list-sys-arcpanel-releases')->returnsExit(ExitCode::NotExist, 'Error: the manifest https://apt.arcpanel.org/releases.json has a bad signature');
    signIn('arcadmin', 'admin');

    $this->get('/server/updates')->assertOk()
        ->assertSeeInOrder(['The release manifest could not be verified', 'the manifest https://apt.arcpanel.org/releases.json has a bad signature', 'Try again', 'Update to latest…'])
        ->assertSee('No verified release manifest')
        ->assertSee('Update to the newest packages?')
        ->assertSee('I understand that this may move the server to a new line.');

    $this->post('/server/updates/update')->assertSessionHas('ok', 'The packages are being updated.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-update']);
});

it('says when the server is up to date', function () {
    $this->fake->on('v-list-sys-arcpanel-releases')->returnsJson([]);
    signIn('arcadmin', 'admin');

    $this->get('/server/updates')->assertOk()->assertSee('ArcPanel is up to date')->assertSee('2.5.0 is the newest release on this channel.');
});

it('downloads the manifest again on Check now', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/check')->assertSessionHas('ok', 'The release manifest has been checked.');
    $this->fake->assertRan('v-list-sys-arcpanel-releases', fn ($a) => $a === ['json', 'yes']);

    $this->fake->on('v-list-sys-arcpanel-releases')->returnsExit(ExitCode::NotExist, 'Error: no release manifest');
    $this->post('/server/updates/check')->assertSessionHas('error', 'Error: no release manifest');
});

it('answers the pre-flight of a release as JSON', function () {
    signIn('arcadmin', 'admin');

    $this->getJson('/server/updates/releases/2.5.1/preflight')->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('checks.0', ['check' => 'manifest', 'status' => 'ok', 'message' => 'ArcPanel 2.5.1 is in the release manifest']);
    $this->fake->assertRan('v-check-sys-arcpanel-update', fn ($a) => $a === ['2.5.1', 'json', 'manual']);

    $this->fake->on('v-check-sys-arcpanel-update')->returnsExit(ExitCode::Forbidden, file_get_contents(base_path('tests/Fixtures/commands/v-check-sys-arcpanel-update/refused.json')));
    $this->getJson('/server/updates/releases/2.6.0/preflight')->assertOk()->assertJsonPath('ready', false)->assertJsonPath('checks.2.status', 'fail');
    $this->get('/server/updates/releases/not-a-version/preflight')->assertNotFound();
});

it('updates to a release as the system task, with the attention items confirmed', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/release', ['version' => '2.5.1'])
        ->assertRedirect('/server/updates')->assertSessionHas('ok', 'ArcPanel is being updated to 2.5.1.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-release', '2.5.1', 'admin', 'arcadmin']);

    $this->post('/server/updates/release', ['version' => '2.6.0'])->assertSessionHasErrors('confirm_attention');
    $this->post('/server/updates/release', ['version' => '2.6.0', 'confirm_attention' => '1'])->assertSessionHas('ok', 'ArcPanel is being updated to 2.6.0.');
    $this->post('/server/updates/release', ['version' => '2.5.2', 'confirm_attention' => '1'])->assertSessionHasErrors(['version' => 'ArcPanel 2.5.2 was withdrawn.']);
    $this->post('/server/updates/release', ['version' => '2.9.9'])->assertSessionHasErrors('version');
    $this->post('/server/updates/release', ['version' => '2.5'])->assertSessionHasErrors('version');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => ($a[1] ?? null) === '2.6.0');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => in_array($a[1] ?? null, ['2.5.2', '2.9.9'], true), 0);
});

it('refuses an update or a rollback while another task runs, and a rollback the command refuses', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/release', ['version' => '2.5.1'])->assertSessionHas('error', 'Another task is running. Wait until it has finished.');
    $this->post('/server/updates/history/3/rollback')->assertSessionHas('error', 'Another task is running. Wait until it has finished.');

    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::Forbidden, 'Error: the rollback is not allowed: run 2 is not the newest update');
    $this->post('/server/updates/history/2/rollback')->assertSessionHas('error', 'Error: the rollback is not allowed: run 2 is not the newest update');
});

it('rolls back the newest update as the system task', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/history/3/rollback')->assertRedirect('/server/updates')->assertSessionHas('ok', 'Update run 3 is being rolled back.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-rollback', '3']);
    $this->post('/server/updates/history/x/rollback')->assertNotFound();
});

it('saves the policy, its window and delay', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/policy', ['policy' => 'notify', 'window_days' => ['sat', 'sun'], 'window_from' => '23', 'window_to' => '2', 'delay_days' => '5'])
        ->assertRedirect('/server/updates')->assertSessionHas('ok', 'The update policy has been saved.');
    $this->fake->assertRan('v-change-sys-update-policy', fn ($a) => $a === ['notify', 'sat,sun', '23-02', '5']);

    // The stored policy (patch, all, 02-04, 2) runs nothing
    $this->post('/server/updates/policy', ['policy' => 'patch', 'window_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'window_from' => '2', 'window_to' => '4', 'delay_days' => '2'])
        ->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertRan('v-change-sys-update-policy', fn ($a) => true, 1);
});

it('validates the policy form', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/policy', ['policy' => 'all', 'window_days' => [], 'window_from' => '3', 'window_to' => '3', 'delay_days' => '31'])
        ->assertSessionHasErrors(['policy', 'window_days', 'window_to', 'delay_days']);
    $this->post('/server/updates/policy', ['policy' => 'patch', 'window_days' => ['mon', 'funday'], 'window_from' => '24', 'window_to' => '25', 'delay_days' => '1'])
        ->assertSessionHasErrors(['window_days.1', 'window_from', 'window_to']);
    $this->fake->assertNotRan('v-change-sys-update-policy');
});

it('saves the notifications', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/updates/notifications', ['send_email' => '1', 'send_log' => '1'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['UPGRADE_SEND_EMAIL_LOG', 'true']);
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a[0] === 'UPGRADE_SEND_EMAIL', 0);
});

it('keeps the page for administrators', function (string $user, string $role) {
    fakeResellers($this->fake);
    signIn($user, $role);

    $this->get('/server/updates')->assertForbidden();
    $this->get('/server/updates/releases/2.5.1/preflight')->assertForbidden();
    foreach (['/server/updates/check', '/server/updates/release', '/server/updates/update', '/server/updates/policy', '/server/updates/resume', '/server/updates/history/3/rollback', '/server/updates/notifications'] as $url) {
        $this->post($url, ['version' => '2.5.1', 'policy' => 'off'])->assertForbidden();
    }
    $this->fake->assertNotRan('v-start-sys-task');
    $this->fake->assertNotRan('v-change-sys-update-policy');
})->with([['bob', 'user'], ['hostco', 'reseller']]);

it('shows everything and changes nothing in a read-only session', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);

    $this->get('/server/updates')->assertOk()
        ->assertSee('Read-only session')
        ->assertSee('2.6.0')->assertSee('Patch releases')
        ->assertDontSee('Review and update')->assertDontSee('Save policy')->assertDontSee('Check now')
        ->assertDontSee('Roll back to 2.5.0?');
    $this->getJson('/server/updates/releases/2.5.1/preflight')->assertOk();
    foreach (['/server/updates/check', '/server/updates/release', '/server/updates/update', '/server/updates/policy', '/server/updates/resume', '/server/updates/history/3/rollback'] as $url) {
        $this->post($url, ['version' => '2.5.1', 'policy' => 'off'])->assertForbidden();
    }
    $this->fake->assertNotRan('v-start-sys-task');
    $this->fake->assertNotRan('v-change-sys-update-policy');
    $this->fake->assertNotRan('v-resume-sys-arcpanel-autoupdate');
});

it('has a capability for every updates route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.updates'));

    expect($routes)->not->toBeEmpty()->and(Capabilities::names())->toContain('server.updates');
});
