<?php

use App\Arc\ExitCode;
use App\Services\Mail\HostingCredentialsMailer;

beforeEach(function () {
    $this->fake = fakeArc(['IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail']);
    fakeServerUsers($this->fake);
    fakeMailDomains($this->fake);

    // The account commands of a change, in the order they ran
    $this->accountCalls = fn () => array_values(array_filter(
        $this->fake->calls(),
        fn (array $call) => preg_match('/^v-(add|change|delete)-mail-account/', $call['command']) === 1,
    ));

    $this->mailer = new class implements HostingCredentialsMailer
    {
        public array $sent = [];

        public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void {}

        public function sendMailCredentials(string $to, array $details, string $language): void
        {
            $this->sent[] = [$to, $details, $language];
        }
    };
    app()->instance(HostingCredentialsMailer::class, $this->mailer);
});

/** v-list-mail-account of bob's info@example.com with these keys changed. */
function fakeMailAccountRow($fake, array $overrides, string $account = 'info'): void
{
    $row = commandFixture('v-list-mail-accounts/bob-example.com.json')[$account];
    $fake->on('v-list-mail-account', ['bob', 'example.com', $account, 'json'])
        ->returnsJson([$account => array_merge($row, ['RATE_LIMIT' => '', 'WEBMAIL_ALIAS' => 'webmail.example.com'], $overrides)]);
}

/** The fields of the add form as web/add/mail?domain= posts them. */
function mailAccountForm(array $overrides = []): array
{
    return array_merge(['account' => 'new', 'password' => 'Secret-123'], $overrides);
}

// Accounts list (spec §9.4)

it('lists the accounts of a domain with aliases, forwards and webmail links', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts')->assertOk()
        ->assertSeeInOrder(['info@example.com', 'noreply@example.com', 'old@example.com'])
        ->assertSee('sales')->assertSee('a@example.net')->assertSee('Discard all mail')->assertSee('∞')->assertSee('100 MB')
        ->assertSee('/mail/bob/example.com/accounts/info/edit')->assertDontSee('/mail/bob/example.com/accounts/old/edit')
        // Open Webmail with the account (spec §11)
        ->assertSee('https://webmail.example.com/?_user=info%40example.com', false)
        ->assertSee('Are you sure you want to suspend info?')->assertSee('Are you sure you want to unsuspend old?')
        ->assertSee('Are you sure you want to delete info?')
        ->assertSee('/mail/bob/example.com/accounts/create');
});

it('shows no webmail link for a domain without webmail', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/shop.example/accounts')->assertOk()->assertDontSee('Open Webmail')->assertSee('This domain has no mail accounts.');
});

it('answers 404 for the accounts of another user\'s domain and for unknown accounts', function () {
    signIn('bob', 'user');

    $this->get('/mail/carol/carol.example/accounts')->assertNotFound();
    $this->get('/mail/carol/carol.example/accounts/create')->assertNotFound();
    $this->get('/mail/bob/example.com/accounts/ghost/edit')->assertNotFound();
    $this->post('/mail/carol/carol.example/accounts', mailAccountForm())->assertNotFound();
});

// Add account (spec §9.4)

it('shows the add form with the client configuration panel', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts/create')->assertOk()
        ->assertSee('apMailAccount', false)->assertSee('name="account"', false)->assertSee('name="password"', false)
        ->assertSee('name="quota_unlimited"', false)->assertSee('name="blackhole"', false)->assertSee('name="fwd_only"', false)
        ->assertSee('Common Account Settings')->assertSee('value="https://webmail.example.com/"', false)
        ->assertSee('value="mail.example.com"', false)->assertSee('Normal password')
        ->assertSeeInOrder(['IMAP Settings', '993', '143', '143', 'POP3 Settings', '995', '110', '110', 'SMTP Settings', '465', '587', '25'])
        ->assertSee('@example.com');
    expect($this->get('/mail/bob/example.com/accounts/create')->getContent())->toMatch('/<input(?=[^>]*name="rate")(?=[^>]* disabled)[^>]*>/');
});

it('adds an account with the legacy arguments and order', function () {
    foreach (['v-add-mail-account', 'v-add-mail-account-alias', 'v-add-mail-account-forward', 'v-add-mail-account-fwd-only'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', mailAccountForm([
        'account' => 'New', 'quota' => '200', 'aliases' => "sales\nhello, support", 'forward' => 'a@example.net b@example.net',
        'fwd_only' => '1', 'rate' => '30',
    ]))->assertRedirect('/mail/bob/example.com/accounts')
        ->assertSessionHas('ok', 'Mail account new@example.com has been created successfully.');

    // A user's rate limit is ignored (administrators only)
    expect(($this->accountCalls)())->toBe([
        ['command' => 'v-add-mail-account', 'args' => ['bob', 'example.com', 'new', 'Secret-123', '200']],
        ['command' => 'v-add-mail-account-alias', 'args' => ['bob', 'example.com', 'new', 'sales']],
        ['command' => 'v-add-mail-account-alias', 'args' => ['bob', 'example.com', 'new', 'hello']],
        ['command' => 'v-add-mail-account-alias', 'args' => ['bob', 'example.com', 'new', 'support']],
        ['command' => 'v-add-mail-account-forward', 'args' => ['bob', 'example.com', 'new', 'a@example.net']],
        ['command' => 'v-add-mail-account-forward', 'args' => ['bob', 'example.com', 'new', 'b@example.net']],
        ['command' => 'v-add-mail-account-fwd-only', 'args' => ['bob', 'example.com', 'new']],
    ]);
    expect($this->mailer->sent)->toBe([]);
});

it('adds :blackhole: once for "Discard all mail" and an unlimited quota', function () {
    $this->fake->on('v-add-mail-account')->returns();
    $this->fake->on('v-add-mail-account-forward')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['quota' => '5', 'quota_unlimited' => '1', 'blackhole' => '1', 'forward' => 'a@example.net']))
        ->assertRedirect('/mail/bob/example.com/accounts');

    expect(($this->accountCalls)())->toBe([
        ['command' => 'v-add-mail-account', 'args' => ['bob', 'example.com', 'new', 'Secret-123', 'unlimited']],
        ['command' => 'v-add-mail-account-forward', 'args' => ['bob', 'example.com', 'new', ':blackhole:']],
    ]);
});

it('sets the rate limit of a new account for administrators', function () {
    $this->fake->on('v-add-mail-account')->returns();
    $this->fake->on('v-change-mail-account-rate-limit')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['rate' => '30']))->assertRedirect();
    $this->fake->assertRan('v-change-mail-account-rate-limit', fn ($a) => $a === ['bob', 'example.com', 'new', '30']);
});

it('mails the credentials of a new account with the webmail and host names', function () {
    $this->fake->on('v-add-mail-account')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['email' => 'me@example.net']))->assertRedirect();

    expect($this->mailer->sent)->toBe([['me@example.net', [
        'account' => 'new', 'domain' => 'example.com', 'password' => 'Secret-123',
        'webmail' => 'https://webmail.example.com/', 'hostname' => 'mail.example.com',
    ], 'en']]);
});

it('validates the add form as the legacy page', function () {
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', ['account' => '', 'password' => ''])
        ->assertSessionHasErrors(['account' => 'Field "Account" can not be blank.', 'password' => 'Field "Password" can not be blank.']);
    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['password' => 'weak']))
        ->assertSessionHasErrors(['password' => 'Password does not match the minimum requirements.']);
    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['email' => 'nope']))
        ->assertSessionHasErrors(['email' => 'Please enter a valid email address.']);
    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['account' => 'a b']))->assertSessionHasErrors(['account']);
    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['quota' => '-1']))->assertSessionHasErrors(['quota']);
    $this->fake->assertNotRan('v-add-mail-account');
});

it('needs no password for an account that does not store forwarded mail', function () {
    $this->fake->on('v-add-mail-account')->returns();
    $this->fake->on('v-add-mail-account-forward')->returns();
    $this->fake->on('v-add-mail-account-fwd-only')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', ['account' => 'relay', 'fwd_only' => '1', 'forward' => 'a@example.net'])
        ->assertRedirect('/mail/bob/example.com/accounts');
    $this->fake->assertRan('v-add-mail-account', fn ($a) => $a === ['bob', 'example.com', 'relay', '', '0']);
});

it('keeps the input without the password when the account cannot be added', function () {
    $this->fake->on('v-add-mail-account')->returnsExit(ExitCode::Exists, 'Error: mail account new exists');
    signIn('bob', 'user');

    $this->from('/mail/bob/example.com/accounts/create')->post('/mail/bob/example.com/accounts', mailAccountForm(['email' => 'me@example.net']))
        ->assertRedirect('/mail/bob/example.com/accounts/create')
        ->assertSessionHas('error', 'Error: mail account new exists')
        ->assertSessionHasInput('account', 'new')->assertSessionMissing('_old_input.password');
    expect($this->mailer->sent)->toBe([]);
});

it('shows the account with the failed step when a later step of the add fails', function () {
    $this->fake->on('v-add-mail-account')->returns();
    $this->fake->on('v-add-mail-account-alias')->returnsExit(ExitCode::Exists, 'Error: alias exists');
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', mailAccountForm(['aliases' => 'sales']))
        ->assertRedirect('/mail/bob/example.com/accounts/new/edit')
        ->assertSessionHas('error', 'Add the alias sales failed')->assertSessionHas('error_detail', 'Error: alias exists');
});

// Edit account (spec §9.4)

it('shows the edit tabs with the account values and the autoreply message', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts/info/edit')->assertOk()
        ->assertSee('/mail/bob/example.com/accounts/info/edit/general')
        ->assertSee('/mail/bob/example.com/accounts/info/edit/forwarding')
        ->assertSee('/mail/bob/example.com/accounts/info/edit/autoreply')
        ->assertSee('value="100"', false)
        ->assertSee("sales\nhello", false)->assertSee("a@example.net\nb@example.net", false)
        ->assertSee("Away\nback soon", false)
        ->assertSee('https://webmail.example.com/?_user=info%40example.com', false)
        ->assertSee('value="https://webmail.example.com/"', false);
});

it('shows "Discard all mail" ticked and the forwards empty for :blackhole:', function () {
    signIn('bob', 'user');

    $content = $this->get('/mail/bob/example.com/accounts/noreply/edit')->assertOk()->getContent();
    expect($content)->toMatch('/<input[^>]*name="blackhole"[^>]*checked/')
        ->and($content)->not->toContain(':blackhole:')
        ->and($content)->toMatch('/name="quota_unlimited" value="1" checked/');
});

it('changes the password, quota and rate limit on the general tab', function () {
    $this->fake->on('v-change-mail-account-password')->returns();
    $this->fake->on('v-change-mail-account-quota')->returns();
    $this->fake->on('v-change-mail-account-rate-limit')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['quota' => '100', 'rate' => ''])
        ->assertRedirect('/mail/bob/example.com/accounts/info/edit#general')->assertSessionHas('ok', 'Nothing has changed.');

    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['password' => 'N3w-passwd', 'quota_unlimited' => '1', 'rate' => '40'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    expect(($this->accountCalls)())->toBe([
        ['command' => 'v-change-mail-account-password', 'args' => ['bob', 'example.com', 'info', 'N3w-passwd']],
        ['command' => 'v-change-mail-account-quota', 'args' => ['bob', 'example.com', 'info', 'unlimited']],
        ['command' => 'v-change-mail-account-rate-limit', 'args' => ['bob', 'example.com', 'info', '40']],
    ]);
});

it('ignores the rate limit of users and resets it to "system" for administrators', function () {
    fakeMailAccountRow($this->fake, ['RATE_LIMIT' => '40']);
    $this->fake->on('v-change-mail-account-rate-limit')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['quota' => '100', 'rate' => ''])->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-change-mail-account-rate-limit');

    signIn('arcadmin', 'admin');
    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['quota' => '100', 'rate' => ''])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-mail-account-rate-limit', fn ($a) => $a === ['bob', 'example.com', 'info', 'system']);
});

it('validates the password of the general tab and mails the credentials', function () {
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['password' => 'weak', 'quota' => '100'])
        ->assertRedirect('/mail/bob/example.com/accounts/info/edit#general')
        ->assertSessionHasErrors(['password' => 'Password does not match the minimum requirements.'], null, 'general');
    $this->fake->assertNotRan('v-change-mail-account-password');

    $this->fake->on('v-change-mail-account-password')->returns();
    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['password' => 'N3w-passwd', 'quota' => '100', 'email' => 'me@example.net'])
        ->assertSessionHas('ok');
    expect($this->mailer->sent)->toBe([['me@example.net', [
        'account' => 'info', 'domain' => 'example.com', 'password' => 'N3w-passwd',
        'webmail' => 'https://webmail.example.com/', 'hostname' => 'mail.example.com',
    ], 'en']]);
});

it('diffs aliases and forwards on the forwarding tab', function () {
    foreach (['v-delete-mail-account-alias', 'v-add-mail-account-alias', 'v-delete-mail-account-forward', 'v-add-mail-account-forward'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/edit/forwarding', ['aliases' => "sales\nhello", 'forward' => "a@example.net\nb@example.net"])
        ->assertRedirect('/mail/bob/example.com/accounts/info/edit#forwarding')->assertSessionHas('ok', 'Nothing has changed.');

    $this->post('/mail/bob/example.com/accounts/info/edit/forwarding', ['aliases' => 'sales, support', 'forward' => 'b@example.net c@example.net'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    expect(($this->accountCalls)())->toBe([
        ['command' => 'v-delete-mail-account-alias', 'args' => ['bob', 'example.com', 'info', 'hello']],
        ['command' => 'v-add-mail-account-alias', 'args' => ['bob', 'example.com', 'info', 'support']],
        ['command' => 'v-delete-mail-account-forward', 'args' => ['bob', 'example.com', 'info', 'a@example.net']],
        ['command' => 'v-add-mail-account-forward', 'args' => ['bob', 'example.com', 'info', 'c@example.net']],
    ]);
});

it('discards all mail: removes the forwards and adds :blackhole: once (spec §11)', function () {
    $this->fake->on('v-delete-mail-account-forward')->returns();
    $this->fake->on('v-add-mail-account-forward')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/edit/forwarding', ['aliases' => "sales\nhello", 'forward' => 'ignored@example.net', 'blackhole' => '1'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    expect(($this->accountCalls)())->toBe([
        ['command' => 'v-delete-mail-account-forward', 'args' => ['bob', 'example.com', 'info', 'a@example.net']],
        ['command' => 'v-delete-mail-account-forward', 'args' => ['bob', 'example.com', 'info', 'b@example.net']],
        ['command' => 'v-add-mail-account-forward', 'args' => ['bob', 'example.com', 'info', ':blackhole:']],
    ]);
});

it('keeps an existing :blackhole: and removes it when discarding is switched off', function () {
    $this->fake->on('v-delete-mail-account-forward')->returns();
    $this->fake->on('v-add-mail-account-forward')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/noreply/edit/forwarding', ['blackhole' => '1'])->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/mail/bob/example.com/accounts/noreply/edit/forwarding', ['forward' => 'a@example.net'])->assertSessionHas('ok', 'Changes have been saved.');
    expect(($this->accountCalls)())->toBe([
        ['command' => 'v-delete-mail-account-forward', 'args' => ['bob', 'example.com', 'noreply', ':blackhole:']],
        ['command' => 'v-add-mail-account-forward', 'args' => ['bob', 'example.com', 'noreply', 'a@example.net']],
    ]);
});

it('switches "Do not store forwarded mail" on and off', function () {
    $this->fake->on('v-add-mail-account-fwd-only')->returns();
    $this->fake->on('v-delete-mail-account-fwd-only')->returns();
    signIn('bob', 'user');
    $form = ['aliases' => 'sales hello', 'forward' => 'a@example.net b@example.net'];

    $this->post('/mail/bob/example.com/accounts/info/edit/forwarding', $form + ['fwd_only' => '1'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-mail-account-fwd-only', fn ($a) => $a === ['bob', 'example.com', 'info']);

    fakeMailAccountRow($this->fake, ['FWD_ONLY' => 'yes']);
    $this->post('/mail/bob/example.com/accounts/info/edit/forwarding', $form)->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-mail-account-fwd-only', fn ($a) => $a === ['bob', 'example.com', 'info']);
});

it('changes, keeps and deletes the autoreply', function () {
    $this->fake->on('v-add-mail-account-autoreply')->returns();
    $this->fake->on('v-delete-mail-account-autoreply')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/edit/autoreply', ['autoreply' => '1', 'message' => "Away\r\nback soon"])
        ->assertRedirect('/mail/bob/example.com/accounts/info/edit#autoreply')->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/mail/bob/example.com/accounts/info/edit/autoreply', ['autoreply' => '1', 'message' => "On holiday\r\nuntil Monday"])
        ->assertSessionHas('ok', 'Changes have been saved.');
    // The command writes the message with echo -e: line breaks travel as "\n"
    $this->fake->assertRan('v-add-mail-account-autoreply', fn ($a) => $a === ['bob', 'example.com', 'info', 'On holiday\nuntil Monday']);

    $this->post('/mail/bob/example.com/accounts/info/edit/autoreply', ['message' => 'x'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-mail-account-autoreply', fn ($a) => $a === ['bob', 'example.com', 'info']);
});

it('adds an autoreply to an account without one', function () {
    $this->fake->on('v-add-mail-account-autoreply')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/noreply/edit/autoreply', ['autoreply' => '1', 'message' => 'Hello'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-add-mail-account-autoreply', fn ($a) => $a === ['bob', 'example.com', 'noreply', 'Hello']);
    $this->fake->assertNotRan('v-list-mail-account-autoreply');
});

it('names the failed step of an account change', function () {
    $this->fake->on('v-change-mail-account-password')->returns();
    $this->fake->on('v-change-mail-account-quota')->returnsExit(ExitCode::Invalid, 'Error: invalid quota');
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/info/edit/general', ['quota' => '7', 'password' => 'N3w-passwd'])
        ->assertRedirect('/mail/bob/example.com/accounts/info/edit#general')
        ->assertSessionHas('error', 'Change the quota failed')->assertSessionHas('error_detail', 'Error: invalid quota')
        ->assertSessionMissing('_old_input.password');
});

// Account actions (spec §9.1)

it('suspends, unsuspends and deletes an account', function () {
    foreach (['v-suspend-mail-account', 'v-unsuspend-mail-account', 'v-delete-mail-account'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');
    $list = '/mail/bob/example.com/accounts';

    $this->from($list)->post('/mail/bob/example.com/accounts/info/suspend')->assertRedirect($list)
        ->assertSessionHas('ok', 'Mail account info@example.com has been suspended.');
    $this->from($list)->post('/mail/bob/example.com/accounts/old/unsuspend')->assertSessionHas('ok', 'Mail account old@example.com has been unsuspended.');
    $this->delete('/mail/bob/example.com/accounts/info')->assertRedirect($list)->assertSessionHas('ok', 'Mail account info@example.com has been deleted.');

    $this->fake->assertRan('v-suspend-mail-account', fn ($a) => $a === ['bob', 'example.com', 'info']);
    $this->fake->assertRan('v-unsuspend-mail-account', fn ($a) => $a === ['bob', 'example.com', 'old']);
    $this->fake->assertRan('v-delete-mail-account', fn ($a) => $a === ['bob', 'example.com', 'info']);
    $this->delete('/mail/bob/example.com/accounts/ghost')->assertNotFound();
});

it('runs an account bulk action, reporting done and failed items', function () {
    $this->fake->on('v-suspend-mail-account')->returns();
    $this->fake->on('v-suspend-mail-account', ['bob', 'example.com', 'old'])->returnsExit(ExitCode::Suspended, 'Error: account old is suspended');
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts/bulk', ['action' => 'suspend', 'items' => ['info', 'old']])
        ->assertRedirect('/mail/bob/example.com/accounts')->assertSessionHas('error', '1 done, 1 failed')
        ->assertSessionHas('error_detail', 'old: Error: account old is suspended');
    $this->fake->assertRan('v-suspend-mail-account', fn ($a) => $a === ['bob', 'example.com', 'info']);
});

it('refuses account bulk actions outside the scope or with bad items', function () {
    signIn('bob', 'user');

    $this->post('/mail/carol/carol.example/accounts/bulk', ['action' => 'delete', 'items' => ['info']])->assertNotFound();
    $this->post('/mail/bob/example.com/accounts/bulk', ['action' => 'rebuild', 'items' => ['info']])->assertSessionHasErrors(['action']);
    $this->post('/mail/bob/example.com/accounts/bulk', ['action' => 'delete', 'items' => ['a b']])->assertSessionHasErrors(['items.0']);
    $this->post('/mail/bob/nope.example/accounts/bulk', ['action' => 'delete', 'items' => ['info']])->assertNotFound();
    $this->fake->assertNotRan('v-delete-mail-account');
});

it('lets a reseller manage its customers\' accounts only', function () {
    fakeResellers($this->fake);
    $this->fake->on('v-delete-mail-account')->returns();
    signIn('hostco', 'reseller');

    $this->post('/mail/bob/example.com/accounts/bulk', ['action' => 'delete', 'items' => ['info']])->assertSessionHas('ok', '1 done, 0 failed');
    $this->post('/mail/carol/carol.example/accounts/bulk', ['action' => 'delete', 'items' => ['info']])->assertNotFound();
    $this->get('/mail/carol/carol.example/accounts/create')->assertNotFound();
    $this->fake->assertRan('v-delete-mail-account', times: 1);
});

it('refuses every account change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes', 'IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts')->assertOk()->assertDontSee('Add Mail Account')->assertDontSee('mail-accounts-bulk');
    $this->get('/mail/bob/example.com/accounts/info/edit')->assertOk()->assertSee('Read-only');
    $this->get('/mail/bob/example.com/accounts/create')->assertForbidden();
    foreach (['accounts', 'accounts/bulk', 'accounts/info/edit/general', 'accounts/info/edit/forwarding', 'accounts/info/edit/autoreply', 'accounts/info/suspend'] as $path) {
        $this->post("/mail/bob/example.com/{$path}")->assertForbidden();
    }
    $this->delete('/mail/bob/example.com/accounts/info')->assertForbidden();
    $this->fake->assertNotRan('v-delete-mail-account');
});
