<?php

use App\Audit\AuditWriter;
use App\Auth\SudoMode;

// Turning two-factor authentication on and recovery codes (E39 W5 spec §11.3).

const TF_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const TF_URI = 'otpauth://totp/ArcPanel%3Abob%40panel.example?secret='.TF_SECRET.'&issuer=ArcPanel&algorithm=SHA1&digits=6&period=30';

function tfCodes(): array
{
    return array_map(fn ($i) => sprintf('AAAA-BBBB-CCCC-DD%s%s', chr(65 + $i), chr(65 + $i)), range(0, 9));
}

/** bob in sudo mode, so the setup does not ask for the password first. */
function tfSignInSudo(array $session = []): void
{
    signIn('bob', 'user', [SudoMode::KEY => time() + SudoMode::SECONDS] + $session);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en']);
    $this->fake->on('v-list-user-ssh-keys')->returnsJson([]);
    $this->fake->on('v-list-apis')->returnsJson([]);
});

it('explains the setup on its own page and sends an account with 2FA back', function () {
    tfSignInSudo();
    $this->get('/account/two-factor')->assertOk()->assertSee('Set up')
        ->assertSee('action="'.route('account.two-factor.start').'"', false);

    fakeServerUsers($this->fake, ['bob' => ['TWOFA' => 'yes']]);
    $this->get('/account/two-factor')->assertRedirect(route('account.edit'));
});

it('asks for the password before it creates a secret', function () {
    signIn('bob', 'user');
    $this->post('/account/two-factor')->assertRedirect(route('sudo.show'));
    $this->fake->assertNotRan('v-add-user-2fa');
});

it('draws the QR code itself and keeps the secret out of the session', function () {
    tfSignInSudo();
    $this->fake->on('v-add-user-2fa', ['bob', 'json'])->returnsJson(['SECRET' => TF_SECRET, 'URI' => TF_URI]);

    $response = $this->post('/account/two-factor')->assertOk()
        ->assertSee('src="data:image/svg+xml;base64,', false)
        ->assertSee('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')
        ->assertSee('name="secret" value="'.TF_SECRET.'"', false)
        ->assertSee('action="'.route('account.two-factor.confirm').'"', false)
        ->assertDontSee('api.qrserver.com');
    $svg = base64_decode(explode('"', explode('data:image/svg+xml;base64,', $response->getContent())[1])[0]);
    expect($svg)->toStartWith('<?xml')->toContain('<svg');
    expect(json_encode(session()->all()))->not->toContain(TF_SECRET);
});

it('turns 2FA on with a right code and shows the recovery codes once, with a download', function () {
    tfSignInSudo();
    $this->fake->on('v-change-user-2fa', fn ($a) => $a === ['bob', '123456', 'json'])->returnsJson(['RECOVERY_CODES' => tfCodes()]);
    $this->fake->on('v-change-user-2fa', fn ($a) => $a[1] === '000000')->returnsExit(9, 'Error: Authentication token mismatch.');
    $form = ['secret' => TF_SECRET, 'uri' => TF_URI];

    $this->post('/account/two-factor/confirm', ['code' => '000000'] + $form)->assertOk()
        ->assertSee('The code is not right')->assertSee('src="data:image/svg+xml;base64,', false);

    $this->post('/account/two-factor/confirm', ['code' => '123 456'] + $form)->assertOk()
        ->assertSee('Two-factor authentication is on.')
        ->assertSee('AAAA-BBBB-CCCC-DDAA')->assertSee('AAAA-BBBB-CCCC-DDJJ')
        ->assertSee('href="data:text/plain;charset=utf-8,', false)
        ->assertSee('download="arcpanel-recovery-codes-bob.txt"', false);
    expect(json_encode(session()->all()))->not->toContain('AAAA-BBBB');

    // A URI that is not the one of the secret is refused before any command
    $this->post('/account/two-factor/confirm', ['code' => '123456', 'secret' => TF_SECRET, 'uri' => 'otpauth://totp/x?secret=OTHER'])->assertStatus(422);
    $this->fake->assertRan('v-change-user-2fa', times: 2);
    // The audit log has the wrong code and the change, not the setup routes (E23 §5.2)
    expect(array_map(fn ($e) => $e['action'].' '.$e['result'], app(AuditWriter::class)->events('account.2fa.enable')))
        ->toBe(['account.2fa.enable denied', 'account.2fa.enable ok']);
    expect(app(AuditWriter::class)->events('account.two-factor.confirm'))->toHaveCount(1);
});

it('creates new recovery codes only when 2FA is on', function () {
    tfSignInSudo();
    $this->fake->on('v-add-user-2fa-recovery-codes', ['bob', 'json'])->returnsJson(['RECOVERY_CODES' => tfCodes()]);

    $this->post('/account/two-factor/recovery-codes')->assertRedirect(route('account.edit'))->assertSessionHas('error');
    $this->fake->assertNotRan('v-add-user-2fa-recovery-codes');

    fakeServerUsers($this->fake, ['bob' => ['TWOFA' => 'yes', 'TWOFA_RECOVERY_LEFT' => '2']]);
    $this->post('/account/two-factor/recovery-codes')->assertOk()
        ->assertSee('The earlier codes no longer work.')->assertSee('AAAA-BBBB-CCCC-DDAA');
    expect(array_map(fn ($e) => $e['result'], app(AuditWriter::class)->events('account.2fa.recovery')))->toBe(['failed', 'ok']);
});

it('is for the login user only: not while impersonating and not in a read-only session', function () {
    signIn('arcadmin', 'admin', ['look' => 'bob', SudoMode::KEY => time() + SudoMode::SECONDS]);
    $this->get('/account/two-factor')->assertForbidden();
    $this->post('/account/two-factor')->assertForbidden();
    $this->fake->assertNotRan('v-add-user-2fa');

    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    tfSignInSudo();
    $this->post('/account/two-factor')->assertForbidden();
    $this->post('/account/two-factor/recovery-codes')->assertForbidden();
});

it('shows 2FA, the codes left and a warning without codes on the own security tab only', function () {
    tfSignInSudo();
    $this->get('/account')->assertOk()->assertSee('Turn on two-factor authentication')
        ->assertSee('href="'.route('account.two-factor').'"', false);

    fakeServerUsers($this->fake, ['bob' => ['TWOFA' => 'yes', 'TWOFA_RECOVERY_LEFT' => '0']]);
    $this->get('/account')->assertOk()->assertSee('Recovery codes left: 0.')
        ->assertSee('This account has no recovery codes.')->assertSee('Create new recovery codes');

    // An administrator editing bob sees the switch that turns it off, not the setup
    $this->fake->on('v-list-package-tenancy')->returnsJson(['default' => ['USERS' => '0']]);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-list-sys-php', ['json'])->returnsJson(['8.5']);
    signIn('arcadmin', 'admin');
    $this->get('/users/bob/edit')->assertOk()->assertSee('name="twofa_off"', false)
        ->assertDontSee('Create new recovery codes')->assertDontSee('Recovery codes left');
});

it('limits the codes typed during the setup', function () {
    tfSignInSudo();
    $this->fake->on('v-change-user-2fa')->returnsExit(9, 'Error');
    foreach (range(1, 10) as $attempt) {
        $this->post('/account/two-factor/confirm', ['code' => '000000', 'secret' => TF_SECRET, 'uri' => TF_URI])->assertOk();
    }
    $this->post('/account/two-factor/confirm', ['code' => '000000', 'secret' => TF_SECRET, 'uri' => TF_URI])->assertStatus(429);
});
