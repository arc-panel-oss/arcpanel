<?php

use App\Arc\ExitCode;
use App\Services\Mail\AccountMailer;

// Users and customers (E11 spec §7): hostco owns bob, resb owns carol and dave.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeWebDomains($this->fake);
    fakeResellers($this->fake);
    $packages = [
        'default' => ['WEB_DOMAINS' => 'unlimited', 'USERS' => '0', 'RESELLER_OWNER' => ''],
        'starter' => ['WEB_DOMAINS' => '2', 'USERS' => '0', 'RESELLER_OWNER' => ''],
        'reseller' => ['WEB_DOMAINS' => '100', 'USERS' => '25', 'RESELLER_OWNER' => ''],
        'system' => ['USERS' => '0'],
    ];
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson($packages);
    foreach ($packages as $name => $row) {
        $this->fake->on('v-list-package-tenancy', [$name, 'json'])->returnsJson([$name => $row]);
    }
    $this->fake->on('v-list-reseller-packages', ['hostco', 'json'])->returnsJson(['starter' => $packages['starter'], 'hostco_gold' => ['WEB_DOMAINS' => '5', 'RESELLER_OWNER' => 'hostco']]);
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de', 'vi']);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-list-sys-php', ['json'])->returnsJson(['8.3', '8.5']);
    $this->fake->on('v-check-reseller-quota')->returns();
    $this->fake->on('v-update-reseller-counters')->returns();
    $this->fake->on('v-log-action')->returns();
});

// List and detail (spec §7.1–7.2)

it('lists every user with role and owner for an admin, with an owner tree', function () {
    signIn('arcadmin', 'admin');

    $this->get('/users')->assertOk()
        ->assertSee('hostco')->assertSee('carol')->assertSee('Reseller')
        ->assertSee('Owner tree')
        ->assertSeeInOrder(['hostco', 'bob', 'resb', 'carol', 'dave']);

    $this->get('/users?role=reseller')->assertOk()->assertSee('hostco')->assertDontSee('Carol Shop');
    $this->get('/users?owner=resb')->assertOk()->assertSee('Carol Shop')->assertDontSee('Bob Builder');
});

it('lists only the customers of a reseller', function () {
    signIn('hostco', 'reseller');

    $this->get('/users')->assertOk()
        ->assertSee('Customers')->assertSee('Bob Builder')
        ->assertDontSee('Carol Shop')->assertDontSee('Owner tree')->assertDontSee('All roles');
});

it('keeps users out of user management', function () {
    signIn('bob', 'user');

    $this->get('/users')->assertForbidden();
    $this->get('/users/create')->assertForbidden();
    $this->get('/users/carol')->assertNotFound();
});

it('shows a customer to its reseller and nobody else', function () {
    signIn('hostco', 'reseller');
    $this->get('/users/bob')->assertOk()->assertSee('Bob Builder')->assertSee('example.com')->assertSee('Log in as')
        ->assertSee('href="http://localhost/files/bob"', false);
    $this->get('/users/carol')->assertNotFound();
    $this->get('/users/hostco')->assertNotFound();

    signIn('resb', 'reseller');
    $this->get('/users/bob')->assertNotFound();
});

it('shows the plan allocation of a reseller to an admin', function () {
    signIn('arcadmin', 'admin');

    $this->get('/users/resb')->assertOk()
        ->assertSee('Reseller plan')->assertSee('carol')->assertSee('dave');
});

// Create (spec §7.3)

it('creates a customer through v-add-reseller-user', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-reseller-user')->returns();

    $this->get('/users/create')->assertOk()->assertSee('Add Customer')->assertSee('gold')->assertDontSee('value="reseller"', false);

    $this->post('/users', [
        'username' => 'NewCust', 'password' => 'Secret-123', 'email' => 'new@example.com',
        'name' => 'New Customer', 'language' => 'en', 'package' => 'starter', 'role' => 'admin',
    ])->assertRedirect('/users/newcust')->assertSessionHas('ok');

    $this->fake->assertRan('v-add-reseller-user', fn ($a) => $a === ['hostco', 'newcust', 'Secret-123', 'new@example.com', 'starter', 'New Customer']);
    $this->fake->assertNotRan('v-change-user-role');
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['hostco']);
});

it('refuses a package the reseller cannot assign or that does not fit', function () {
    signIn('hostco', 'reseller');
    $input = ['username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New', 'language' => 'en'];

    $this->post('/users', $input + ['package' => 'reseller'])->assertSessionHasErrors('package');

    $this->fake->on('v-check-reseller-quota', ['hostco', 'starter'])->returnsExit(ExitCode::Limit, 'USERS');
    $this->post('/users', $input + ['package' => 'starter'])->assertSessionHasErrors(['package' => 'The plan has no room for USERS.']);
    $this->getJson('/users/quota?package=starter')->assertOk()->assertJson(['ok' => false, 'key' => 'USERS']);

    $this->fake->assertNotRan('v-add-reseller-user');
});

it('refuses a username that exists', function () {
    signIn('arcadmin', 'admin');

    $this->post('/users', ['username' => 'carol', 'password' => 'Secret-123', 'email' => 'c@example.com', 'name' => 'C', 'language' => 'en', 'package' => 'default'])
        ->assertSessionHasErrors('username');
});

it('lets an admin create a reseller, and a customer for a reseller', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-add-user')->returns();
    $this->fake->on('v-change-user-role')->returns();
    $this->fake->on('v-change-user-owner')->returns();
    $this->fake->on('v-change-user-language')->returns();
    $base = ['password' => 'Secret-123', 'email' => 'x@example.com', 'name' => 'X', 'language' => 'de'];

    $this->post('/users', $base + ['username' => 'agency', 'package' => 'default', 'role' => 'reseller'])->assertSessionHasErrors('package');

    $this->post('/users', $base + ['username' => 'agency', 'package' => 'reseller', 'role' => 'reseller'])->assertRedirect('/users/agency');
    $this->fake->assertRan('v-change-user-role', fn ($a) => $a === ['agency', 'reseller']);
    $this->fake->assertRan('v-change-user-language', fn ($a) => $a === ['agency', 'de']);

    $this->post('/users', $base + ['username' => 'client', 'package' => 'starter', 'owner' => 'resb'])->assertRedirect('/users/client');
    $this->fake->assertRan('v-change-user-owner', fn ($a) => $a === ['client', 'resb']);
});

it('adds the first web domain and database and mails the credentials', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-reseller-user')->returns();
    $this->fake->on('v-list-user-ips', ['newcust', 'json'])->returnsJson(['10.0.0.10' => ['NAT' => '']]);
    $this->fake->on('v-add-web-domain')->returns();
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-add-database')->returns();
    fakeRestarts($this->fake);
    $mailer = new class implements AccountMailer
    {
        public array $sent = [];

        public function sendAccountCredentials(string $to, string $username, string $name, string $password, string $language): void
        {
            $this->sent[] = [$to, $username, $password];
        }
    };
    app()->instance(AccountMailer::class, $mailer);

    $this->post('/users', [
        'username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New', 'language' => 'en',
        'package' => 'starter', 'domain' => 'www.newcust.example', 'dns' => '1', 'database' => 'wp', 'db_user' => 'wp', 'db_password' => 'Db-Secret-1',
        'notify' => 'owner@example.com',
    ])->assertRedirect('/users/newcust')->assertSessionHas('ok');

    $this->fake->assertRan('v-add-web-domain', fn ($a) => $a[0] === 'newcust' && $a[1] === 'newcust.example');
    $this->fake->assertRan('v-add-dns-domain');
    $this->fake->assertRan('v-add-database', fn ($a) => $a === ['newcust', 'wp', 'wp', 'Db-Secret-1']);
    expect($mailer->sent)->toBe([['owner@example.com', 'newcust', 'Secret-123']]);
});

it('keeps the account and names the step when a later step fails', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-reseller-user')->returns();
    $this->fake->on('v-add-database')->returnsExit(ExitCode::Exists, 'Error: database exists');

    $this->post('/users', [
        'username' => 'newcust', 'password' => 'Secret-123', 'email' => 'new@example.com', 'name' => 'New', 'language' => 'en',
        'package' => 'starter', 'database' => 'wp', 'db_user' => 'wp', 'db_password' => 'Db-Secret-1',
    ])->assertRedirect('/users/newcust')->assertSessionHas('error')->assertSessionHas('error_detail');
});

// Edit (spec §7.4–7.5)

it('saves only the changed general values', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-change-user-contact')->returns();
    $this->fake->on('v-change-user-password')->returns();

    $this->get('/users/bob/edit')->assertOk()->assertSee('Name servers')->assertDontSee('Administration');

    $this->post('/users/bob/general', ['name' => 'Bob Builder', 'email' => 'new@example.com', 'language' => 'en', 'password' => 'Better-123'])
        ->assertRedirect('/users/bob/edit#general')->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-contact', fn ($a) => $a === ['bob', 'new@example.com']);
    $this->fake->assertRan('v-change-user-password', fn ($a) => $a === ['bob', 'Better-123']);
    $this->fake->assertNotRan('v-change-user-name');
});

it('changes the package of a customer through the reseller command', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-change-reseller-user-package')->returns();

    $this->post('/users/bob/package', ['package' => 'hostco_gold'])->assertRedirect('/users/bob/edit#package');
    $this->fake->assertRan('v-change-reseller-user-package', fn ($a) => $a === ['hostco', 'bob', 'hostco_gold']);

    $this->fake->on('v-check-reseller-quota', ['hostco', 'starter', 'bob'])->returnsExit(ExitCode::Limit, 'WEB_DOMAINS');
    $this->post('/users/bob/package', ['package' => 'starter'])->assertSessionHasErrors(['package'], null, 'package');
    $this->post('/users/bob/package', ['package' => 'reseller'])->assertSessionHasErrors(['package'], null, 'package');
});

it('lets only admins change role, owner and shell', function () {
    signIn('hostco', 'reseller');
    $this->post('/users/bob/administration', ['role' => 'admin'])->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->fake->on('v-change-user-owner')->returns();
    $this->fake->on('v-change-user-role')->returns();
    $this->fake->on('v-change-user-shell')->returns();

    $this->get('/users/bob/edit')->assertOk()->assertSee('Administration');
    $this->post('/users/resb/administration', ['role' => 'user'])->assertSessionHasErrors(['role'], null, 'administration');

    $this->post('/users/bob/administration', ['role' => 'user', 'owner' => 'resb', 'shell' => 'bash'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-owner', fn ($a) => $a === ['bob', 'resb']);
    $this->fake->assertRan('v-change-user-shell', fn ($a) => $a === ['bob', 'bash']);
    $this->fake->assertNotRan('v-change-user-role');
});

it('moves a customer back to the administrator before it becomes a reseller', function () {
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'reseller']]);
    signIn('arcadmin', 'admin');
    $this->fake->on('v-change-user-owner')->returns();
    $this->fake->on('v-change-user-role')->returns();

    $this->post('/users/bob/administration', ['role' => 'reseller', 'owner' => 'hostco'])->assertSessionHas('ok');

    $calls = array_map(fn ($c) => $c['command'], array_filter($this->fake->calls(), fn ($c) => in_array($c['command'], ['v-change-user-owner', 'v-change-user-role'], true)));
    expect(array_values($calls))->toBe(['v-change-user-owner', 'v-change-user-role']);
    $this->fake->assertRan('v-change-user-owner', fn ($a) => $a === ['bob', 'arcadmin']);
});

it('lets every user edit their own account', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-user-name')->returns();
    $this->fake->on('v-change-user-config-value')->returns();

    $this->get('/account')->assertOk()->assertSee('My account')->assertDontSee('Administration')->assertDontSee('name="login_disabled"', false);
    $this->get('/users/bob/edit')->assertOk()->assertSee('My account');

    $this->post('/users/bob/general', ['name' => 'Bob B', 'email' => 'bob@example.com', 'language' => 'en'])->assertRedirect('/account#general');
    $this->post('/users/bob/security', ['use_iplist' => '1', 'allowed_ips' => "203.0.113.5\n198.51.100.7"])->assertRedirect('/account#security');
    $this->fake->assertRan('v-change-user-config-value', fn ($a) => $a === ['bob', 'LOGIN_ALLOW_IPS', '203.0.113.5,198.51.100.7']);
    // The user record and the login take IPv4 addresses only, compared exactly (as API v2)
    foreach (['198.51.100.0/24', '2001:db8::1', 'example.com'] as $invalid) {
        $this->post('/users/bob/security', ['use_iplist' => '1', 'allowed_ips' => "203.0.113.5\n$invalid"])
            ->assertSessionHasErrors(['allowed_ips' => sprintf('%s is not an IPv4 address.', $invalid)], null, 'security');
    }
    $this->fake->assertRan('v-change-user-config-value', fn ($a) => $a[1] === 'LOGIN_ALLOW_IPS', times: 1);
    $this->post('/users/bob/package', ['package' => 'default'])->assertForbidden();
});

// Actions (spec §7.6)

it('suspends and deletes customers, and keeps resellers with customers', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-suspend-user')->returns();
    $this->fake->on('v-delete-user')->returns();

    $this->post('/users/bob/suspend')->assertSessionHas('ok');
    $this->fake->assertRan('v-suspend-user', fn ($a) => $a === ['bob']);
    $this->delete('/users/bob')->assertRedirect('/users');
    $this->fake->assertRan('v-update-reseller-counters', fn ($a) => $a === ['hostco']);
    $this->delete('/users/carol')->assertNotFound();

    signIn('arcadmin', 'admin');
    $this->delete('/users/resb')->assertSessionHas('error');
    $this->fake->assertRan('v-delete-user', times: 1);
    $this->delete('/users/arcadmin')->assertForbidden();
});

it('runs nothing when one bulk item is outside the tree', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-suspend-user')->returns();

    $this->post('/users/bulk', ['action' => 'suspend', 'items' => ['bob', 'carol']])->assertNotFound();
    $this->fake->assertNotRan('v-suspend-user');
});

it('suspends a reseller with its customers', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-suspend-user')->returns();

    $this->post('/users/bulk', ['action' => 'suspend-tree', 'items' => ['resb']])->assertSessionHas('ok', '3 done, 0 failed');
    $this->fake->assertRan('v-suspend-user', times: 3);
});
