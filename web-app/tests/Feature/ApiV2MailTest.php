<?php

use App\Arc\ExitCode;

// REST API v2, mail domains (E34 batch 2 spec §6.2). bob (customer of
// hostco) has example.com and the suspended shop.example with Let's Encrypt
// and an SMTP relay; carol (customer of resb) has carol.example.

beforeEach(function () {
    $this->fake = fakeApi([
        'ANTISPAM_SYSTEM' => 'spamassassin',
        'ANTIVIRUS_SYSTEM' => 'clamav',
        'IMAP_SYSTEM' => 'dovecot',
        'WEBMAIL_SYSTEM' => 'roundcube',
    ]);
    fakeResellers($this->fake);
    fakeMailDomains($this->fake);
});

it('lists the mail domains of a user inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/mail-domains", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', count($response->json('data')));
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('describes a domain and never returns a relay password', function () {
    $this->getJson('/api/v2/users/bob/mail-domains/example.com', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.domain', 'example.com')
        ->assertJsonPath('data.user', 'bob')
        ->assertJsonPath('data.antispam', true)
        ->assertJsonPath('data.dkim', true)
        ->assertJsonPath('data.catchall', null)
        ->assertJsonPath('data.webmail', 'roundcube')
        ->assertJsonPath('data.rate_limit', 200)
        ->assertJsonPath('data.ssl.enabled', false)
        ->assertJsonPath('data.ssl.letsencrypt', false)
        ->assertJsonPath('data.smtp_relay', null)
        ->assertJsonPath('data.accounts', 3)
        ->assertJsonPath('data.disk_mb', 12)
        ->assertJsonPath('data.created_at', '2026-10-06T01:50:43')
        ->assertJsonMissingPath('data.smtp_relay.password');

    $this->getJson('/api/v2/users/bob/mail-domains/shop.example', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.ssl.letsencrypt', true)
        ->assertJsonPath('data.ssl.key_algorithm', 'ecdsa-256')
        ->assertJsonPath('data.catchall', 'orders@shop.example')
        ->assertJsonPath('data.rate_limit', null)
        ->assertJsonPath('data.webmail', null)
        ->assertJsonPath('data.smtp_relay.host', 'smtp.relay.example')
        ->assertJsonPath('data.smtp_relay.port', 587)
        ->assertJsonPath('data.smtp_relay.username', 'relayuser')
        ->assertJsonPath('data.suspended', true);
});

it('pages the list', function () {
    $this->getJson('/api/v2/users/bob/mail-domains?limit=1&offset=1', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta', ['total' => 2, 'limit' => 1, 'offset' => 1]);
});

it('creates a domain with a key that holds only v2-mail', function () {
    $this->fake->on('v-add-mail-domain')->returns();
    $this->fake->on('v-list-mail-domain', ['bob', 'new.example', 'json'])->returnsJson(['new.example' => [
        'ANTIVIRUS' => 'yes', 'ANTISPAM' => 'yes', 'DKIM' => 'yes', 'CATCHALL' => '', 'ACCOUNTS' => '0',
        'RATE_LIMIT' => '', 'REJECT' => 'no', 'U_DISK' => '0', 'SSL' => 'no', 'SSL_KEY_ALGO' => '',
        'LETSENCRYPT' => 'no', 'SUSPENDED' => 'no', 'TIME' => '12:00:00', 'DATE' => '2026-10-06',
        'WEBMAIL_ALIAS' => '', 'WEBMAIL' => '', 'U_SMTP_RELAY' => '', 'U_SMTP_RELAY_HOST' => '',
        'U_SMTP_RELAY_PORT' => '', 'U_SMTP_RELAY_USERNAME' => '',
    ]]);

    $this->postJson('/api/v2/users/bob/mail-domains', ['domain' => 'WWW.New.Example'], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/mail-domains/new.example')
        ->assertJsonPath('data.domain', 'new.example');

    $this->fake->assertRan('v-add-mail-domain', fn ($a) => $a === ['bob', 'new.example', 'yes', 'yes', 'yes']);
});

it('answers a domain the user has with 409 before any check', function () {
    $this->postJson('/api/v2/users/bob/mail-domains', ['domain' => 'example.com'], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->assertNotRan('v-add-mail-domain');
});

it('refuses an option the server does not offer', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all']);

    $this->postJson('/api/v2/users/bob/mail-domains', ['domain' => 'new.example', 'antispam' => true], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonStructure(['error' => ['fields' => ['antispam']]]);
    $this->fake->assertNotRan('v-add-mail-domain');
});

it('plans a create without running it, and refuses a v2-read key', function () {
    $this->postJson('/api/v2/users/bob/mail-domains?dry_run=1', ['domain' => 'new.example', 'dkim' => false], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0.command', 'v-add-mail-domain')
        ->assertJsonPath('data.steps.0.args.4', 'no');
    $this->fake->assertNotRan('v-add-mail-domain');

    $this->postJson('/api/v2/users/bob/mail-domains', ['domain' => 'new.example'], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-add-mail-domain');
    $this->fake->assertNotRan('v-add-mail-domain');
});

it('names the failed step', function () {
    $this->fake->on('v-add-mail-domain')->returnsExit(ExitCode::Invalid, 'Error: invalid domain format');

    $this->postJson('/api/v2/users/bob/mail-domains', ['domain' => 'new.example'], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'E_INVALID')
        ->assertJsonPath('error.step.command', 'v-add-mail-domain');
});

it('updates the general tab and refuses a rate limit to anyone but an administrator', function () {
    $this->fake->on('v-delete-mail-domain-dkim')->returns();
    $this->fake->on('v-add-mail-domain-catchall')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com', ['dkim' => false, 'catchall' => 'all@example.com'], $headers)->assertOk();
    $this->fake->assertRan('v-delete-mail-domain-dkim', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertRan('v-add-mail-domain-catchall', fn ($a) => $a === ['bob', 'example.com', 'all@example.com']);

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com', ['rate_limit' => 50], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-change-mail-domain-rate-limit');

    $this->fake->on('v-change-mail-domain-rate-limit')->returns();
    $this->patchJson('/api/v2/users/bob/mail-domains/example.com', ['rate_limit' => 50], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-change-mail-domain-rate-limit', fn ($a) => $a === ['bob', 'example.com', '50']);
});

it('refuses a webmail client the server does not have', function () {
    $this->patchJson('/api/v2/users/bob/mail-domains/example.com', ['webmail' => 'rainloop'], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['webmail']]]);
    $this->fake->assertNotRan('v-add-mail-domain-webmail');
});

it('suspends a domain once, refuses a rebuild to a user and deletes it', function () {
    $this->fake->on('v-suspend-mail-domain')->returns();
    $this->fake->on('v-delete-mail-domain')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->postJson('/api/v2/users/bob/mail-domains/shop.example/suspend', [], $headers)->assertOk();
    $this->fake->assertNotRan('v-suspend-mail-domain');
    $this->postJson('/api/v2/users/bob/mail-domains/example.com/suspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-suspend-mail-domain', fn ($a) => $a === ['bob', 'example.com']);

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/rebuild', [], $headers)->assertForbidden();
    $this->fake->on('v-rebuild-mail-domain')->returns();
    $this->postJson('/api/v2/users/bob/mail-domains/example.com/rebuild', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();

    $this->deleteJson('/api/v2/users/bob/mail-domains/example.com', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-mail-domain', fn ($a) => $a === ['bob', 'example.com']);
});

it('requires a key for a first certificate and replaces Lets Encrypt in one plan', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->putJson('/api/v2/users/bob/mail-domains/example.com/ssl', ['certificate' => 'CRT'], $headers)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['key']]]);
    $this->fake->assertNotRan('v-add-mail-domain-ssl');

    $this->putJson('/api/v2/users/bob/mail-domains/shop.example/ssl?dry_run=1', ['certificate' => 'CRT', 'key' => 'KEY'], $headers)
        ->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-letsencrypt-domain')
        ->assertJsonPath('data.steps.1.command', 'v-add-mail-domain-ssl');
    $this->fake->assertNotRan('v-delete-letsencrypt-domain');
});

it('queues a mail Lets Encrypt certificate and removes a relay only when one exists', function () {
    $this->fake->on('v-schedule-letsencrypt-domain')->returns("17290000009999\n");
    $this->fake->on('v-delete-mail-domain-smtp-relay')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/letsencrypt', ['key_algorithm' => 'rsa'], $headers)
        ->assertStatus(202)
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/mail-domains/example.com')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009999>; rel="monitor"');
    $this->fake->assertRan('v-schedule-letsencrypt-domain', fn ($a) => $a === ['bob', 'example.com', '', 'rsa', 'yes']);

    $this->deleteJson('/api/v2/users/bob/mail-domains/example.com/smtp-relay', [], $headers)->assertNoContent();
    $this->fake->assertNotRan('v-delete-mail-domain-smtp-relay');
    $this->deleteJson('/api/v2/users/bob/mail-domains/shop.example/smtp-relay', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-mail-domain-smtp-relay', fn ($a) => $a === ['bob', 'shop.example']);
});

it('keeps the stored relay password: changed relay settings need the password again', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->putJson('/api/v2/users/bob/mail-domains/shop.example/smtp-relay', ['host' => 'smtp2.relay.example', 'username' => 'relayuser'], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.fields', ['password' => ['The password is required when the relay settings change.']]);
    $this->putJson('/api/v2/users/bob/mail-domains/shop.example/smtp-relay', ['host' => 'smtp.relay.example', 'username' => 'relayuser', 'port' => 2525, 'password' => null], $headers)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    $this->fake->assertNotRan('v-add-mail-domain-smtp-relay');

    $this->putJson('/api/v2/users/bob/mail-domains/shop.example/smtp-relay', ['host' => 'smtp.relay.example', 'username' => 'relayuser', 'port' => 587], $headers)
        ->assertOk();
    $this->fake->assertNotRan('v-add-mail-domain-smtp-relay');

    $this->putJson('/api/v2/users/bob/mail-domains/shop.example/smtp-relay?dry_run=1', ['host' => 'smtp2.relay.example', 'username' => 'relayuser', 'password' => 'Relay-pass1'], $headers)
        ->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-mail-domain-smtp-relay');
    $this->putJson('/api/v2/users/bob/mail-domains/example.com/smtp-relay?dry_run=1', ['host' => 'smtp.relay.example'], $headers)
        ->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-mail-domain-smtp-relay');
});

it('returns the DNS records the domain needs', function () {
    $this->getJson('/api/v2/users/bob/mail-domains/example.com/dns-records', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonFragment(['name' => 'example.com', 'type' => 'MX', 'value' => 'mail.example.com.', 'priority' => 10])
        ->assertJsonFragment(['name' => 'mail.example.com', 'type' => 'A']);
});
