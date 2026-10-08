<?php

use App\Services\Mail\HostingCredentialsMailer;

// REST API v2, mail accounts (E34 batch 2 spec §6.3). bob's example.com has
// info (aliases, forwards, autoreply), noreply (discards mail) and the
// suspended old.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeMailDomains($this->fake);
});

it('lists the accounts of a domain inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/mail-domains/example.com/accounts", apiAs($this->fake, $principal, $role, readOnly: true));
    if ($owner === 'carol') {
        $response = $this->getJson('/api/v2/users/carol/mail-domains/carol.example/accounts', apiAs($this->fake, $principal, $role, readOnly: true));
    }
    $response->assertStatus($status);
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

it('describes an account and never returns its password', function () {
    $this->getJson('/api/v2/users/bob/mail-domains/example.com/accounts/info', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.account', 'info')
        ->assertJsonPath('data.address', 'info@example.com')
        ->assertJsonPath('data.aliases', ['sales', 'hello'])
        ->assertJsonPath('data.forwards', ['a@example.net', 'b@example.net'])
        ->assertJsonPath('data.discard_all', false)
        ->assertJsonPath('data.quota_mb', 100)
        ->assertJsonPath('data.autoreply', "Away\nback soon")
        ->assertJsonMissingPath('data.password');

    $this->getJson('/api/v2/users/bob/mail-domains/example.com/accounts/noreply', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.discard_all', true)
        ->assertJsonPath('data.forwards', [])
        ->assertJsonPath('data.quota_mb', null)
        ->assertJsonPath('data.autoreply', null);
});

it('creates an account with a key that holds only v2-mail and mails the settings', function () {
    $mailer = new class implements HostingCredentialsMailer
    {
        public bool $sent = false;

        public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void {}

        public function sendMailCredentials(string $to, array $details, string $language): void
        {
            $this->sent = $to === 'owner@example.net' && $details['account'] === 'shop' && $details['password'] === 'Secret1a';
        }
    };
    app()->instance(HostingCredentialsMailer::class, $mailer);
    $this->fake->on('v-add-mail-account')->returns();
    $this->fake->on('v-list-mail-account', ['bob', 'example.com', 'shop', 'json'])->returnsJson(['shop' => [
        'ALIAS' => '', 'FWD' => '', 'FWD_ONLY' => '', 'AUTOREPLY' => 'no', 'QUOTA' => 'unlimited',
        'RATE_LIMIT' => '', 'U_DISK' => '0', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '12:00:00',
    ]]);

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts', [
        'account' => 'Shop', 'password' => 'Secret1a', 'send_credentials_to' => 'owner@example.net',
    ], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/mail-domains/example.com/accounts/shop')
        ->assertJsonPath('data.account', 'shop')
        ->assertJsonPath('meta.credentials_sent', true);
    expect($mailer->sent)->toBeTrue();
    $this->fake->assertRan('v-add-mail-account');
});

it('answers an account the domain has with 409 and a weak password with 422', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts', ['account' => 'info', 'password' => 'Secret1a'], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts', ['account' => 'new', 'password' => 'short'], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['password']]]);
    $this->fake->assertNotRan('v-add-mail-account');
});

it('plans a create without running it, and refuses a v2-read key', function () {
    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts?dry_run=1', [
        'account' => 'shop', 'password' => 'Secret1a',
    ], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-mail-account');
    $this->fake->assertNotRan('v-add-mail-account');

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts', [
        'account' => 'shop', 'password' => 'Secret1a',
    ], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-add-mail-account');
});

it('replaces the alias list, refuses discard with forwards, and changes a password', function () {
    $this->fake->on('v-delete-mail-account-alias')->returns();
    $this->fake->on('v-change-mail-account-password')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com/accounts/info', ['aliases' => ['sales']], $headers)->assertOk();
    $this->fake->assertRan('v-delete-mail-account-alias', fn ($a) => $a === ['bob', 'example.com', 'info', 'hello']);

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com/accounts/info', [
        'discard_all' => true, 'forwards' => ['c@example.net'],
    ], $headers)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['discard_all']]]);

    $this->putJson('/api/v2/users/bob/mail-domains/example.com/accounts/info/password', ['password' => 'Secret1a'], $headers)->assertNoContent();
    $this->fake->assertRan('v-change-mail-account-password');
});

it('refuses forwards for an account that discards all mail unless discard_all is turned off', function () {
    $this->fake->on('v-delete-mail-account-forward')->returns();
    $this->fake->on('v-add-mail-account-forward')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com/accounts/noreply', ['forwards' => ['c@example.net']], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.fields', ['forwards' => ['Turn off discard_all to set forwards.']]);
    $this->fake->assertNotRan('v-add-mail-account-forward');

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com/accounts/noreply', ['forwards' => []], $headers)->assertOk();
    $this->fake->assertNotRan('v-add-mail-account-forward');

    $this->patchJson('/api/v2/users/bob/mail-domains/example.com/accounts/noreply', ['discard_all' => false, 'forwards' => ['c@example.net']], $headers)->assertOk();
    $this->fake->assertRan('v-delete-mail-account-forward', fn ($a) => $a === ['bob', 'example.com', 'noreply', ':blackhole:']);
    $this->fake->assertRan('v-add-mail-account-forward', fn ($a) => $a === ['bob', 'example.com', 'noreply', 'c@example.net']);
});

it('suspends an account once and deletes it', function () {
    $this->fake->on('v-suspend-mail-account')->returns();
    $this->fake->on('v-delete-mail-account')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'mail');

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts/old/suspend', [], $headers)->assertOk();
    $this->fake->assertNotRan('v-suspend-mail-account');
    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts/info/suspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-suspend-mail-account', fn ($a) => $a === ['bob', 'example.com', 'info']);

    $this->deleteJson('/api/v2/users/bob/mail-domains/example.com/accounts/info', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-mail-account', fn ($a) => $a === ['bob', 'example.com', 'info']);
});

it('keeps a failed settings mail from failing the create', function () {
    app()->instance(HostingCredentialsMailer::class, new class implements HostingCredentialsMailer
    {
        public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void {}

        public function sendMailCredentials(string $to, array $details, string $language): void
        {
            throw new RuntimeException('mail down');
        }
    });
    $this->fake->on('v-add-mail-account')->returns();
    $this->fake->on('v-list-mail-account', ['bob', 'example.com', 'shop', 'json'])->returnsJson(['shop' => [
        'ALIAS' => '', 'FWD' => '', 'FWD_ONLY' => '', 'AUTOREPLY' => 'no', 'QUOTA' => '0',
        'RATE_LIMIT' => '', 'U_DISK' => '0', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '12:00:00',
    ]]);

    $this->postJson('/api/v2/users/bob/mail-domains/example.com/accounts', [
        'account' => 'shop', 'password' => 'Secret1a', 'send_credentials_to' => 'owner@example.net',
    ], apiAs($this->fake, 'bob', 'user', area: 'mail'))
        ->assertCreated()
        ->assertJsonPath('meta.credentials_sent', false);
});
