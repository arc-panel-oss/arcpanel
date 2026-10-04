<?php

use App\Arc\ArcCommandFake;
use App\Auth\ArrayLegacySession;
use App\Http\Controllers\Auth\LoginController;

/** Set up bob with password "pw" (des hash) and the commands a login runs. */
function bobCanLogIn(ArcCommandFake $fake, array $user = []): void
{
    $fake->on('v-get-user-salt')->returnsJson(['bob' => ['METHOD' => 'des', 'SALT' => 'xy']]);
    $fake->on('v-check-user-hash')->using(fn ($args) => $args[1] === crypt('pw', 'xy') ? [0, ''] : [9, 'Error']);
    fakeUsers($fake, ['bob' => userRecord($user)]);
    $fake->on('v-log-user-login')->returns();
    $fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de']);
}

beforeEach(function () {
    $this->fake = fakeArc();
    $this->session = ArrayLegacySession::install(null);
});

it('shows the login form', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Welcome to ArcPanel')
        ->assertSee('name="password"', false)
        ->assertSee('href="/reset/"', false);
});

it('hides the password reset link when the policy disables it', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_SYSTEM_PASSWORD_RESET' => 'no']));

    $this->get('/login')->assertOk()->assertDontSee('href="/reset/"', false);
});

it('logs a user in and lands on the legacy page', function () {
    bobCanLogIn($this->fake);

    $this->get('/login');
    $fingerprint = session('login.fingerprint');

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])
        ->assertRedirect('/list/web/');

    $this->session->assertRegenerated();
    expect($this->session->data())->toMatchArray([
        'user' => 'bob',
        'userContext' => 'user',
        'token' => $fingerprint,
        'user_combined_ip' => '127.0.0.1',
    ]);
    $this->fake->assertRan('v-log-user-login', fn ($args) => $args[2] === 'success' && $args[3] === $fingerprint);
});

it('sends an admin to the users list', function () {
    bobCanLogIn($this->fake, ['ROLE' => 'admin']);

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/list/user/');
});

it('shows the legacy error for a wrong password and keeps the user name', function () {
    bobCanLogIn($this->fake);

    $this->post('/login', ['user' => 'bob', 'password' => 'nope'])
        ->assertRedirect('/login')
        ->assertSessionHas('error', 'Invalid username or password')
        ->assertSessionHasInput('user', 'bob');

    expect($this->session->data())->toBeNull();
});

it('asks for the 2FA code, then logs in', function () {
    bobCanLogIn($this->fake, ['TWOFA' => 'SECRET']);
    $this->fake->on('v-check-user-2fa', ['bob', '123456'])->returns();

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/login/2fa');
    expect(session('pending_2fa'))->toMatchArray(['user' => 'bob', 'failures' => 0])
        ->and(session('pending_2fa'))->not->toHaveKey('password');
    $this->get('/login/2fa')->assertOk()->assertSee('name="twofa"', false);

    $this->post('/login/2fa', ['twofa' => '123456'])->assertRedirect('/list/web/');
    expect($this->session->data()['user'])->toBe('bob')
        ->and(session('pending_2fa'))->toBeNull();
});

it('ends the attempt after more than three wrong 2FA codes', function () {
    bobCanLogIn($this->fake, ['TWOFA' => 'SECRET']);
    $this->fake->on('v-check-user-2fa')->returnsExit(9, 'Error');
    $this->post('/login', ['user' => 'bob', 'password' => 'pw']);

    foreach (range(1, 3) as $attempt) {
        $this->post('/login/2fa', ['twofa' => '000000'])->assertRedirect('/login/2fa');
    }
    $this->post('/login/2fa', ['twofa' => '000000'])
        ->assertRedirect('/login')
        ->assertSessionHas('error', 'Invalid or missing 2FA token');

    expect(session('pending_2fa'))->toBeNull()
        ->and($this->session->data())->toBeNull();
    $this->fake->assertRan('v-log-user-login', fn ($args) => ($args[6] ?? '') === 'Invalid or missing 2FA token', 1);
});

it('refuses an expired 2FA step', function () {
    bobCanLogIn($this->fake, ['TWOFA' => 'SECRET']);
    $this->post('/login', ['user' => 'bob', 'password' => 'pw']);

    $this->travel(LoginController::SECOND_FACTOR_MINUTES + 1)->minutes();

    $this->get('/login/2fa')->assertRedirect('/login');
    $this->post('/login/2fa', ['twofa' => '123456'])->assertRedirect('/login');
    $this->fake->assertNotRan('v-check-user-2fa');
});

it('sends a logged-in user away from the login form', function () {
    fakeUsers($this->fake, ['bob' => userRecord()]);
    ArrayLegacySession::install([
        'user' => 'bob', 'userContext' => 'user', 'user_combined_ip' => '127.0.0.1',
        'token' => str_repeat('a', 32), 'LAST_ACTIVITY' => time(), 'look' => '',
    ]);

    $this->get('/login')->assertRedirect('/login/');
});

it('rejects a login form without a CSRF token', function () {
    $this->app['env'] = 'production';

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertStatus(419);
    $this->fake->assertNotRan('v-get-user-salt');
});
