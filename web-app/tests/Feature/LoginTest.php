<?php

use App\Arc\ArcCommandFake;
use App\Auth\ArrayLegacySession;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Sleep;

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
        ->assertSee('href="'.route('reset').'"', false);
});

it('hides the password reset link when the policy disables it', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_SYSTEM_PASSWORD_RESET' => 'no']));

    $this->get('/login')->assertOk()->assertDontSee('href="'.route('reset').'"', false);
});

it('logs a user in and lands on the dashboard', function () {
    bobCanLogIn($this->fake);

    $this->get('/login');
    $fingerprint = session('login.fingerprint');

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])
        ->assertRedirect('/');

    $this->session->assertRegenerated();
    expect($this->session->data())->toMatchArray([
        'user' => 'bob',
        'userContext' => 'user',
        'token' => $fingerprint,
        'user_combined_ip' => '127.0.0.1',
    ]);
    $this->fake->assertRan('v-log-user-login', fn ($args) => $args[2] === 'success' && $args[3] === $fingerprint);
});

it('sends an admin to the dashboard', function () {
    bobCanLogIn($this->fake, ['ROLE' => 'admin']);

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/');
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
    $this->fake->on('v-check-user-2fa', ['bob', '123456', '127.0.0.1'])->returns();

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/login/2fa');
    expect(session('pending_2fa'))->toMatchArray(['user' => 'bob'])
        ->and(session('pending_2fa'))->not->toHaveKey('password');
    $this->get('/login/2fa')->assertOk()->assertSee('name="twofa"', false);

    $this->post('/login/2fa', ['twofa' => '123456'])->assertRedirect('/');
    expect($this->session->data()['user'])->toBe('bob')
        ->and(session('pending_2fa'))->toBeNull();
});

it('counts wrong 2FA codes across logins and answers 429 after five', function () {
    bobCanLogIn($this->fake, ['TWOFA' => 'SECRET']);
    $this->fake->on('v-check-user-2fa', fn ($args) => $args[1] === '000000')->returnsExit(9, 'Error');
    $this->fake->on('v-check-user-2fa', fn ($args) => $args[1] === '123456')->returns();

    // A new correct password must not start a new count (H6).
    $this->post('/login', ['user' => 'bob', 'password' => 'pw']);
    foreach (range(1, 3) as $attempt) {
        $this->post('/login/2fa', ['twofa' => '000000'])->assertRedirect('/login/2fa');
    }
    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/login/2fa');
    foreach (range(1, 2) as $attempt) {
        $this->post('/login/2fa', ['twofa' => '000000'])->assertRedirect('/login/2fa');
    }

    $this->post('/login/2fa', ['twofa' => '123456'])
        ->assertStatus(429)
        ->assertSee('Too many attempts');

    expect(session('pending_2fa'))->toBeNull()
        ->and($this->session->data())->toBeNull();
    $this->fake->assertRan('v-check-user-2fa', null, 5);
    Sleep::assertNeverSlept();
});

it('logs in with the right code after a few wrong ones and starts the 2FA count again', function () {
    bobCanLogIn($this->fake, ['TWOFA' => 'SECRET']);
    $this->fake->on('v-check-user-2fa', fn ($args) => $args[1] === '000000')->returnsExit(9, 'Error');
    $this->fake->on('v-check-user-2fa', fn ($args) => $args[1] === '123456')->returns();

    $this->post('/login', ['user' => 'bob', 'password' => 'pw']);
    foreach (range(1, 4) as $attempt) {
        $this->post('/login/2fa', ['twofa' => '000000'])->assertRedirect('/login/2fa');
    }
    $this->post('/login/2fa', ['twofa' => '123456'])->assertRedirect('/');

    expect(app(LoginThrottle::class)->secondFactor('bob'))->toBeNull();
});

it('answers 429 on the sixth attempt for a user name and address before running any command', function () {
    bobCanLogIn($this->fake);

    foreach (range(1, LoginThrottle::PAIR_ATTEMPTS) as $attempt) {
        $this->post('/login', ['user' => 'bob', 'password' => 'nope'])->assertRedirect('/login');
    }
    $calls = count($this->fake->calls());

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])
        ->assertStatus(429)
        ->assertSee('Too many attempts');

    expect(count($this->fake->calls()))->toBe($calls)
        ->and($this->session->data())->toBeNull();
    Sleep::assertNeverSlept();
});

it('answers 429 once an address made too many attempts across user names', function () {
    $this->fake->on('v-log-sys-login-failure')->returns();

    foreach (range(1, LoginThrottle::IP_ATTEMPTS) as $attempt) {
        // Invalid names cost nothing but a log line, which is what M7 abused.
        $this->post('/login', ['user' => '-x'.$attempt, 'password' => 'nope'])->assertRedirect('/login');
    }

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertStatus(429);
    $this->fake->assertRan('v-log-sys-login-failure', null, LoginThrottle::IP_ATTEMPTS);
});

it('counts only failures on the hourly limit of a user name', function () {
    bobCanLogIn($this->fake);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
            ->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/');
        $this->session = ArrayLegacySession::install(null);
    }
    foreach (range(1, LoginThrottle::USER_FAILURES) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->post('/login', ['user' => 'bob', 'password' => 'nope'])->assertRedirect('/login');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
        ->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertStatus(429);
});

it('lets an address of the user\'s login allow list past the hourly user limit', function () {
    bobCanLogIn($this->fake, ['LOGIN_USE_IPLIST' => 'yes', 'LOGIN_ALLOW_IPS' => '192.0.2.10']);

    foreach (range(1, LoginThrottle::USER_FAILURES) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->post('/login', ['user' => 'bob', 'password' => 'nope']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
        ->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/');
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

    $this->get('/login')->assertRedirect('/');
});

it('rejects a login form without a CSRF token', function () {
    $this->app['env'] = 'production';

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertStatus(419);
    $this->fake->assertNotRan('v-get-user-salt');
});
