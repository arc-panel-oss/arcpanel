<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
});

it('renders the shell with the theme from the legacy THEME and the arc_ui cookie', function () {
    signIn('bob', 'user');

    $this->get('/web/domains')->assertOk()
        ->assertSee('data-theme="nocturne"', false)
        ->assertSee('data-density="comfortable"', false)
        ->assertSee('name="csrf-token"', false)
        ->assertSee('Skip to content');

    $this->withUnencryptedCookie('arc_ui', '{"theme":"light","density":"compact"}')
        ->get('/web/domains')
        ->assertSee('data-theme="light"', false)
        ->assertSee('data-density="compact"', false);
});

it('renders right to left for RTL languages', function () {
    signIn('bob', 'user', ['language' => 'ar']);
    file_put_contents(lang_path('ar.json'), '{}');

    try {
        $this->get('/web/domains')->assertOk()->assertSee('dir="rtl"', false);
    } finally {
        unlink(lang_path('ar.json'));
    }
});

it('offers the user switcher to admins only', function () {
    signIn('arcadmin', 'admin');
    $this->get('/web/domains')->assertSee('Switch user')->assertSee('<option value="carol">', false);

    signIn('bob', 'user');
    $this->get('/web/domains')->assertDontSee('Switch user');
});

it('impersonates from the user switcher', function () {
    $this->fake->on('v-log-action')->returns();
    $session = signIn('arcadmin', 'admin');

    $this->post('/impersonate', ['user' => 'bob'])->assertRedirect('/login/');
    expect($session->data()['look'])->toBe('bob');

    $this->post('/impersonate', ['user' => 'bad user'])->assertSessionHasErrors('user');
});

it('searches objects, users and pages for the command palette', function () {
    $this->fake->on('v-search-object')->returnsJson([]);
    $this->fake->on('v-search-object', ['example', 'json'])->returnsFixture('v-search-object/example.json');
    signIn('arcadmin', 'admin');

    $groups = collect($this->getJson('/_palette?q=example')->assertOk()->json('groups'))->keyBy('label');

    expect($groups['Web domains']['items'][0])->toMatchArray(['label' => 'example.com', 'meta' => 'bob', 'url' => route('web.domains.edit', ['user' => 'bob', 'domain' => 'example.com'])])
        ->and($groups['DNS']['items'][0]['url'])->toStartWith('/login/?loginas=carol')
        ->and($groups['Mail']['items'][0]['url'])->toContain('account%3Dinfo');

    $users = collect($this->getJson('/_palette?q=builder')->json('groups'))->keyBy('label');
    expect($users['Users']['items'][0]['label'])->toBe('bob');

    $pages = collect($this->getJson('/_palette?q=web')->json('groups'))->keyBy('label');
    expect(collect($pages['Pages and actions']['items'])->pluck('label'))->toContain('Web', 'Add Web Domain');
});

it('keeps palette results of a user to their own objects', function () {
    $this->fake->on('v-search-user-object', ['bob', 'example', 'json'])->returnsFixture('v-search-object/example.json');
    signIn('bob', 'user');

    $groups = collect($this->getJson('/_palette?q=example')->json('groups'))->keyBy('label');

    expect($groups)->toHaveKeys(['Web domains', 'Mail'])->and($groups)->not->toHaveKeys(['DNS', 'Users']);
    expect($this->getJson('/_palette?q=e')->json('groups'))->toBe([]);
    $this->fake->assertNotRan('v-search-object');
});

it('lists, acknowledges and deletes notifications as JSON', function () {
    $this->fake->on('v-list-user-notifications', ['bob', 'json'])->returnsFixture('v-list-user-notifications/bob.json');
    $this->fake->on('v-acknowledge-user-notification', ['bob', '2'])->returns();
    $this->fake->on('v-delete-user-notification', ['bob', '1'])->returns();
    signIn('bob', 'user');

    $this->getJson('/_notifications')->assertOk()->assertJsonPath('notifications.0', [
        'id' => '2', 'title' => 'Maintenance tonight', 'body' => 'Services restart at 02:00.', 'acknowledged' => false, 'date' => '2026-10-04', 'time' => '11:00:00',
    ])->assertJsonPath('notifications.1.body', 'Read the guide to get started.');

    $this->postJson('/_notifications/2/acknowledge')->assertOk();
    $this->deleteJson('/_notifications/1')->assertOk();
    $this->fake->assertRan('v-acknowledge-user-notification');
    $this->fake->assertRan('v-delete-user-notification');
});

it('answers JSON errors for the shell routes', function () {
    $this->fake->on('v-list-user-notifications')->returnsExit(ExitCode::NotExist, 'Error: no such user');
    signIn('bob', 'user');

    $this->getJson('/_notifications')->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
});

it('serves server graphs to admins only', function () {
    $this->fake->on('v-export-rrd', ['la', 'weekly', 'json'])->returnsJson(['meta' => ['start' => 1, 'step' => 300, 'legend' => ['Load']], 'data' => [[0.4]]]);

    signIn('bob', 'user');
    $this->getJson('/_rrd?service=la&period=weekly')->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->getJson('/_rrd?service=la&period=weekly')->assertOk()->assertJsonPath('unit', 'Points')->assertJsonPath('service', 'la');
    $this->getJson('/_rrd?service=../etc')->assertStatus(422);
});

it('changes the language for both apps', function () {
    $this->fake->on('v-change-user-language', ['bob', 'en'])->returns();
    $session = signIn('bob', 'user', ['language' => 'en']);

    $this->from('/web/domains')->post('/_language', ['language' => 'en'])->assertRedirect('/web/domains');
    expect($session->data()['language'])->toBe('en');

    $this->post('/_language', ['language' => 'xx'])->assertStatus(422);
});

it('shows the style guide only in debug mode', function () {
    signIn('arcadmin', 'admin');
    $this->get('/_styleguide')->assertNotFound();

    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['DEBUG_MODE' => 'true']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);

    $this->get('/_styleguide?theme=light&dir=rtl&density=compact')->assertOk()
        ->assertSee('data-theme="light"', false)->assertSee('dir="rtl"', false)->assertSee('data-density="compact"', false)
        ->assertSee('x-ap-table')->assertSee('x-ap-wizard');
});

it('renders the 403 page inside the shell', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk();
    $this->post('/web/domains/bob/example.com/advanced')->assertForbidden()->assertSee('You do not have permission to do this.');
});
