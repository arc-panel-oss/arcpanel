<?php

use App\Arc\ExitCode;

/*
 * Webmail single sign-on, panel side (E46,
 * docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md §7.5, §7.7).
 */

const SSO_TICKET = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_AbCdE';

beforeEach(function () {
    $this->fake = fakeArc([
        'IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail',
        'WEBMAIL_PORT' => '8086', 'WEBMAIL_LINK' => 'port',
    ]);
    fakeServerUsers($this->fake);
    fakeMailDomains($this->fake);
    $this->fake->on('v-add-mail-account-sso')->returns(SSO_TICKET."\n");
});

// Open Webmail (signed in) (§7.7)

it('offers the sign-on to whoever may change the account password', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts')->assertOk()
        ->assertSee('/mail/bob/example.com/accounts/info/webmail')
        // Not for the suspended account
        ->assertDontSee('/mail/bob/example.com/accounts/old/webmail')
        ->assertSee('Open Webmail (signed in)');
    $this->get('/mail/bob/example.com/accounts/info/edit')->assertOk()
        ->assertSee('/mail/bob/example.com/accounts/info/webmail')->assertSee('formtarget="_blank"', false);
});

it('posts a one-time ticket to Roundcube on the webmail port, never in the URL', function () {
    signIn('bob', 'user');

    $response = $this->post('/mail/bob/example.com/accounts/info/webmail');
    $response->assertOk()->assertHeaderMissing('Location')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('action="https://localhost:8086/?_task=login&amp;_action=arcpanel-sso"', false)
        ->assertSee('<input type="hidden" name="_ticket" value="'.SSO_TICKET.'">', false)
        ->assertSee('method="post"', false);
    $this->fake->assertRan('v-add-mail-account-sso', fn ($a) => $a === ['bob', 'example.com', 'info', 'bob', '127.0.0.1']);
});

it('names the administrator who signs on for a user', function () {
    signIn('arcadmin', 'admin', ['look' => 'bob']);

    $this->post('/mail/bob/example.com/accounts/info/webmail')->assertOk();
    $this->fake->assertRan('v-add-mail-account-sso', fn ($a) => $a === ['bob', 'example.com', 'info', 'arcadmin', '127.0.0.1']);
});

// Each case in its own test: the route keeps its controller between the requests of one test

it('has no sign-on for a suspended account', function () {
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/old/webmail')->assertNotFound();
    $this->fake->assertNotRan('v-add-mail-account-sso');
});

it('has no sign-on over plain HTTP: the domain link of a domain without SSL', function () {
    withSysConfig($this->fake, ['IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail', 'WEBMAIL_PORT' => '8086', 'WEBMAIL_LINK' => 'domain']);
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts')->assertOk()->assertDontSee('Open Webmail (signed in)');
    $this->post('/mail/bob/example.com/accounts/info/webmail')->assertNotFound();
    $this->fake->assertNotRan('v-add-mail-account-sso');
});

it('has no sign-on without Roundcube', function () {
    withSysConfig($this->fake, ['IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'snappymail', 'WEBMAIL_ALIAS' => 'webmail', 'WEBMAIL_PORT' => '8086']);
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/webmail')->assertNotFound();
    $this->fake->assertNotRan('v-add-mail-account-sso');
});

it('has no sign-on into the mailbox of another owner', function () {
    signIn('carol', 'user');

    $this->post('/mail/bob/example.com/accounts/info/webmail')->assertNotFound();
    $this->fake->assertNotRan('v-add-mail-account-sso');
});

it('refuses the sign-on in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes', 'IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_PORT' => '8086']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts/info/edit')->assertOk()->assertDontSee('Open Webmail (signed in)');
    $this->post('/mail/bob/example.com/accounts/info/webmail')->assertForbidden();
    $this->fake->assertNotRan('v-add-mail-account-sso');
});

// POST /sso/mail (§7.5)

it('exchanges a ticket for the session password on the loopback address only', function () {
    $this->fake->on('v-check-mail-account-sso')->returns('{"USER": "info@example.com", "PASSWORD": "session-pass"}');

    $this->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => '203.0.113.9'])->assertOk()
        ->assertExactJson(['user' => 'info@example.com', 'password' => 'session-pass'])
        ->assertHeader('Cache-Control', 'no-store, private');
    // The fake shows file secrets revealed: the ticket goes in a Secret file
    $this->fake->assertRan('v-check-mail-account-sso', fn ($a) => $a === [SSO_TICKET, '203.0.113.9', 'json']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => '203.0.113.9'])->assertNotFound();
    $this->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => '203.0.113.9'], ['X-Forwarded-For' => '203.0.113.9'])->assertNotFound();
    $this->fake->assertRan('v-check-mail-account-sso', null, 1);
});

it('answers 403 without detail for a bad or refused ticket', function () {
    $this->fake->on('v-check-mail-account-sso')->returnsExit(ExitCode::Forbidden, 'Error: the sign-on is not valid');

    $this->post('/sso/mail', ['ticket' => 'short', 'client_ip' => '203.0.113.9'])->assertForbidden()->assertExactJson(['error' => 'forbidden']);
    $this->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => 'nowhere'])->assertForbidden();
    $this->fake->assertNotRan('v-check-mail-account-sso');

    $this->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => '203.0.113.9'])->assertForbidden()->assertExactJson(['error' => 'forbidden']);
});

it('limits the exchanges to 30 a minute', function () {
    $this->fake->on('v-check-mail-account-sso')->returnsExit(ExitCode::Forbidden, 'Error: the sign-on is not valid');

    foreach (range(1, 30) as $i) {
        $this->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => '203.0.113.9'])->assertForbidden();
    }
    $this->post('/sso/mail', ['ticket' => SSO_TICKET, 'client_ip' => '203.0.113.9'])->assertStatus(429);
});
