<?php

use App\Arc\ExitCode;

// App processes pages, navigation, Dashboard card, user detail card and the
// web domain tab (E42 spec §9). hostco owns bob (developer package, with app
// processes and a web process), resb owns carol (default package, without
// the grant).

beforeEach(function () {
    $this->fake = fakeArc(['WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => 'nginx']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer', 'SHELL' => 'bash']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['APP_PROCESSES' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['APP_PROCESSES' => 'no']]);
    $this->fake->on('v-list-app-processes', ['bob', 'json'])->returnsJson(appProcessList('bob', [appProcessRow('shop.bob.example')]));
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'web', 'json'])->returnsJson(appProcessRow('shop.bob.example'));
    $this->fake->on('v-list-app-process', ['bob', 'shop.bob.example', 'none', 'json'])->returnsExit(ExitCode::NotExist, 'Error: shop.bob.example has no app process named none');
    foreach (['bob', 'carol'] as $user) {
        $this->fake->on('v-list-app-process-task', [$user, 'json'])->returnsJson(['TASK' => null]);
    }
});

function processIslandProps(string $html): array
{
    return json_decode(html_entity_decode((string) str($html)->match('/data-island="app-processes"[^>]*data-props="([^"]*)"/')), true)
        ?? json_decode(html_entity_decode((string) str($html)->match('/data-props="([^"]*)"[^>]*data-island="app-processes"/')), true);
}

it('shows the list with the island, the processes and the sidebar link', function () {
    signIn('bob', 'user');

    $html = $this->get('/app-processes/bob')->assertOk()
        ->assertSee('data-island="app-processes"', false)
        ->assertSee('shop.bob.example/web')
        ->assertSee('href="'.route('app-processes').'"', false)
        ->getContent();
    $props = processIslandProps($html);
    expect($props['view'])->toBe('list')
        ->and($props['account']['count'])->toBe(1)
        ->and($props['base'])->toEndWith('/app-processes/bob/api')
        ->and($props['pages']['show'])->toEndWith('/app-processes/bob/__DOMAIN__/__NAME__')
        ->and($props['abilities'])->toBe(['manage' => true, 'remove' => true])
        ->and($props['strings'])->toHaveKey('Add process');
});

it('sends /app-processes to the list of the effective account', function () {
    signIn('bob', 'user');
    $this->get('/app-processes')->assertRedirect('/app-processes/bob');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/app-processes')->assertRedirect('/app-processes/bob');
});

it('opens the wizard and the page of a process', function () {
    signIn('bob', 'user');

    $props = processIslandProps($this->get('/app-processes/bob/create?domain=Shop.Bob.Example')->assertOk()->getContent());
    expect($props['view'])->toBe('create')->and($props['domain'])->toBe('shop.bob.example');

    $props = processIslandProps($this->get('/app-processes/bob/shop.bob.example/web')->assertOk()->assertSee('127.0.0.1:30001')->getContent());
    expect($props['view'])->toBe('show')
        ->and($props['process']['name'])->toBe('web')
        ->and($props['secrets'])->toBe([])
        ->and($props['strings'])->toHaveKey('Delete the process');

    $this->get('/app-processes/bob/shop.bob.example/none')->assertNotFound();
});

it('scopes the pages and needs the grant to create', function () {
    signIn('bob', 'user');
    $this->get('/app-processes/carol')->assertNotFound();

    signIn('resb', 'reseller');
    $this->get('/app-processes/bob')->assertNotFound();
    $this->get('/app-processes/carol')->assertOk();
    $this->get('/app-processes/carol/create')->assertForbidden();

    signIn('hostco', 'reseller');
    $this->get('/app-processes/bob/create')->assertOk();

    signIn('carol', 'user');
    $this->get('/app-processes/carol')->assertOk()
        ->assertSee('App processes are not included in your package.')
        ->assertDontSee('href="'.route('app-processes').'"', false);
});

it('shows the App processes card on the dashboard of a package with the grant', function () {
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-user-backups')->returnsJson([]);
    $this->fake->on('v-list-user-notifications')->returnsJson([]);

    signIn('bob', 'user');
    $this->get('/')->assertOk()->assertSee('All app processes')->assertSee('1 running');

    signIn('carol', 'user');
    $this->get('/')->assertOk()->assertDontSee('All app processes');
});

it('lists the processes on the user detail page', function () {
    fakeWebDomains($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/users/bob')->assertOk()
        ->assertSee('App processes')
        ->assertSee('href="'.route('app-processes.show', ['user' => 'bob', 'domain' => 'shop.bob.example', 'name' => 'web']).'"', false);
});

it('lists the domain processes in a tab of the web domain page', function () {
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-web-domain-php')->returnsJson([]);
    $this->fake->on('v-list-app-processes', ['bob', 'json'])->returnsJson(appProcessList('bob', [appProcessRow('example.com'), appProcessRow('other.example', 'queue')]));
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('App processes')
        ->assertSee('href="'.route('app-processes.show', ['user' => 'bob', 'domain' => 'example.com', 'name' => 'web']).'"', false)
        ->assertDontSee('other.example/queue')
        ->assertSee('href="'.route('app-processes.create', ['user' => 'bob', 'domain' => 'example.com']).'"', false);

    // Without the grant and without processes, no tab
    $this->fake->on('v-list-app-processes', ['bob', 'json'])->returnsJson(appProcessList('bob', [], ['ENABLED' => 'no']));
    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertDontSee('href="'.route('app-processes.index', ['user' => 'bob']).'"', false);
});
