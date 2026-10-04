<?php

use App\Arc\ArcCommand;
use App\Auth\ArrayLegacySession;
use Illuminate\Support\Facades\Route;

/** Legacy session of a logged-in user (fresh activity, same IP as the test client). */
function sessionOf(string $user, string $role, array $overrides = []): array
{
    return array_merge([
        'user' => $user,
        'userContext' => $role,
        'user_combined_ip' => '127.0.0.1',
        'token' => str_repeat('e', 32),
        'LAST_ACTIVITY' => time(),
        'look' => '',
    ], $overrides);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeUsers($this->fake, [
        'arcadmin' => userRecord(['ROLE' => 'admin']),
        'bob' => userRecord(),
        'carol' => userRecord(),
    ]);
    $this->fake->on('v-log-action')->returns();
    $this->fake->on('v-log-user-logout')->returns();
});

it('redirects guests to the login form', function () {
    ArrayLegacySession::install(null);

    $this->get('/')->assertRedirect('/login');
    $this->postJson('/logout')->assertStatus(401);
});

it('logs out of both apps', function () {
    $session = ArrayLegacySession::install(sessionOf('bob', 'user'));

    $this->post('/logout')->assertRedirect('/login');

    $session->assertDestroyed();
    $this->fake->assertRan('v-log-user-logout', fn ($args) => $args === ['bob', str_repeat('e', 32)]);
});

it('lets an admin impersonate a user, as the legacy ?loginas= does', function () {
    $session = ArrayLegacySession::install(sessionOf('arcadmin', 'admin', ['_sf2_meta' => 'x']));

    $this->post('/impersonate/bob')->assertRedirect('/login/');

    expect($session->data()['look'])->toBe('bob')
        ->and($session->data())->not->toHaveKey('_sf2_meta');
    $this->fake->assertRan('v-log-action', fn ($args) => $args === ['arcadmin', 'Info', 'Security', 'Logged in as another user (User: bob)']);
    $this->fake->assertRan('v-log-action', fn ($args) => $args === ['system', 'Warning', 'Security', 'User impersonation session started (User: bob, Administrator: arcadmin)']);
});

it('ends an impersonation', function () {
    $session = ArrayLegacySession::install(sessionOf('arcadmin', 'admin', ['look' => 'bob']));

    $this->post('/impersonate/stop')->assertRedirect('/');

    expect($session->data())->not->toHaveKey('look');
    $this->fake->assertRan('v-log-action', fn ($args) => $args[3] === 'User impersonation session ended (User: bob, Administrator: arcadmin)');
});

it('refuses impersonation by users and of unknown users', function () {
    ArrayLegacySession::install(sessionOf('bob', 'user'));
    $this->post('/impersonate/carol')->assertForbidden();

    ArrayLegacySession::install(sessionOf('arcadmin', 'admin'));
    $this->post('/impersonate/ghost')->assertNotFound();
    $this->post('/impersonate/arcadmin')->assertForbidden();

    $this->fake->assertNotRan('v-log-action');
});

it('refuses state changes in read-only mode', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes']));
    fakeUsers($this->fake, ['bob' => userRecord(['SUSPENDED' => 'yes'])]);
    ArrayLegacySession::install(sessionOf('bob', 'user'));

    Route::post('/_test/change', fn () => 'changed')->middleware(['web', 'auth.arc', 'not-readonly']);
    Route::get('/_test/change', fn () => 'read')->middleware(['web', 'auth.arc', 'not-readonly']);

    $this->post('/_test/change')->assertForbidden();
    $this->get('/_test/change')->assertOk()->assertSee('read');
});

it('reports health without a session', function () {
    $this->getJson('/_health')->assertOk()->assertExactJson(['status' => 'ok', 'version' => '1.0.0']);
});

it('reports an unhealthy server without details', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsExit(1, 'sudo: a password is required');

    $this->getJson('/_health')->assertStatus(503)->assertExactJson(['status' => 'error']);
});

it('renders command failures as JSON for API-style requests', function () {
    ArrayLegacySession::install(sessionOf('arcadmin', 'admin'));
    $this->fake->on('v-log-action')->returns();
    Route::post('/_test/fail', function (ArcCommand $arc) {
        $arc->run('v-add-user', ['bob']);
    })->middleware(['web', 'auth.arc']);
    $this->fake->on('v-add-user')->returnsExit(4, 'Error: user bob exists');

    $this->postJson('/_test/fail')
        ->assertStatus(409)
        ->assertExactJson(['error' => ['code' => 'E_EXISTS', 'message' => 'Error: user bob exists']]);
});
