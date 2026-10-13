<?php

use App\Auth\LoginThrottle;
use App\Auth\SudoMode;

// Sudo mode for sensitive changes to one's own account (E39 W2 spec §7, D12).

/** bob's password is "pw" (des hash), as in LoginTest. */
function bobHasPassword($fake): void
{
    $fake->on('v-get-user-salt')->returnsJson(['bob' => ['METHOD' => 'des', 'SALT' => 'xy']]);
    $fake->on('v-check-user-hash')->using(fn ($args) => $args[1] === crypt('pw', 'xy') ? [0, ''] : [9, 'Error']);
    $fake->on('v-log-user-login')->returns();
}

beforeEach(function () {
    $this->fake = fakeArc(['API_SYSTEM' => '2']);
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de']);
    $this->fake->on('v-list-apis')->returnsJson(['purge-nginx-cache' => ['COMMANDS' => ['v-purge-nginx-cache'], 'ROLE' => 'user']]);
    $this->fake->on('v-list-user-ssh-keys')->returnsJson([]);
    $this->fake->on('v-log-action')->returns();
});

it('asks for the password before a user changes their own password, then applies it and renews the session', function () {
    $session = signIn('bob', 'user', ['LOGIN_TIME' => (time() - 3600) * 1000]);
    bobHasPassword($this->fake);
    $this->fake->on('v-change-user-password')->returns();
    $form = ['name' => 'Bob Builder', 'email' => 'bob@example.com', 'language' => 'en', 'password' => 'Better-123'];

    $this->get('/account')->assertOk();
    $this->post('/users/bob/general', $form)
        ->assertRedirect(route('sudo.show'));
    $this->fake->assertNotRan('v-change-user-password');

    $this->get(route('sudo.show'))->assertOk()->assertSee('Confirm your password to continue.')->assertSee('name="password"', false);
    $this->post(route('sudo.store'), ['password' => 'pw'])->assertRedirect(route('account.edit'));
    expect($session->data()['SUDO_UNTIL'])->toBeGreaterThan(time() + SudoMode::SECONDS - 10);

    $this->post('/users/bob/general', $form)->assertRedirect('/account#general')->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-password', fn ($a) => $a === ['bob', 'Better-123']);
    // The user's own session survives the stamp v-change-user-password writes
    $session->assertRegenerated();
    expect($session->data()['LOGIN_TIME'])->toBeGreaterThanOrEqual((time() - 5) * 1000)
        ->and($session->data()['user'])->toBe('bob');
});

it('asks for the password before the other sensitive changes of one\'s own account', function (string $url, array $data, string $command) {
    signIn('bob', 'user');
    $this->fake->on($command)->returns();

    $this->post($url, $data)->assertRedirect(route('sudo.show'));
    $this->fake->assertNotRan($command);
})->with([
    'contact e-mail' => ['/users/bob/general', ['name' => 'Bob Builder', 'email' => 'new@example.com', 'language' => 'en'], 'v-change-user-contact'],
    'login IP list' => ['/users/bob/security', ['use_iplist' => '1', 'allowed_ips' => '203.0.113.5'], 'v-change-user-config-value'],
    'access key' => ['/access-keys/bob', ['sets' => ['purge-nginx-cache'], 'comment' => 'ci'], 'v-add-access-key'],
    'SSH key' => ['/ssh-keys/bob', ['key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOk bob@desktop'], 'v-add-user-ssh-key'],
]);

it('does not ask for the password for other changes of one\'s own account', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-user-name')->returns();

    $this->post('/users/bob/general', ['name' => 'Bob B', 'email' => 'bob@example.com', 'language' => 'en'])
        ->assertRedirect('/account#general');
    $this->fake->assertRan('v-change-user-name');
});

it('does not ask an administrator who changes another account, nor while impersonating', function () {
    $this->fake->on('v-change-user-password')->returns();
    $form = ['name' => 'Bob Builder', 'email' => 'bob@example.com', 'language' => 'en', 'password' => 'Better-123'];

    signIn('arcadmin', 'admin');
    $this->post('/users/bob/general', $form)->assertRedirect('/users/bob/edit#general');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->post('/users/bob/general', $form)->assertRedirect('/account#general');

    $this->fake->assertRan('v-change-user-password', null, 2);
});

it('ends sudo mode after ten minutes', function () {
    signIn('bob', 'user', ['SUDO_UNTIL' => time() + SudoMode::SECONDS]);
    $this->fake->on('v-change-user-contact')->returns();
    $form = ['name' => 'Bob Builder', 'email' => 'new@example.com', 'language' => 'en'];

    $this->travel(SudoMode::SECONDS + 60)->seconds();

    $this->post('/users/bob/general', $form)->assertRedirect(route('sudo.show'));
    $this->fake->assertNotRan('v-change-user-contact');
});

it('refuses a wrong password, logs it and limits the attempts like a login', function () {
    $session = signIn('bob', 'user');
    bobHasPassword($this->fake);

    $this->post(route('sudo.store'), ['password' => 'nope'])
        ->assertRedirect(route('sudo.show'))->assertSessionHas('error', 'Invalid username or password');
    expect($session->data())->not->toHaveKey('SUDO_UNTIL');
    $this->fake->assertRan('v-log-user-login', fn ($a) => $a[0] === 'bob' && $a[2] === 'failed');

    foreach (range(2, LoginThrottle::PAIR_ATTEMPTS) as $attempt) {
        $this->post(route('sudo.store'), ['password' => 'nope']);
    }
    $this->post(route('sudo.store'), ['password' => 'pw'])->assertStatus(429)->assertSee('Too many attempts');
    expect($session->data())->not->toHaveKey('SUDO_UNTIL');
});

it('checks the password of the login user, not of the impersonated one', function () {
    $session = signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->fake->on('v-get-user-salt', fn ($a) => $a[0] === 'arcadmin')->returnsJson(['arcadmin' => ['METHOD' => 'des', 'SALT' => 'ab']]);
    $this->fake->on('v-check-user-hash', fn ($a) => $a[0] === 'arcadmin')->using(fn ($a) => $a[1] === crypt('admin-pw', 'ab') ? [0, ''] : [9, 'Error']);

    $this->post(route('sudo.store'), ['password' => 'admin-pw'])->assertRedirect();
    expect($session->data()['SUDO_UNTIL'] ?? 0)->toBeGreaterThan(time());
});

it('sends a guest to the login page', function () {
    $this->get(route('sudo.show'))->assertRedirect(route('login'));
});
