<?php

use App\Arc\ExitCode;
use App\Auth\Role;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->fake = fakeArc(['IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail']);
    fakeServerUsers($this->fake);
    fakeMailDomains($this->fake);

    // The mail commands of a change, in the order they ran
    $this->mailCalls = fn () => array_values(array_filter(
        $this->fake->calls(),
        fn (array $call) => preg_match('/^v-(add|change|delete)-(mail|letsencrypt)/', $call['command']) === 1,
    ));
});

/** Mail systems of the server: IMAP and webmail always, spam filter and anti-virus on demand. */
function withMailSystems($fake, array $config = []): void
{
    withSysConfig($fake, array_merge(['IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail'], $config));
}

/** v-list-mail-domain of bob's example.com with these keys changed. */
function fakeMailDomainRow($fake, array $overrides, string $domain = 'example.com'): void
{
    $row = commandFixture('v-list-mail-domain/bob-example.com.json')['example.com'];
    $fake->on('v-list-mail-domain', ['bob', $domain, 'json'])->returnsJson([$domain => array_merge($row, $overrides)]);
}

/** The fields of the general tab for bob's example.com, unchanged. */
function mailGeneralForm(array $overrides = []): array
{
    return array_merge(['webmail' => 'roundcube', 'catchall' => '', 'rate' => '200', 'antispam' => '1', 'dkim' => '1'], $overrides);
}

// Domains list (spec §9.2)

it('lists only the mail domains of a user', function () {
    signIn('bob', 'user');

    $this->get('/mail')->assertOk()
        ->assertSee('example.com')->assertSee('shop.example')
        ->assertDontSee('carol.example')->assertDontSee('All owners')
        ->assertSee('/mail/bob/example.com/accounts')->assertSee('/mail/bob/example.com/accounts/create')
        ->assertSee('/mail/bob/example.com/edit')->assertSee('/mail/bob/example.com/dns')
        ->assertDontSee('/mail/bob/shop.example/edit')
        // Webmail: on for example.com, off for the suspended shop.example
        ->assertSee('http://webmail.example.com/', false)->assertDontSee('webmail.shop.example/', false)
        ->assertSee('Are you sure you want to suspend domain example.com?')
        ->assertSee('Are you sure you want to unsuspend domain shop.example?')
        ->assertSee('Are you sure you want to delete domain example.com?')
        ->assertSee('Enabled')->assertSee('Disabled')->assertSee('12 MB')
        ->assertDontSee('value="rebuild"', false);
});

it('hides the webmail link when the server has no webmail', function () {
    withSysConfig($this->fake, ['IMAP_SYSTEM' => 'dovecot']);
    signIn('bob', 'user');

    $this->get('/mail')->assertOk()->assertDontSee('Open Webmail');
});

it('lists the mail domains of every user for an admin, with an owner filter and rebuild', function () {
    signIn('arcadmin', 'admin');

    $this->get('/mail')->assertOk()
        ->assertSee('All owners')->assertSee('value="rebuild"', false)
        ->assertSeeInOrder(['carol.example', 'example.com', 'shop.example']);
    $this->get('/mail?owner=carol')->assertOk()->assertSee('carol.example')->assertDontSee('shop.example');
    $this->get('/mail?owner=ghost')->assertNotFound();
});

it('scopes the list of a reseller to its tree', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/mail')->assertOk()->assertSee('example.com')->assertDontSee('carol.example');
    $this->get('/mail?owner=carol')->assertNotFound();
    $this->get('/mail/carol/carol.example/accounts')->assertNotFound();
    $this->get('/mail/carol/carol.example/edit')->assertNotFound();
    $this->get('/mail/carol/carol.example/dns')->assertNotFound();
    $this->get('/mail/bob/example.com/accounts')->assertOk();
});

it('answers 404 for another user\'s domain and for unknown domains', function () {
    signIn('bob', 'user');

    $this->get('/mail/carol/carol.example/accounts')->assertNotFound();
    $this->get('/mail/carol/carol.example/edit')->assertNotFound();
    $this->get('/mail/bob/nope.example/edit')->assertNotFound();
    $this->get('/mail/bob/nope.example/accounts')->assertNotFound();
});

it('shows an empty state without mail domains', function () {
    signIn('arcadmin', 'admin');

    $this->get('/mail?owner=arcadmin')->assertOk()->assertSee('No mail domains for this owner.');
});

it('links mail domains and accounts from the command palette and the sidebar', function () {
    $this->fake->on('v-search-object')->returnsJson([]);
    $this->fake->on('v-search-object', ['example', 'json'])->returnsJson([
        '1' => ['ID' => '1', 'USER' => 'bob', 'TYPE' => 'mail', 'KEY' => 'DOMAIN', 'RESULT' => 'example.com', 'ALIAS' => '',
            'LINK' => '', 'PARENT' => '', 'SUSPENDED' => 'no', 'TIME' => '10:05:00', 'DATE' => '2026-10-06'],
        '2' => ['ID' => '2', 'USER' => 'bob', 'TYPE' => 'mail', 'KEY' => 'ACCOUNT', 'RESULT' => 'info@example.com', 'ALIAS' => '',
            'LINK' => 'info', 'PARENT' => 'example.com', 'SUSPENDED' => 'no', 'TIME' => '10:05:00', 'DATE' => '2026-10-06'],
    ]);
    signIn('arcadmin', 'admin');

    $urls = collect(collect($this->getJson('/_palette?q=example')->assertOk()->json('groups'))->keyBy('label')['Mail']['items'])->pluck('url');
    expect($urls)->toContain(route('mail.accounts.index', ['user' => 'bob', 'domain' => 'example.com']))
        ->toContain(route('mail.accounts.edit', ['user' => 'bob', 'domain' => 'example.com', 'account' => 'info']));

    $pages = collect($this->getJson('/_palette?q=mail')->json('groups'))->keyBy('label');
    expect(collect($pages['Pages and actions']['items'])->pluck('url'))->toContain(route('mail.create'), route('mail.index'));
});

// Add domain (spec §9.2)

it('shows the add form with the legacy defaults', function () {
    signIn('bob', 'user');

    $this->get('/mail/create')->assertOk()
        ->assertSee('name="webmail"', false)->assertSee('<option value="roundcube" selected', false)->assertSee('Snappymail')
        ->assertSee('<option value="disabled"', false)
        ->assertSee('name="dkim"', false)->assertSee('name="relay_host"', false)
        // Spam filter and anti-virus need their systems
        ->assertDontSee('name="antispam"', false)->assertDontSee('name="reject"', false)->assertDontSee('name="antivirus"', false)
        ->assertDontSee('strongly advised');
});

it('shows spam filter, reject spam and anti-virus with their systems, checked', function () {
    withMailSystems($this->fake, ['ANTISPAM_SYSTEM' => 'spamassassin', 'ANTIVIRUS_SYSTEM' => 'clamav-daemon']);
    signIn('bob', 'user');

    $this->get('/mail/create')->assertOk()
        ->assertSee('name="antispam"', false)->assertSee('name="reject"', false)->assertSee('name="antivirus"', false);
});

it('adds a domain with the legacy arguments, then the webmail client', function () {
    $this->fake->on('v-add-mail-domain')->returns();
    $this->fake->on('v-add-mail-domain-webmail')->returns();
    signIn('bob', 'user');

    $this->post('/mail', ['domain' => 'WWW.New.Example', 'webmail' => 'roundcube', 'dkim' => '1', 'antispam' => '1', 'reject' => '1', 'antivirus' => '1'])
        ->assertRedirect('/mail/bob/new.example/accounts')
        ->assertSessionHas('ok', 'Mail domain new.example has been created successfully.');

    // Without spam filter and anti-virus systems their boxes count as unticked
    expect(($this->mailCalls)())->toBe([
        ['command' => 'v-add-mail-domain', 'args' => ['bob', 'new.example', 'no', 'no', 'yes']],
        ['command' => 'v-add-mail-domain-webmail', 'args' => ['bob', 'new.example', 'roundcube', 'yes']],
    ]);
});

it('adds reject spam after the domain when the spam filter is on', function () {
    withMailSystems($this->fake, ['ANTISPAM_SYSTEM' => 'spamassassin', 'ANTIVIRUS_SYSTEM' => 'clamav-daemon']);
    foreach (['v-add-mail-domain', 'v-add-mail-domain-reject', 'v-add-mail-domain-webmail'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/mail', ['domain' => 'new.example', 'webmail' => 'snappymail', 'antispam' => '1', 'reject' => '1', 'antivirus' => '1'])
        ->assertRedirect('/mail/bob/new.example/accounts');

    expect(($this->mailCalls)())->toBe([
        ['command' => 'v-add-mail-domain', 'args' => ['bob', 'new.example', 'yes', 'yes', 'no']],
        ['command' => 'v-add-mail-domain-reject', 'args' => ['bob', 'new.example']],
        ['command' => 'v-add-mail-domain-webmail', 'args' => ['bob', 'new.example', 'snappymail', 'yes']],
    ]);
});

it('deletes the webmail of a new domain for "Disabled"', function () {
    $this->fake->on('v-add-mail-domain')->returns();
    $this->fake->on('v-delete-mail-domain-webmail')->returns();
    signIn('bob', 'user');

    $this->post('/mail', ['domain' => 'new.example', 'webmail' => 'disabled', 'dkim' => '1'])->assertRedirect();
    $this->fake->assertRan('v-delete-mail-domain-webmail', fn ($a) => $a === ['bob', 'new.example', 'yes']);
});

it('skips the webmail client without an IMAP system', function () {
    withSysConfig($this->fake, ['WEBMAIL_SYSTEM' => 'roundcube']);
    $this->fake->on('v-add-mail-domain')->returns();
    signIn('bob', 'user');

    $this->get('/mail/create')->assertOk()->assertDontSee('name="webmail"', false);
    $this->post('/mail', ['domain' => 'other.example', 'webmail' => 'roundcube'])->assertRedirect();
    $this->fake->assertNotRan('v-add-mail-domain-webmail');
});

it('adds the SMTP relay with the password in a file', function () {
    foreach (['v-add-mail-domain', 'v-add-mail-domain-webmail', 'v-add-mail-domain-smtp-relay'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/mail', [
        'domain' => 'new.example', 'webmail' => 'roundcube', 'relay' => '1',
        'relay_host' => 'smtp.relay.example', 'relay_user' => 'me', 'relay_password' => 'p4ss word',
    ])->assertRedirect('/mail/bob/new.example/accounts');

    $this->fake->assertRan('v-add-mail-domain-smtp-relay', fn ($a) => $a === ['bob', 'new.example', 'smtp.relay.example', 'me', 'p4ss word', '587']);
});

it('refuses an unknown webmail client and validates the domain', function () {
    signIn('bob', 'user');

    $this->post('/mail', ['domain' => 'new.example', 'webmail' => 'squirrel'])->assertSessionHasErrors(['webmail']);
    $this->post('/mail', ['domain' => ''])->assertSessionHasErrors(['domain' => 'Field "Domain" can not be blank.']);
    $this->post('/mail', ['domain' => 'new.example', 'relay_port' => 'abc'])->assertSessionHasErrors(['relay_port']);
    $this->fake->assertNotRan('v-add-mail-domain');
});

it('keeps the input and shows the command error when the domain cannot be added', function () {
    $this->fake->on('v-add-mail-domain')->returnsExit(ExitCode::Exists, 'Error: mail domain new.example exists');
    signIn('bob', 'user');

    $this->from('/mail/create')->post('/mail', ['domain' => 'new.example', 'webmail' => 'roundcube', 'relay_password' => 'secret'])
        ->assertRedirect('/mail/create')
        ->assertSessionHas('error', 'Error: mail domain new.example exists')
        ->assertSessionHasInput('domain', 'new.example')->assertSessionMissing('_old_input.relay_password');
    $this->fake->assertNotRan('v-add-mail-domain-webmail');
});

it('shows the domain with the failed step when a later step of the add fails', function () {
    $this->fake->on('v-add-mail-domain')->returns();
    $this->fake->on('v-add-mail-domain-webmail')->returnsExit(ExitCode::Invalid, 'Error: invalid webmail');
    signIn('bob', 'user');

    $this->post('/mail', ['domain' => 'new.example', 'webmail' => 'roundcube'])
        ->assertRedirect('/mail/bob/new.example/accounts')
        ->assertSessionHas('error', 'Change the webmail client failed')
        ->assertSessionHas('error_detail', 'Error: invalid webmail');
});

it('lets an admin add a domain for another user and warns about its own account (D11)', function () {
    $this->fake->on('v-add-mail-domain')->returns();
    $this->fake->on('v-add-mail-domain-webmail')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/mail/create')->assertOk()->assertSee('strongly advised')->assertSee('a mail domain')->assertSee('Use this owner');
    $this->get('/mail/create?owner=carol')->assertOk()->assertDontSee('strongly advised');
    $this->post('/mail', ['owner' => 'carol', 'domain' => 'new.example', 'webmail' => 'roundcube'])->assertRedirect('/mail/carol/new.example/accounts');
    $this->fake->assertRan('v-add-mail-domain', fn ($a) => $a[0] === 'carol');
});

it('does not let a user add a domain for someone else', function () {
    signIn('bob', 'user');

    $this->get('/mail/create?owner=carol')->assertNotFound();
    $this->post('/mail', ['owner' => 'carol', 'domain' => 'new.example'])->assertNotFound();
    $this->fake->assertNotRan('v-add-mail-domain');
});

// Edit domain: general tab (spec §9.3)

it('shows the edit tabs with the domain values', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/edit')->assertOk()
        ->assertSee('/mail/bob/example.com/edit/general')->assertSee('/mail/bob/example.com/edit/ssl')->assertSee('/mail/bob/example.com/edit/relay')
        ->assertSee('<option value="roundcube" selected', false)
        ->assertSee('name="catchall"', false)->assertSee('value="200"', false)
        ->assertSee('ECDSA P-256 (recommended)')->assertSee('RSA 4096')
        ->assertSee('To enable Let&#039;s Encrypt SSL, ensure that DNS records exist for mail.example.com and webmail.example.com!', false);
});

it('disables the rate limit for users and enables it for administrators', function () {
    signIn('bob', 'user');
    expect($this->get('/mail/bob/example.com/edit')->assertOk()->getContent())->toMatch('/<input(?=[^>]*name="rate")(?=[^>]* disabled)[^>]*>/');

    signIn('arcadmin', 'admin');
    expect($this->get('/mail/bob/example.com/edit')->assertOk()->getContent())->not->toMatch('/<input(?=[^>]*name="rate")(?=[^>]* disabled)[^>]*>/');
});

it('runs nothing for an unchanged general tab', function () {
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm())
        ->assertRedirect('/mail/bob/example.com/edit#general')->assertSessionHas('ok', 'Nothing has changed.');
    expect(($this->mailCalls)())->toBe([]);
});

it('runs the changed general steps in the legacy order', function () {
    withMailSystems($this->fake, ['ANTISPAM_SYSTEM' => 'spamassassin', 'ANTIVIRUS_SYSTEM' => 'clamav-daemon']);
    fakeMailDomainRow($this->fake, ['CATCHALL' => 'old@example.net']);
    foreach (['v-add-mail-domain-antivirus', 'v-delete-mail-domain-dkim', 'v-add-mail-domain-reject', 'v-change-mail-domain-catchall', 'v-add-mail-domain-webmail'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/general', [
        'webmail' => 'snappymail', 'catchall' => 'new@example.net', 'rate' => '50', 'antispam' => '1', 'reject' => '1', 'antivirus' => '1',
    ])->assertRedirect('/mail/bob/example.com/edit#general')->assertSessionHas('ok', 'Changes have been saved.');

    // A user's rate limit is ignored (web/edit/mail: userContext admin)
    expect(($this->mailCalls)())->toBe([
        ['command' => 'v-add-mail-domain-antivirus', 'args' => ['bob', 'example.com']],
        ['command' => 'v-delete-mail-domain-dkim', 'args' => ['bob', 'example.com']],
        ['command' => 'v-add-mail-domain-reject', 'args' => ['bob', 'example.com']],
        ['command' => 'v-change-mail-domain-catchall', 'args' => ['bob', 'example.com', 'new@example.net']],
        ['command' => 'v-add-mail-domain-webmail', 'args' => ['bob', 'example.com', 'snappymail', 'yes']],
    ]);
});

it('deletes and adds the catch-all address', function () {
    fakeMailDomainRow($this->fake, ['CATCHALL' => 'old@example.net']);
    $this->fake->on('v-delete-mail-domain-catchall')->returns();
    $this->fake->on('v-add-mail-domain-catchall')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['catchall' => '']))->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-mail-domain-catchall', fn ($a) => $a === ['bob', 'example.com']);

    fakeMailDomainRow($this->fake, ['CATCHALL' => '']);
    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['catchall' => 'all@example.net']))->assertSessionHas('ok');
    $this->fake->assertRan('v-add-mail-domain-catchall', fn ($a) => $a === ['bob', 'example.com', 'all@example.net']);
});

it('changes the rate limit for administrators only, "system" when emptied', function () {
    $this->fake->on('v-change-mail-domain-rate-limit')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['rate' => '']))->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-mail-domain-rate-limit', fn ($a) => $a === ['bob', 'example.com', 'system']);

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['rate' => '75']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-mail-domain-rate-limit', fn ($a) => $a === ['bob', 'example.com', '75']);
});

it('does not let a reseller or an administrator acting as a user change the rate limit', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');
    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['rate' => '75']))->assertSessionHas('ok', 'Nothing has changed.');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['rate' => '75']))->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-change-mail-domain-rate-limit');
});

it('runs the webmail command only when the client changed, and deletes it for "Disabled" (spec §11)', function () {
    $this->fake->on('v-delete-mail-domain-webmail')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['webmail' => 'roundcube']))->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-add-mail-domain-webmail');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['webmail' => 'disabled']))->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-mail-domain-webmail', fn ($a) => $a === ['bob', 'example.com', 'yes']);
    $this->fake->assertNotRan('v-add-mail-domain-webmail');
});

it('refuses an unknown webmail client on the general tab', function () {
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['webmail' => 'squirrel']))
        ->assertRedirect('/mail/bob/example.com/edit#general')->assertSessionHasErrors(['webmail'], null, 'general');
    expect(($this->mailCalls)())->toBe([]);
});

it('names the failed step of a general change', function () {
    $this->fake->on('v-delete-mail-domain-dkim')->returnsExit(ExitCode::Invalid, 'Error: dkim failed');
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/general', mailGeneralForm(['dkim' => null]))
        ->assertRedirect('/mail/bob/example.com/edit#general')
        ->assertSessionHas('error', 'Disable DKIM failed')->assertSessionHas('error_detail', 'Error: dkim failed');
});

// Edit domain: SSL tab

it('shows the certificate details without the private key', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/shop.example/edit')->assertOk()
        ->assertSee('mail.shop.example, webmail.shop.example')->assertSee('256 bit')->assertSee('ecdsa-with-SHA256')
        ->assertSee('<option value="ecdsa-256" selected', false)
        ->assertSee('takes two saves')
        ->assertDontSee('SECRETKEYshop');
});

it('installs an uploaded certificate and requires certificate and key', function () {
    $this->fake->on('v-add-mail-domain-ssl')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'certificate' => '', 'key' => ''])
        ->assertRedirect('/mail/bob/example.com/edit#ssl')
        ->assertSessionHasErrors([
            'certificate' => 'Field "SSL Certificate" can not be blank.',
            'key' => 'Field "SSL Private Key" can not be blank.',
        ], null, 'ssl');
    $this->fake->assertNotRan('v-add-mail-domain-ssl');

    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'certificate' => "CRT\r\n", 'key' => 'KEY'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-mail-domain-ssl', fn ($a) => $a[0] === 'bob' && json_decode($a[2], true) === ['example.com.crt' => 'CRT', 'example.com.key' => 'KEY'] && $a[3] === 'no');
});

it('replaces an uploaded certificate whose text changed', function () {
    fakeMailDomainRow($this->fake, ['SSL' => 'yes']);
    $this->fake->on('v-list-mail-domain-ssl', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => ['CRT' => 'OLD', 'KEY' => 'OLDKEY', 'CA' => '']]);
    $this->fake->on('v-change-mail-domain-sslcert')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'certificate' => 'OLD'])->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'certificate' => 'NEW'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-mail-domain-sslcert', fn ($a) => json_decode($a[2], true) === ['example.com.crt' => "NEW\n", 'example.com.key' => "OLDKEY\n"] && $a[3] === 'no');
});

it('issues and reissues Let\'s Encrypt with the key algorithm', function () {
    $this->fake->on('v-add-letsencrypt-domain')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'letsencrypt' => '1'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-letsencrypt-domain', fn ($a) => $a === ['bob', 'example.com', '', 'yes', 'ecdsa-256']);

    fakeMailDomainRow($this->fake, ['SSL' => 'yes', 'LETSENCRYPT' => 'yes', 'SSL_KEY_ALGO' => 'ecdsa-256']);
    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'letsencrypt' => '1', 'key_algo' => 'ecdsa-256'])->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'letsencrypt' => '1', 'key_algo' => 'ecdsa-384'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-letsencrypt-domain', fn ($a) => $a === ['bob', 'example.com', '', 'yes', 'ecdsa-384']);
    $this->fake->assertRan('v-add-letsencrypt-domain', times: 2);
});

it('deletes Let\'s Encrypt, and an uploaded certificate, when switched off', function () {
    $this->fake->on('v-delete-letsencrypt-domain')->returns();
    $this->fake->on('v-delete-mail-domain-ssl')->returns();
    signIn('bob', 'user');

    fakeMailDomainRow($this->fake, ['SSL' => 'yes', 'LETSENCRYPT' => 'yes']);
    $this->post('/mail/bob/example.com/edit/ssl', ['ssl' => '1', 'certificate' => 'MINE', 'key' => 'MYKEY'])->assertSessionHas('ok');
    // Switching to an uploaded certificate takes a second save
    expect(($this->mailCalls)())->toBe([['command' => 'v-delete-letsencrypt-domain', 'args' => ['bob', 'example.com', '', 'yes']]]);

    fakeMailDomainRow($this->fake, ['SSL' => 'yes']);
    $this->post('/mail/bob/example.com/edit/ssl', [])->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-mail-domain-ssl', fn ($a) => $a === ['bob', 'example.com']);
});

// Edit domain: SMTP relay tab

it('adds the relay again only when it changed or a password was entered', function () {
    $this->fake->on('v-add-mail-domain-smtp-relay')->returns();
    signIn('bob', 'user');
    $relay = ['relay' => '1', 'relay_host' => 'smtp.relay.example', 'relay_port' => '587', 'relay_user' => 'relayuser'];

    $this->post('/mail/bob/shop.example/edit/relay', $relay)->assertRedirect('/mail/bob/shop.example/edit#relay')->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/mail/bob/shop.example/edit/relay', $relay + ['relay_password' => 'n3w'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-mail-domain-smtp-relay', fn ($a) => $a === ['bob', 'shop.example', 'smtp.relay.example', 'relayuser', 'n3w', '587']);
    $this->post('/mail/bob/shop.example/edit/relay', array_merge($relay, ['relay_port' => '2525']))->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-mail-domain-smtp-relay', fn ($a) => $a[5] === '2525');
});

it('deletes the relay when switched off', function () {
    $this->fake->on('v-delete-mail-domain-smtp-relay')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/edit/relay', [])->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-delete-mail-domain-smtp-relay');
    $this->post('/mail/bob/shop.example/edit/relay', [])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-mail-domain-smtp-relay', fn ($a) => $a === ['bob', 'shop.example']);
});

it('never shows the relay password', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/shop.example/edit')->assertOk()
        ->assertSee('value="smtp.relay.example"', false)->assertSee('value="relayuser"', false)
        ->assertDontSee('name="relay_password" value', false);
});

// DNS hints (spec §9.2)

it('shows the DNS records of a mail domain with the DKIM key', function () {
    $this->fake->on('v-list-dns-domains')->returnsJson([]);
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/dns')->assertOk()
        ->assertSee('value="mail.example.com"', false)
        ->assertSee('value="webmail.example.com"', false)
        ->assertSee('value="203.0.113.10"', false)
        ->assertSee('value="mail.example.com."', false)
        ->assertSee('value="v=spf1 a mx ip4:203.0.113.10 -all"', false)
        ->assertSee('value="_dmarc"', false)->assertSee('value="v=DMARC1; p=quarantine; pct=100"', false)
        ->assertSee('value="mail._domainkey"', false)
        ->assertSee('value="v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA8uepPZ6GNeJDbBrOCh2alQIDAQAB"', false)
        ->assertSeeInOrder(['14400', '14400', '10', '14400', '3600'])
        ->assertDontSee('Open DNS zone');
});

it('leaves out the webmail record without a webmail system and links the DNS zone of the same name', function () {
    withSysConfig($this->fake, ['IMAP_SYSTEM' => 'dovecot']);
    fakeDnsZones($this->fake);
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/dns')->assertOk()
        ->assertDontSee('value="webmail.example.com"', false)
        ->assertSee('Open DNS zone')->assertSee(route('dns.records.index', ['user' => 'bob', 'domain' => 'example.com']));
});

// Domain actions (spec §9.1)

it('suspends, unsuspends and deletes a domain', function () {
    foreach (['v-suspend-mail-domain', 'v-unsuspend-mail-domain', 'v-delete-mail-domain'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->from('/mail')->post('/mail/bob/example.com/suspend')->assertRedirect('/mail')->assertSessionHas('ok', 'Mail domain example.com has been suspended.');
    $this->from('/mail')->post('/mail/bob/shop.example/unsuspend')->assertSessionHas('ok', 'Mail domain shop.example has been unsuspended.');
    $this->delete('/mail/bob/example.com')->assertRedirect('/mail')->assertSessionHas('ok', 'Mail domain example.com has been deleted.');

    $this->fake->assertRan('v-suspend-mail-domain', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertRan('v-unsuspend-mail-domain', fn ($a) => $a === ['bob', 'shop.example']);
    $this->fake->assertRan('v-delete-mail-domain', fn ($a) => $a === ['bob', 'example.com']);
    $this->delete('/mail/bob/nope.example')->assertNotFound();
});

it('runs a domain bulk action, reporting done and failed items', function () {
    $this->fake->on('v-suspend-mail-domain')->returns();
    $this->fake->on('v-suspend-mail-domain', ['bob', 'shop.example'])->returnsExit(ExitCode::Suspended, 'Error: mail domain shop.example is suspended');
    signIn('bob', 'user');

    $this->post('/mail/bulk', ['action' => 'suspend', 'items' => ['bob/example.com', 'bob/shop.example']])
        ->assertRedirect('/mail')->assertSessionHas('error', '1 done, 1 failed')
        ->assertSessionHas('error_detail', 'shop.example: Error: mail domain shop.example is suspended');
    $this->fake->assertRan('v-suspend-mail-domain', fn ($a) => $a === ['bob', 'example.com']);
});

it('runs nothing when one domain bulk item belongs to another user, and refuses rebuild to users', function () {
    signIn('bob', 'user');

    $this->post('/mail/bulk', ['action' => 'delete', 'items' => ['bob/example.com', 'carol/carol.example']])->assertNotFound();
    $this->post('/mail/bulk', ['action' => 'rebuild', 'items' => ['bob/example.com']])->assertForbidden();
    $this->post('/mail/bulk', ['action' => 'delete', 'items' => ['bob']])->assertSessionHasErrors(['items.0']);
    $this->fake->assertNotRan('v-delete-mail-domain');
    $this->fake->assertNotRan('v-rebuild-mail-domain');
});

it('lets an admin rebuild domains of several users', function () {
    $this->fake->on('v-rebuild-mail-domain')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/mail/bulk?owner=bob', ['action' => 'rebuild', 'items' => ['bob/example.com', 'carol/carol.example']])
        ->assertRedirect('/mail?owner=bob')->assertSessionHas('ok', '2 done, 0 failed');
    $this->fake->assertRan('v-rebuild-mail-domain', fn ($a) => $a === ['carol', 'carol.example']);
});

it('lets a reseller act on its customers\' domains only', function () {
    fakeResellers($this->fake);
    $this->fake->on('v-suspend-mail-domain')->returns();
    signIn('hostco', 'reseller');

    $this->post('/mail/bulk', ['action' => 'suspend', 'items' => ['bob/example.com']])->assertSessionHas('ok', '1 done, 0 failed');
    $this->post('/mail/bulk', ['action' => 'suspend', 'items' => ['carol/carol.example']])->assertNotFound();
    $this->post('/mail/bulk', ['action' => 'rebuild', 'items' => ['bob/example.com']])->assertForbidden();
    $this->post('/mail/carol/carol.example/suspend')->assertNotFound();
    $this->fake->assertRan('v-suspend-mail-domain', times: 1);
});

// Read-only mode and capabilities

it('refuses every domain change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes', 'IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/mail')->assertOk()->assertDontSee('Add Mail Domain');
    $this->get('/mail/bob/example.com/edit')->assertOk()->assertSee('Read-only');
    foreach (['edit/general', 'edit/ssl', 'edit/relay', 'suspend', 'unsuspend'] as $action) {
        $this->post("/mail/bob/example.com/{$action}")->assertForbidden();
    }
    $this->delete('/mail/bob/example.com')->assertForbidden();
    $this->post('/mail/bulk', ['action' => 'delete', 'items' => ['bob/example.com']])->assertForbidden();
    $this->post('/mail', ['domain' => 'new.example'])->assertForbidden();
    $this->fake->assertNotRan('v-delete-mail-domain');
});

it('has a capability for every mail route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'mail.'));

    expect($routes)->toContain('mail.index', 'mail.create', 'mail.store', 'mail.bulk', 'mail.edit', 'mail.update.general',
        'mail.update.ssl', 'mail.update.relay', 'mail.suspend', 'mail.unsuspend', 'mail.destroy', 'mail.dns',
        'mail.accounts.index', 'mail.accounts.create', 'mail.accounts.store', 'mail.accounts.bulk', 'mail.accounts.edit',
        'mail.accounts.update.general', 'mail.accounts.update.forwarding', 'mail.accounts.update.autoreply',
        'mail.accounts.suspend', 'mail.accounts.unsuspend', 'mail.accounts.destroy');
    foreach (['mail.view', 'mail.create', 'mail.update', 'mail.delete', 'mail.rate.edit'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
    expect(Capabilities::allows(Role::Reseller, 'mail.rate.edit'))->toBeFalse()
        ->and(Capabilities::allows(Role::Admin, 'mail.rate.edit'))->toBeTrue();
});
