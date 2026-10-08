<?php

use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Logs, login history and the notification tray (E7 batch 2 spec §9.1–§9.3).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $log = fn (string $user) => [
        '1' => ['DATE' => '2026-10-01', 'TIME' => '09:00:00', 'LEVEL' => 'Info', 'CATEGORY' => 'Web', 'MESSAGE' => "Added web domain ({$user}.example)."],
        '2' => ['DATE' => '2026-10-02', 'TIME' => '10:00:00', 'LEVEL' => 'Warning', 'CATEGORY' => 'System', 'MESSAGE' => "Disk quota of {$user} is almost full."],
        '3' => ['DATE' => '2026-10-03', 'TIME' => '11:00:00', 'LEVEL' => 'Error', 'CATEGORY' => 'Mail', 'MESSAGE' => '<b>Mail</b> delivery failed.'],
    ];
    $this->fake->on('v-list-user-log')->using(fn (array $args) => [0, (string) json_encode($log($args[0]))]);
    $this->fake->on('v-list-user-auth-log')->returnsFixture('v-list-user-auth-log/arcadmin.json');
    $this->fake->on('v-delete-user-log')->returns();
    $this->fake->on('v-delete-user-auth-log')->returns();
});

// Action log (§9.2)

it('shows the action log of a user, newest first, escaped', function () {
    signIn('bob', 'user');

    $this->get('/logs')->assertRedirect('/logs/bob');
    $this->get('/logs/bob')->assertOk()
        ->assertSeeInOrder(['&lt;b&gt;Mail&lt;/b&gt; delivery failed.', 'Disk quota of bob is almost full.', 'Added web domain (bob.example).'], false)
        ->assertSee('Information')->assertSee('Warning')->assertSee('Error')
        ->assertSee('Login History')->assertSee('Are you sure you want to delete the logs?')
        ->assertSee('3 log records');
    $this->get('/logs/carol')->assertNotFound();
});

it('shows "Unable to load logs" when the log cannot be read', function () {
    $this->fake->on('v-list-user-log')->returnsExit(3, 'Error: no log');
    signIn('bob', 'user');

    $this->get('/logs/bob')->assertOk()->assertSee('Unable to load logs');
});

it('lets administrators read and delete every log', function () {
    signIn('arcadmin', 'admin');

    $this->get('/logs?owner=carol')->assertRedirect('/logs/carol');
    $this->get('/logs/carol')->assertOk()->assertSee('Disk quota of carol')->assertSee('Show logs');
    $this->delete('/logs/carol')->assertRedirect('/logs/carol')->assertSessionHas('ok', 'Logs have been deleted.');
    $this->fake->assertRan('v-delete-user-log', fn ($a) => $a === ['carol']);
});

it('lets a reseller read but not delete the logs of its customers', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/logs/bob')->assertOk()->assertSee('Disk quota of bob')->assertDontSee('Are you sure you want to delete the logs?');
    $this->delete('/logs/bob')->assertForbidden();
    $this->delete('/logs/hostco')->assertRedirect('/logs/hostco');
    $this->get('/logs/carol')->assertNotFound();
    $this->fake->assertRan('v-delete-user-log', fn ($a) => $a === ['bob'], 0);
});

it('follows POLICY_USER_VIEW_LOGS and POLICY_USER_DELETE_LOGS', function () {
    withSysConfig($this->fake, ['POLICY_USER_DELETE_LOGS' => 'no']);
    signIn('bob', 'user');
    $this->get('/logs/bob')->assertOk()->assertDontSee('Are you sure you want to delete the logs?');
    $this->delete('/logs/bob')->assertForbidden();

    withSysConfig($this->fake, ['POLICY_USER_VIEW_LOGS' => 'no']);
    $this->get('/logs/bob')->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->get('/logs/bob')->assertOk();
});

it('refuses deleting logs in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/logs/bob')->assertOk()->assertDontSee('Are you sure you want to delete the logs?');
    $this->delete('/logs/bob')->assertForbidden();
    $this->delete('/logs/bob/auth')->assertForbidden();
    $this->fake->assertNotRan('v-delete-user-log');
    $this->fake->assertNotRan('v-delete-user-auth-log');
});

// Login history (§9.2)

it('shows the login history and deletes it without logging a new login', function () {
    signIn('bob', 'user');

    $this->get('/logs/bob/auth')->assertOk()
        ->assertSeeInOrder(['192.0.2.191', '203.0.113.5'])->assertSee('Success')->assertSee('Failed')->assertSee('curl/8.5.0');
    $this->delete('/logs/bob/auth')->assertRedirect('/logs/bob/auth')->assertSessionHas('ok', 'Logs have been deleted.');
    $this->fake->assertRan('v-delete-user-auth-log', fn ($a) => $a === ['bob']);
    $this->fake->assertNotRan('v-log-user-login');
});

it('hides the login history in demo mode and protects the admin history', function () {
    withSysConfig($this->fake, ['DEMO_MODE' => 'yes']);
    signIn('bob', 'user');
    $this->get('/logs/bob')->assertOk()->assertDontSee('Login History');
    $this->get('/logs/bob/auth')->assertForbidden();

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['otheradmin' => ['ROLE' => 'admin']]);
    signIn('otheradmin', 'admin');
    $this->get('/logs/arcadmin/auth')->assertForbidden();
    $this->get('/logs/bob/auth')->assertOk();
    signIn('arcadmin', 'admin');
    $this->get('/logs/arcadmin/auth')->assertOk();
});

// Notification tray (§9.3)

it('marks every notification read and deletes them all', function () {
    $this->fake->on('v-acknowledge-user-notification')->returns();
    $this->fake->on('v-delete-user-notification')->returns();
    signIn('bob', 'user');

    $this->postJson('/_notifications/acknowledge')->assertOk();
    $this->deleteJson('/_notifications')->assertOk();
    $this->fake->assertRan('v-acknowledge-user-notification', fn ($a) => $a === ['bob', 'all']);
    $this->fake->assertRan('v-delete-user-notification', fn ($a) => $a === ['bob', 'all']);

    // the tray of every shell page offers both
    $this->get('/logs/bob')->assertOk()->assertSee('Mark all read')->assertSee('Delete all notifications');
});

it('refuses tray changes in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->postJson('/_notifications/acknowledge')->assertForbidden();
    $this->deleteJson('/_notifications')->assertForbidden();
    // single notifications too (E34 batch 6 D64)
    $this->postJson('/_notifications/2/acknowledge')->assertForbidden();
    $this->deleteJson('/_notifications/1')->assertForbidden();
    $this->fake->assertNotRan('v-acknowledge-user-notification');
    $this->fake->assertNotRan('v-delete-user-notification');
});

it('has a capability for every log route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'logs.'));

    expect($routes)->toContain('logs.index', 'logs.show', 'logs.auth', 'logs.destroy', 'logs.auth.destroy')
        ->and(Capabilities::names())->toContain('logs.view', 'logs.delete');
});
