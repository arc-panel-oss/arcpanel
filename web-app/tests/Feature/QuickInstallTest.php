<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Web terminal and Quick Install (E7 batch 2 spec §10).

/** `v-list-quick-install-app-options … json` of DokuWiki */
function dokuwikiForm(string $rootClean = 'yes'): array
{
    $field = fn (string $key, string $type, string $value = '', array $options = [], string $required = 'no', string $placeholder = '') => [
        'KEY' => $key, 'TYPE' => $type, 'LABEL' => ucwords(str_replace('_', ' ', $key)), 'VALUE' => $value,
        'PLACEHOLDER' => $placeholder, 'OPTIONS' => $options, 'REQUIRED' => $required,
    ];

    return ['APP' => 'DokuWiki', 'VERSION' => 'latest', 'ROOT_CLEAN' => $rootClean, 'FIELDS' => [
        $field('wiki_name', 'text', required: 'yes'),
        $field('password', 'password', required: 'yes'),
        $field('initial_ACL_policy', 'select', options: [['VALUE' => '0: Open Wiki', 'LABEL' => '0: Open Wiki'], ['VALUE' => '1: Public Wiki', 'LABEL' => '1: Public Wiki']]),
        $field('php_version', 'select', '8.4', [['VALUE' => '8.3', 'LABEL' => '8.3'], ['VALUE' => '8.4', 'LABEL' => '8.4']]),
        $field('database_create', 'boolean', 'true'),
        $field('database_password', 'password', placeholder: 'auto'),
    ]];
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-quick-install-apps')->returnsJson([
        'DokuWiki' => ['NAME' => 'DokuWiki', 'GROUP' => 'wiki', 'VERSION' => 'latest', 'THUMBNAIL' => 'dokuwiki-logo.svg', 'PHP_VERSIONS' => '8.3,8.4', 'INSTALLABLE' => 'yes'],
        'Flarum' => ['NAME' => 'Flarum', 'GROUP' => 'forum', 'VERSION' => '1.8.5', 'THUMBNAIL' => 'fl-logo.png', 'PHP_VERSIONS' => '', 'INSTALLABLE' => 'no'],
    ]);
    $this->fake->on('v-list-quick-install-app-options')->returnsJson(dokuwikiForm());
    $this->fake->on('v-list-user-quick-install-task')->returnsJson(['USER' => 'bob', 'ID' => '', 'DOMAIN' => '', 'APP' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
});

// Web terminal (§10.4)

it('shows the terminal page only when the web terminal is on and the user has a shell', function () {
    signIn('bob', 'user');
    $this->get('/terminal')->assertNotFound(); // WEB_TERMINAL='false'

    withSysConfig($this->fake, ['WEB_TERMINAL' => 'true']);
    $this->get('/terminal')->assertForbidden(); // nologin

    fakeServerUsers($this->fake, ['bob' => ['SHELL' => 'bash']]);
    signIn('bob', 'user');
    $this->get('/terminal')->assertOk()->assertSee('apTerminal', false)->assertSee('Connection error.');
});

it('hides the terminal while a reseller acts as a customer', function () {
    withSysConfig($this->fake, ['WEB_TERMINAL' => 'true']);
    fakeResellers($this->fake, ['hostco' => ['SHELL' => 'bash'], 'bob' => ['SHELL' => 'bash']]);

    signIn('hostco', 'reseller');
    $this->get('/terminal')->assertOk();
    signIn('hostco', 'reseller', ['arc_look' => 'bob']);
    $this->get('/terminal')->assertForbidden();
});

// Quick Install (§10.2, §10.3)

it('lists the installers of a domain with logos, disabled when PHP does not fit', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/install')->assertOk()
        ->assertSee('/build/quick-install/DokuWiki/dokuwiki-logo.svg', false)
        ->assertSee('/web/domains/bob/example.com/install/DokuWiki', false)
        ->assertDontSee('/web/domains/bob/example.com/install/Flarum', false)
        ->assertSee('Unable to install Flarum, required php version is not available.')
        ->assertSee('Version: latest');
    $this->get('/web/domains/bob/example.com/install/Flarum')
        ->assertRedirect('/web/domains/bob/example.com/install')
        ->assertSessionHas('error', 'Unable to install Flarum, required php version is not available.');
    $this->get('/web/domains/bob/example.com/install/NoSuchApp')->assertNotFound();
});

it('links Quick Install from the domain edit page', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('/web/domains/bob/example.com/install', false);
});

it('renders the installer form with the data loss warning', function () {
    $this->fake->on('v-list-quick-install-app-options')->returnsJson(dokuwikiForm('no'));
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/install/DokuWiki')->assertOk()
        ->assertSee('Install DokuWiki')->assertSee('Data Loss Warning!')
        ->assertSee('Please make sure ~/web/example.com/public_html is empty!')
        ->assertSee('name="options[wiki_name]"', false)->assertSee('name="options[database_create]"', false)
        ->assertSee('Leave empty to generate one');
    $this->fake->assertRan('v-list-quick-install-app-options', fn ($a) => $a === ['bob', 'example.com', 'DokuWiki', 'json']);
});

it('starts the install with the options in a secret file', function () {
    $this->fake->on('v-start-user-quick-install')->returns('17286400001234');
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/install/DokuWiki', ['options' => [
        'wiki_name' => 'Team Wiki', 'password' => 'S3cret-pass!', 'initial_ACL_policy' => '1: Public Wiki', 'php_version' => '8.3',
    ]])->assertRedirect('/web/domains/bob/example.com/install/DokuWiki')
        ->assertSessionHas('ok', 'Installing DokuWiki. This can take a few minutes.');

    $call = collect($this->fake->calls('v-start-user-quick-install'))->last();
    expect(array_slice($call['args'], 0, 3))->toBe(['bob', 'example.com', 'DokuWiki'])
        ->and(json_decode($call['args'][3], true))->toBe([
            'wiki_name' => 'Team Wiki', 'password' => 'S3cret-pass!', 'initial_ACL_policy' => '1: Public Wiki',
            'php_version' => '8.3', 'database_create' => '',
        ]);
    expect(json_encode(session()->all()))->not->toContain('S3cret-pass!');
});

it('requires the required fields and refuses unknown choices', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/install/DokuWiki', ['options' => ['wiki_name' => '', 'password' => 'x']])
        ->assertSessionHasErrors(['options.wiki_name' => 'Field "Wiki Name" can not be blank.'])
        ->assertSessionMissing('_old_input.options.password');
    $this->post('/web/domains/bob/example.com/install/DokuWiki', ['options' => ['wiki_name' => 'W', 'password' => 'x', 'php_version' => '5.6']])
        ->assertStatus(422);
    $this->fake->assertNotRan('v-start-user-quick-install');
});

it('says when another install of the account is running', function () {
    $this->fake->on('v-start-user-quick-install')->returnsExit(ExitCode::InUse, 'Error: Another installation is running for bob');
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/install/DokuWiki', ['options' => ['wiki_name' => 'W', 'password' => 'x']])
        ->assertSessionHas('error', 'Another installation is running for this account.');
});

it('reports the install task of the domain as JSON', function () {
    $this->fake->on('v-list-user-quick-install-task')->returnsJson(['USER' => 'bob', 'ID' => '1', 'DOMAIN' => 'example.com', 'APP' => 'DokuWiki',
        'STATUS' => 'running', 'EXIT' => '', 'START' => '2026-10-06 10:00:00', 'END' => '', 'LOG' => '$ v-add-web-domain-app bob example.com DokuWiki']);
    signIn('bob', 'user');

    $this->getJson('/web/domains/bob/example.com/install-task')->assertOk()
        ->assertJsonPath('task.status', 'running')->assertJsonPath('task.app', 'DokuWiki');
    $this->getJson('/web/domains/bob/shop.example/install-task')->assertOk()->assertJsonPath('task.status', '');
    $this->get('/web/domains/bob/example.com/install/DokuWiki')->assertOk()->assertSee('disabled', false);
});

it('keeps Quick Install to the owners of the domain and their managers', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');
    $this->get('/web/domains/bob/example.com/install')->assertOk();
    $this->get('/web/domains/carol/carol.example/install')->assertNotFound();

    signIn('carol', 'user');
    $this->get('/web/domains/bob/example.com/install')->assertNotFound();
    $this->post('/web/domains/bob/example.com/install/DokuWiki', ['options' => ['wiki_name' => 'W']])->assertNotFound();
    $this->fake->assertNotRan('v-start-user-quick-install');
});

it('refuses installs in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    fakeWebDomains($this->fake);
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/install/DokuWiki', ['options' => ['wiki_name' => 'W', 'password' => 'x']])->assertForbidden();
    $this->fake->assertNotRan('v-start-user-quick-install');
});

it('has a capability for the terminal and Quick Install routes', function () {
    expect(collect(Route::getRoutes()->getRoutesByName())->keys())->toContain('terminal.index', 'web.domains.install',
        'web.domains.install.form', 'web.domains.install.start', 'web.domains.install.task')
        ->and(Capabilities::names())->toContain('terminal.use', 'web.apps.install');
});
