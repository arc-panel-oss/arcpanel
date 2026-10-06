<?php

use App\Services\Mail\PasswordResetMailer;
use Illuminate\Support\Facades\RateLimiter;

// Password reset and 2FA reset (E7 batch 3 spec §11).

const RESET_SENT = 'Password reset instructions have been sent to the email address associated with this account.';

beforeEach(function () {
    $this->fake = fakeArc();
    $this->rkeyexp = '';
    $this->fake->on('v-get-user-value')->using(fn (array $a) => [0, $this->rkeyexp."\n"]);
    $this->fake->on('v-change-user-rkey')->returns();
    $this->fake->on('v-log-user-login')->returns();
    $this->mails = [];
    $mails = &$this->mails;
    app()->instance(PasswordResetMailer::class, new class($mails) implements PasswordResetMailer
    {
        public function __construct(private array &$mails) {}

        public function sendPasswordReset(string $to, string $name, string $username, string $code, string $language, string $host): void
        {
            $this->mails[] = compact('to', 'name', 'username', 'code', 'language', 'host');
        }
    });
    RateLimiter::clear('reset:ip:127.0.0.1');
    foreach (['bob', 'carol', 'nobody'] as $user) {
        RateLimiter::clear('reset:user:'.$user);
    }
});

it('sends a code only to the contact address of an active user, with the same answer every time', function () {
    fakeServerUsers($this->fake, ['carol' => ['SUSPENDED' => 'yes', 'CONTACT' => 'carol@example.com']]);

    $this->get('/reset')->assertOk()->assertSee('Forgot Password');

    foreach ([['nobody', 'x@example.com'], ['bob', 'wrong@example.com'], ['carol', 'carol@example.com'], ['../etc', 'x@example.com']] as [$user, $email]) {
        $this->post('/reset', ['user' => $user, 'email' => $email])
            ->assertRedirect(route('reset.code', ['user' => $user]))->assertSessionHas('ok', RESET_SENT);
    }
    $this->fake->assertNotRan('v-change-user-rkey');
    expect($this->mails)->toBe([]);

    $this->post('/reset', ['user' => 'bob', 'email' => 'BOB@example.com'])->assertSessionHas('ok', RESET_SENT);
    $hash = $this->fake->calls('v-change-user-rkey')[0]['args'][1];
    expect($this->fake->calls('v-change-user-rkey')[0]['args'][0])->toBe('bob')
        ->and($this->mails)->toHaveCount(1)
        ->and($this->mails[0]['to'])->toBe('bob@example.com')
        ->and($this->mails[0]['host'])->toBe('localhost')
        ->and(password_verify($this->mails[0]['code'], $hash))->toBeTrue()
        ->and(strlen($this->mails[0]['code']))->toBe(12);
});

it('sends no second code within 15 minutes, without saying so', function () {
    fakeServerUsers($this->fake);
    $this->rkeyexp = (string) (time() - 60);

    $this->post('/reset', ['user' => 'bob', 'email' => 'bob@example.com'])->assertSessionHas('ok', RESET_SENT);
    $this->fake->assertNotRan('v-change-user-rkey');
});

it('changes the password with a valid code and makes the code unusable', function () {
    fakeServerUsers($this->fake, ['bob' => ['RKEY' => password_hash('Code12345678', PASSWORD_DEFAULT)]]);
    $this->rkeyexp = (string) (time() - 60);
    $this->fake->on('v-change-user-password')->returns();

    $this->get('/reset/confirm?user=bob&code=Code12345678')->assertOk()->assertSee('New Password')->assertSee('value="Code12345678"', false);

    $this->post('/reset/confirm', ['user' => 'bob', 'code' => 'Code12345678', 'password' => 'N3w-Passw0rd', 'password_confirm' => 'other'])
        ->assertSessionHas('error', 'Passwords do not match');
    $this->fake->assertNotRan('v-change-user-password');

    $this->post('/reset/confirm', ['user' => 'bob', 'code' => 'Code12345678', 'password' => 'N3w-Passw0rd', 'password_confirm' => 'N3w-Passw0rd'])
        ->assertRedirect(route('login'))->assertSessionHas('ok', 'Your password has been changed. You can now log in.');
    $this->fake->assertRan('v-change-user-password', fn ($a) => $a === ['bob', 'N3w-Passw0rd']);
    // a fresh random key replaces the hash of the used code
    $this->fake->assertRan('v-change-user-rkey', fn ($a) => $a === ['bob']);
});

it('refuses a wrong or expired code and logs it', function () {
    fakeServerUsers($this->fake, ['bob' => ['RKEY' => password_hash('Code12345678', PASSWORD_DEFAULT)]]);
    $this->fake->on('v-change-user-password')->returns();
    $form = ['user' => 'bob', 'password' => 'N3w-Passw0rd', 'password_confirm' => 'N3w-Passw0rd'];

    $this->rkeyexp = (string) (time() - 60);
    $this->post('/reset/confirm', $form + ['code' => 'wrong'])->assertSessionHas('error', 'Invalid username or code');
    $this->fake->assertRan('v-log-user-login', fn ($a) => $a[0] === 'bob' && $a[2] === 'failed' && $a[6] === 'Invalid Username or Code');

    $this->rkeyexp = (string) (time() - 1000);
    $this->post('/reset/confirm', $form + ['code' => 'Code12345678'])->assertSessionHas('error', 'Code has been expired');
    $this->fake->assertRan('v-log-user-login', fn ($a) => $a[6] === 'Reset code has been expired');

    $this->post('/reset/confirm', ['user' => 'nobody', 'code' => 'x'] + $form)->assertSessionHas('error', 'Invalid username or code');
    $this->fake->assertNotRan('v-change-user-password');
});

it('limits the requests per address and per user name', function () {
    fakeServerUsers($this->fake);
    $this->rkeyexp = (string) (time() - 60);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/reset', ['user' => 'nobody', 'email' => 'x@example.com'])->assertRedirect();
    }
    $this->post('/reset', ['user' => 'nobody', 'email' => 'x@example.com'])->assertStatus(429)->assertSee('Too many attempts. Try again in 15 minutes.');
    $this->post('/reset2fa', ['user' => 'bob', 'twofa' => 'x'])->assertStatus(429);
});

it('turns 2FA off with the reset code only', function () {
    fakeServerUsers($this->fake, ['bob' => ['TWOFA' => 'ABCDEF123456']]);
    $this->fake->on('v-delete-user-2fa')->returns();

    $this->get('/reset2fa')->assertOk()->assertSee('Unlock Account')->assertSee('2FA Reset Code');

    $this->post('/reset2fa', ['user' => 'bob', 'twofa' => 'WRONG'])
        ->assertRedirect(route('reset2fa'))->assertSessionHas('error', 'Invalid username or code');
    $this->fake->assertRan('v-log-user-login', fn ($a) => $a[6] === 'Failed to enter correct 2FA reset key');
    $this->fake->assertNotRan('v-delete-user-2fa');

    $this->post('/reset2fa', ['user' => 'bob', 'twofa' => 'ABCDEF123456'])->assertOk()->assertSee('Account Unlocked');
    $this->fake->assertRan('v-delete-user-2fa', fn ($a) => $a === ['bob']);
});

it('answers 404 when password reset is off', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_PASSWORD_RESET' => 'no']);

    $this->get('/reset')->assertNotFound();
    $this->post('/reset', ['user' => 'bob', 'email' => 'bob@example.com'])->assertNotFound();
    $this->get('/reset/confirm?user=bob&code=x')->assertNotFound();
    $this->fake->assertNotRan('v-change-user-rkey');
});
