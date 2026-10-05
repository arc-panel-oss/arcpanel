<?php

use App\Arc\ExitCode;

// Node apps pages, navigation, Dashboard card and user detail card (E14 spec
// §7). hostco owns bob (developer package, Node.js, one app), resb owns
// carol (default package, without Node.js).

beforeEach(function () {
    $this->fake = fakeArc(['WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes', 'NODE_APPS' => '3']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
    $this->fake->on('v-list-node-apps', ['bob', 'json'])->returnsJson(nodeAppList('bob', [nodeApp('api.bob.example')]));
    $this->fake->on('v-list-node-app', ['bob', 'api.bob.example', 'json'])->returnsJson(['api.bob.example' => nodeApp('api.bob.example')]);
    $this->fake->on('v-list-node-app', ['bob', 'none.bob.example', 'json'])->returnsExit(ExitCode::NotExist, 'Error: none.bob.example has no Node.js app');
    $this->fake->on('v-list-user-node-versions', ['bob', 'json'])->returnsJson(nodeAccount('bob', [], [nodeRuntime('24.21.0', ['DEFAULT' => 'yes'])]));
    $this->fake->on('v-list-user-node-versions', ['carol', 'json'])->returnsJson(nodeAccount('carol', ['PACKAGE' => 'default', 'NODE' => 'no']));
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-user-node-task', [$user, '100', 'json'])->returnsJson(nodeTask($user));
    }
});

function islandProps(string $html): array
{
    return json_decode(html_entity_decode((string) str($html)->match('/data-island="node-apps"[^>]*data-props="([^"]*)"/')), true)
        ?? json_decode(html_entity_decode((string) str($html)->match('/data-props="([^"]*)"[^>]*data-island="node-apps"/')), true);
}

it('shows the list with the island, the apps and the links', function () {
    signIn('bob', 'user');

    $html = $this->get('/node/bob/apps')->assertOk()
        ->assertSee('data-island="node-apps"', false)
        ->assertSee('api.bob.example')
        ->assertSee('href="'.route('node-apps').'"', false)
        ->getContent();
    $props = islandProps($html);
    expect($props['view'])->toBe('list')
        ->and($props['account']['count'])->toBe(1)
        ->and($props['runtimes'])->toBe(1)
        ->and($props['base'])->toEndWith('/node/bob/api/apps')
        ->and($props['pages']['show'])->toEndWith('/node/bob/apps/__DOMAIN__')
        ->and($props['abilities'])->toBe(['manage' => true, 'remove' => true])
        ->and($props['strings'])->toHaveKey('Create app');
});

it('sends /node-apps to the list of the effective account', function () {
    signIn('bob', 'user');
    $this->get('/node-apps')->assertRedirect('/node/bob/apps');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/node-apps')->assertRedirect('/node/bob/apps');
});

it('opens the wizard and the page of an app', function () {
    signIn('bob', 'user');

    $props = islandProps($this->get('/node/bob/apps/create?domain=Shop.Bob.Example')->assertOk()->getContent());
    expect($props['view'])->toBe('create')->and($props['domain'])->toBe('shop.bob.example');

    $props = islandProps($this->get('/node/bob/apps/api.bob.example')->assertOk()->assertSee('127.0.0.1:30010')->getContent());
    expect($props['view'])->toBe('show')
        ->and($props['app']['domain'])->toBe('api.bob.example')
        ->and($props['runtimes'][0]['version'])->toBe('24.21.0')
        ->and($props['maxInstances'])->toBe(4);

    $this->get('/node/bob/apps/none.bob.example')->assertNotFound();
});

it('scopes the pages and needs Node.js in the package to create', function () {
    signIn('bob', 'user');
    $this->get('/node/carol/apps')->assertNotFound();

    signIn('resb', 'reseller');
    $this->get('/node/bob/apps')->assertNotFound();
    $this->get('/node/carol/apps')->assertOk();
    $this->get('/node/carol/apps/create')->assertForbidden();

    signIn('hostco', 'reseller');
    $this->get('/node/bob/apps/create')->assertOk();

    signIn('carol', 'user');
    $this->get('/node/carol/apps')->assertOk()
        ->assertSee('Node.js is not included in your package.')
        ->assertDontSee('href="'.route('node-apps').'"', false);
});

it('shows the Node apps card on the dashboard of a package with Node.js', function () {
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-user-backups')->returnsJson([]);
    $this->fake->on('v-list-user-notifications')->returnsJson([]);

    signIn('bob', 'user');
    $this->get('/')->assertOk()->assertSee('All Node apps')->assertSee('1 online');

    signIn('carol', 'user');
    $this->get('/')->assertOk()->assertDontSee('All Node apps');
});

it('lists the apps on the user detail page', function () {
    fakeWebDomains($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/users/bob')->assertOk()
        ->assertSee('Node apps')
        ->assertSee('href="'.route('node.apps.show', ['user' => 'bob', 'domain' => 'api.bob.example']).'"', false);
});
