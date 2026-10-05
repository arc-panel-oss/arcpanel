<?php

use App\Auth\PrincipalResolver;

// Reseller branch of the authorization frame on the existing pages (E11
// spec §6): hostco owns bob, resb owns carol and dave.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeWebDomains($this->fake);
    fakeResellers($this->fake);
    $this->fake->on('v-log-action')->returns();
});

it('lists the web domains of the reseller tree with an owner filter', function () {
    signIn('hostco', 'reseller');

    $this->get('/web/domains')->assertOk()
        ->assertSee('example.com')->assertSee('shop.example')
        ->assertDontSee('carol.example')
        ->assertSee('All owners')
        ->assertSee('Your web domains and those of your customers.');

    $this->get('/web/domains?owner=bob')->assertOk()->assertSee('example.com');
    $this->get('/web/domains?owner=carol')->assertNotFound();
});

it('answers 404 for domains outside the reseller tree', function () {
    signIn('resb', 'reseller');

    $this->get('/web/domains/bob/example.com/edit')->assertNotFound();
    $this->post('/web/domains/bob/example.com/suspend')->assertNotFound();
    $this->post('/web/domains/bulk', ['action' => 'suspend', 'items' => ['bob/example.com']])->assertNotFound();
    $this->fake->assertNotRan('v-suspend-web-domain');

    $this->get('/web/domains/carol/carol.example/edit')->assertOk();
});

it('lets a reseller add a domain for a customer', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-web-domain')->returns();

    $this->get('/web/domains/create')->assertOk()->assertSee('id="pick-owner"', false);
    $this->post('/web/domains', ['owner' => 'bob', 'domain' => 'new.example', 'ip' => '10.0.0.10'])
        ->assertRedirect('/web/domains/bob/new.example/edit');
    $this->post('/web/domains', ['owner' => 'carol', 'domain' => 'x.example', 'ip' => '10.0.0.10'])->assertNotFound();
});

it('logs a reseller in as its customer through arc_look only', function () {
    $session = signIn('hostco', 'reseller');

    $this->post('/impersonate/bob')->assertRedirect(route('dashboard'));
    expect($session->data()[PrincipalResolver::RESELLER_LOOK])->toBe('bob')
        ->and($session->data()['look'])->toBe('');
    $this->fake->assertRan('v-log-action', fn ($a) => str_contains($a[3] ?? '', 'Reseller: hostco'));

    $this->get('/web/domains')->assertOk()
        ->assertSee('example.com')
        ->assertDontSee('All owners')
        ->assertDontSee('href="/list/dns/"', false)
        ->assertSee('Logged in as');
    expect($session->data()['look'])->toBe('');

    $this->post('/impersonate/stop')->assertRedirect(route('dashboard'));
    expect($session->data())->not->toHaveKey(PrincipalResolver::RESELLER_LOOK);
});

it('refuses login as for users outside the tree', function () {
    signIn('hostco', 'reseller');

    $this->post('/impersonate/carol')->assertForbidden();
    $this->post('/impersonate/resb')->assertForbidden();
    $this->post('/impersonate/arcadmin')->assertForbidden();
});

it('drops a stale login as and returns the reseller to its own account', function () {
    $session = signIn('hostco', 'reseller', [PrincipalResolver::RESELLER_LOOK => 'carol']);

    $this->get('/web/domains')->assertOk()
        ->assertSee('All owners')
        ->assertSessionHas('error');
    expect($session->data())->not->toHaveKey(PrincipalResolver::RESELLER_LOOK);
});

it('ignores the legacy look key of a reseller session', function () {
    signIn('hostco', 'reseller', ['look' => 'carol']);

    $this->get('/web/domains')->assertOk()->assertDontSee('carol.example');
});

it('ends the session of a reseller who is no longer a reseller', function () {
    fakeResellers($this->fake, ['hostco' => ['ROLE' => 'user']]);
    signIn('hostco', 'reseller');

    $this->get('/web/domains')->assertRedirect();
});

it('offers the customer switcher to resellers', function () {
    signIn('hostco', 'reseller');

    $this->get('/web/domains')->assertOk()
        ->assertSee('Switch user')
        ->assertSee('<option value="bob">', false)
        ->assertDontSee('<option value="carol">', false);
});

it('keeps palette results of a reseller to its tree and panel app pages', function () {
    $this->fake->on('v-search-object')->returnsJson([]);
    $this->fake->on('v-search-object', ['example', 'json'])->returnsFixture('v-search-object/example.json');
    signIn('hostco', 'reseller');

    $groups = collect($this->getJson('/_palette?q=example')->assertOk()->json('groups'))->keyBy('label');

    // carol's zone is outside the tree; bob's mail account opens its panel app page (E7)
    expect($groups)->toHaveKeys(['Web domains', 'Mail'])
        ->and($groups)->not->toHaveKey('DNS')
        ->and($groups['Mail']['items'][0]['url'])->toBe(route('mail.accounts.edit', ['user' => 'bob', 'domain' => 'example.com', 'account' => 'info']));

    $users = collect($this->getJson('/_palette?q=Carol')->json('groups'))->keyBy('label');
    expect($users)->not->toHaveKey('Users');
});
