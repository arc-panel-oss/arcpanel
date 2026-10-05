<?php

use App\Arc\ExitCode;

/*
 * Template pickers and backend ports of the Web Domains forms (E6 spec §7).
 * The fake server runs NGINX in front of Apache: the proxy template faces
 * visitors and nodejs-proxy takes a port.
 */

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
});

/** bob's pool: 30000-30004 free, 30005 manual, 30006 used by shop.example. */
function bobPorts($fake): void
{
    $fake->on('v-list-user-ports', ['bob', 'json'])->returnsJson(portAccount('bob', ['LIMIT' => '10', 'ALLOCATED' => '7', 'FREE' => '5'], [
        portEntry('30000-30004'),
        portEntry('30005', ['APP' => 'manual', 'COMMENT' => 'by hand']),
        portEntry('30006', ['APP' => 'web:shop.example']),
    ]));
}

it('shows titled template cards with categories and hides internal templates', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);

    $this->get('/web/domains/create?owner=bob')->assertOk()
        ->assertSee('Proxy Template')->assertSee('Node.js app')->assertSee('Standard proxy')
        ->assertSee('Proxies the domain to a Node.js process')
        ->assertSee('PHP CMS')->assertSee('aria-pressed', false)
        ->assertDontSee('value="suspended"', false)
        ->assertSee('name="backend_port"', false)->assertSee('Assign automatically from my pool')
        ->assertSee('<option value="30005"', false)->assertDontSee('<option value="30006"', false);
});

it('lists the template titles of the domains', function () {
    signIn('bob', 'user');

    $this->get('/web/domains')->assertOk()->assertSee('Standard proxy')->assertSee('PHP site');
});

it('creates a domain on a port template and asks for a port of the pool', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);
    $this->fake->on('v-add-web-domain')->returns();
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-change-web-domain-proxy-tpl')->returns();
    $this->fake->on('v-change-web-domain-tpl-vars')->returns();
    fakeRestarts($this->fake);
    $row = commandFixture('v-list-web-domain/bob-example.com.json')['example.com'];
    $this->fake->on('v-list-web-domain', ['bob', 'node.example', 'json'])->using(function () use (&$row) {
        return [0, (string) json_encode(['node.example' => $row])];
    });
    $this->fake->on('v-change-web-domain-proxy-tpl')->using(function () use (&$row) {
        $row['PROXY'] = 'nodejs-proxy';

        return [0, ''];
    });

    $this->post('/web/domains', ['owner' => 'bob', 'domain' => 'node.example', 'ip' => '10.0.0.10', 'dns' => '1',
        'template' => 'default', 'backend_template' => 'default', 'proxy_template' => 'nodejs-proxy', 'backend_port' => 'auto'])
        ->assertRedirect('/web/domains/bob/node.example/edit')->assertSessionHas('ok');

    $this->fake->assertRan('v-change-web-domain-proxy-tpl', fn ($a) => $a[0] === 'bob' && $a[1] === 'node.example' && $a[2] === 'nodejs-proxy');
    $this->fake->assertRan('v-change-web-domain-tpl-vars', fn ($a) => $a === ['bob', 'node.example', 'BACKEND_PORT=auto']);
    $calls = array_column($this->fake->calls(), 'command');
    expect(array_search('v-change-web-domain-tpl-vars', $calls))->toBeGreaterThan(array_search('v-change-web-domain-proxy-tpl', $calls));
});

it('refuses a port outside the pool before the domain is created', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);

    $this->post('/web/domains', ['owner' => 'bob', 'domain' => 'node.example', 'ip' => '10.0.0.10',
        'proxy_template' => 'nodejs-proxy', 'backend_port' => '30006'])
        ->assertSessionHasErrors(['backend_port']);
    $this->post('/web/domains', ['owner' => 'bob', 'domain' => 'node.example', 'ip' => '10.0.0.10',
        'proxy_template' => 'nodejs-proxy', 'backend_port' => 'eighty'])
        ->assertSessionHasErrors(['backend_port']);
    $this->fake->assertNotRan('v-add-web-domain');
});

it('switches a domain to a port template and sets the chosen port', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);
    $this->fake->on('v-change-web-domain-proxy-tpl')->returns();
    $this->fake->on('v-change-web-domain-tpl-vars')->returns();
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/advanced', ['template' => 'default', 'backend_template' => 'default', 'proxy' => '1',
        'proxy_template' => 'nodejs-proxy', 'proxy_extensions' => 'css,js,png,jpg', 'backend_port' => '30005'])
        ->assertSessionHas('ok');

    $this->fake->assertRan('v-change-web-domain-proxy-tpl', fn ($a) => $a[2] === 'nodejs-proxy');
    $this->fake->assertRan('v-change-web-domain-tpl-vars', fn ($a) => $a === ['bob', 'example.com', 'BACKEND_PORT=30005']);
});

it('shows a refused port on the port field of the advanced tab', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);
    $this->fake->on('v-change-web-domain-proxy-tpl')->returns();
    $this->fake->on('v-change-web-domain-tpl-vars')->returnsExit(ExitCode::Limit, 'Error: no free ports left in the pool of bob');
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/advanced', ['proxy' => '1', 'proxy_template' => 'nodejs-proxy', 'proxy_extensions' => 'css,js,png,jpg', 'backend_port' => 'auto'])
        ->assertRedirect('/web/domains/bob/example.com/edit#advanced')
        ->assertSessionHasErrors(['backend_port' => 'No free port is left in the pool. Ask your provider for more ports.'], errorBag: 'advanced')
        ->assertSessionHas('error');
});

it('refuses a port of another domain on the advanced tab', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);

    $this->post('/web/domains/bob/example.com/advanced', ['proxy' => '1', 'proxy_template' => 'nodejs-proxy', 'proxy_extensions' => 'css,js,png,jpg', 'backend_port' => '30006'])
        ->assertSessionHasErrors(['backend_port'], errorBag: 'advanced');
    $this->fake->assertNotRan('v-change-web-domain-proxy-tpl');
});

it('shows the port of a port template and releases it when the template changes', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);
    $this->fake->on('v-list-web-domain', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => array_merge(
        commandFixture('v-list-web-domain/bob-example.com.json')['example.com'], ['PROXY' => 'nodejs-proxy'],
    )]);
    $this->fake->on('v-list-web-domain-tpl-vars', ['bob', 'example.com', 'json'])->returnsFixture('v-list-web-domain-tpl-vars/node.json');
    $this->fake->on('v-change-web-domain-proxy-tpl')->returns();
    fakeRestarts($this->fake);

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('Node.js app')->assertSee('Port 30001')->assertSee('30001 (current)');

    $this->post('/web/domains/bob/example.com/advanced', ['proxy' => '1', 'proxy_template' => 'default', 'proxy_extensions' => 'css,js,png,jpg'])
        ->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-web-domain-tpl-vars', fn ($a) => $a === ['bob', 'example.com']);
});

it('keeps the stored port when the advanced tab is saved unchanged', function () {
    signIn('arcadmin', 'admin');
    bobPorts($this->fake);
    $this->fake->on('v-list-web-domain', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => array_merge(
        commandFixture('v-list-web-domain/bob-example.com.json')['example.com'], ['PROXY' => 'nodejs-proxy'],
    )]);
    $this->fake->on('v-list-web-domain-tpl-vars', ['bob', 'example.com', 'json'])->returnsFixture('v-list-web-domain-tpl-vars/node.json');
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/advanced', ['proxy' => '1', 'proxy_template' => 'nodejs-proxy', 'proxy_extensions' => 'css,js,png,jpg', 'backend_port' => '30001'])
        ->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-change-web-domain-tpl-vars');
});

it('warns on the edit page when a port template has no port', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-list-web-domain', ['bob', 'example.com', 'json'])->returnsJson(['example.com' => array_merge(
        commandFixture('v-list-web-domain/bob-example.com.json')['example.com'], ['PROXY' => 'nodejs-proxy'],
    )]);

    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('App port not set');
});

it('releases the ports of a deleted domain', function () {
    signIn('bob', 'user');
    fakeRestarts($this->fake);
    $this->fake->on('v-delete-web-domain')->returns();

    $this->delete('/web/domains/bob/example.com')->assertRedirect('/web/domains');
    $this->fake->assertRan('v-delete-web-domain-tpl-vars', fn ($a) => $a === ['bob', 'example.com']);
});

it('does not release ports when the delete failed', function () {
    signIn('bob', 'user');
    fakeRestarts($this->fake);
    $this->fake->on('v-delete-web-domain')->returnsExit(ExitCode::InUse, 'Error: in use');

    $this->delete('/web/domains/bob/example.com');
    $this->fake->assertNotRan('v-delete-web-domain-tpl-vars');
});
