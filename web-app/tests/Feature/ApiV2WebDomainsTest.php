<?php

use App\Arc\ExitCode;

// REST API v2, web domains (E34 spec §6.3): bob (customer of hostco, reseller
// A) has example.com and the suspended shop.example, carol (customer of resb,
// reseller B) has carol.example. The fake server runs NGINX in front of
// Apache with PHP-FPM, so the proxy template faces visitors.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
});

/** Let `v-list-web-domain USER DOMAIN json` answer a domain with the fields of bob's example.com over $row. */
function apiWebDomainListed($test, string $user, string $domain, array $row = []): void
{
    $base = commandFixture('v-list-web-domain/bob-example.com.json')['example.com'];
    $test->fake->on('v-list-web-domain', [$user, $domain, 'json'])->returnsJson([$domain => array_merge($base, [
        'ALIAS' => "www.{$domain}", 'SSL' => 'no', 'LETSENCRYPT' => 'no', 'FTP_USER' => '', 'FTP_PATH' => '', 'STATS' => '',
        'DOCUMENT_ROOT' => "/home/{$user}/web/{$domain}/public_html/",
    ], $row)]);
}

// Read (listWebDomains, getWebDomain)

it('lists the domains of a user inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/web-domains", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
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

it('pages the domain list', function () {
    $this->getJson('/api/v2/users/bob/web-domains?limit=1', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.domain', 'example.com')
        ->assertJsonPath('meta', ['total' => 2, 'limit' => 1, 'offset' => 0]);
});

it('lists the domains of a user', function () {
    $this->getJson('/api/v2/users/bob/web-domains', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.*.domain', ['example.com', 'shop.example'])
        ->assertJsonMissingPath('data.0.backend_port')
        ->assertJsonMissingPath('data.0.ssl.certificate');
    $this->fake->assertNotRan('v-list-web-domain-ssl');
});

it('describes a domain with typed fields and its app port', function () {
    $this->getJson('/api/v2/users/bob/web-domains/example.com', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.domain', 'example.com')
        ->assertJsonPath('data.user', 'bob')
        ->assertJsonPath('data.ip', '10.0.0.10')
        ->assertJsonPath('data.aliases', ['www.example.com', 'blog.example.com'])
        ->assertJsonPath('data.document_root', '/home/bob/web/example.com/public_html/')
        ->assertJsonPath('data.custom_document_root', null)
        ->assertJsonPath('data.templates', ['web' => 'default', 'backend' => 'default', 'proxy' => 'default'])
        ->assertJsonPath('data.proxy_extensions', ['css', 'js', 'png', 'jpg'])
        ->assertJsonPath('data.fastcgi_cache', ['enabled' => false, 'duration' => null])
        ->assertJsonPath('data.ssl', ['enabled' => true, 'letsencrypt' => true, 'force' => true, 'hsts' => false, 'home' => 'same', 'key_algorithm' => 'ecdsa-256',
            'certificate' => ['subject' => 'example.com', 'names' => ['example.com', 'www.example.com', 'blog.example.com'], 'issuer' => "Let's Encrypt E6",
                'not_before' => '2026-09-20T00:00:00Z', 'not_after' => '2026-12-19T00:00:00Z']])
        ->assertJsonPath('data.redirect', null)
        ->assertJsonPath('data.stats', 'awstats')
        ->assertJsonPath('data.ftp_accounts', [['username' => 'bob_deploy', 'path' => 'releases']])
        ->assertJsonPath('data.suspended', false)
        ->assertJsonPath('data.disk_mb', 1250)
        ->assertJsonPath('data.backend_port', null)
        ->assertJsonPath('data.created_at', '2026-09-01T10:00:00');
});

it('reads the app port of a template that takes one', function () {
    apiWebDomainListed($this, 'bob', 'example.com', ['PROXY' => 'nodejs-proxy']);
    $this->fake->on('v-list-web-domain-tpl-vars')->returnsFixture('v-list-web-domain-tpl-vars/node.json');

    $this->getJson('/api/v2/users/bob/web-domains/example.com', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.templates.proxy', 'nodejs-proxy')
        ->assertJsonPath('data.backend_port', 30001);
});

it('answers 404 for an unknown domain and for a domain of another tree', function () {
    $this->getJson('/api/v2/users/bob/web-domains/nope.example', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertNotFound()
        ->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->getJson('/api/v2/users/carol/web-domains/carol.example', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertNotFound();
});

// Create (createWebDomain)

it('creates a domain with its DNS zone and mail domain', function () {
    foreach (['v-add-web-domain', 'v-add-dns-domain', 'v-add-mail-domain'] as $command) {
        $this->fake->on($command)->returns();
    }
    apiWebDomainListed($this, 'bob', 'new.example');

    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'WWW.New.Example', 'ip' => '10.0.0.10', 'dns' => true, 'mail' => true], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/web-domains/new.example')
        ->assertJsonPath('data.domain', 'new.example');

    $this->fake->assertRan('v-add-web-domain', fn ($a) => $a === ['bob', 'new.example', '10.0.0.10', 'yes']);
    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a === ['bob', 'new.example', '203.0.113.10', '', '', '', '', '', '', '', '', 'no']);
    $this->fake->assertRan('v-add-mail-domain', fn ($a) => $a === ['bob', 'new.example']);
});

it('takes the first address of the user and the given aliases', function () {
    $this->fake->on('v-add-web-domain')->returns();
    apiWebDomainListed($this, 'bob', 'new.example');

    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'aliases' => ['A.new.example', 'b.new.example']], apiAs($this->fake, 'bob', 'user'))
        ->assertCreated();

    $this->fake->assertRan('v-add-web-domain', fn ($a) => $a === ['bob', 'new.example', '10.0.0.10', 'yes', 'a.new.example,b.new.example']);
    $this->fake->assertNotRan('v-add-dns-domain');
});

it('answers a domain the user has with 409 and a domain of another user with the command error', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'example.com'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->assertNotRan('v-add-web-domain');

    $this->fake->on('v-add-web-domain')->returnsExit(ExitCode::Exists, 'Error: web domain carol.example exists');
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'carol.example'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.message', 'Error: web domain carol.example exists')
        ->assertJsonPath('error.step.command', 'v-add-web-domain')
        ->assertJsonPath('error.applied', []);
});

it('validates the domain and the address', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'bad domain'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')->assertJsonStructure(['error' => ['fields' => ['domain']]]);
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'ip' => '10.9.9.9'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.ip', ['Please select a valid IP address.']);
    $this->fake->assertNotRan('v-add-web-domain');
});

it('creates a domain for a customer of a reseller, and refuses one of another tree', function () {
    $this->fake->on('v-add-web-domain')->returns();
    apiWebDomainListed($this, 'bob', 'new.example');

    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example'], apiAs($this->fake, 'hostco', 'reseller'))->assertCreated();
    $this->postJson('/api/v2/users/carol/web-domains', ['domain' => 'other.example'], apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->fake->assertRan('v-add-web-domain', times: 1);
});

it('refuses template fields without the right to change templates and runs nothing', function () {
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'templates' => ['web' => 'wordpress']], apiAs($this->fake, 'bob', 'user'))
        ->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN');
    $this->fake->assertNotRan('v-add-web-domain');
});

it('applies the templates of an administrator after the domain was created', function () {
    foreach (['v-add-web-domain', 'v-change-web-domain-tpl', 'v-change-web-domain-proxy-tpl'] as $command) {
        $this->fake->on($command)->returns();
    }
    fakeRestarts($this->fake);
    apiWebDomainListed($this, 'carol', 'new.example');

    $this->postJson('/api/v2/users/carol/web-domains', ['domain' => 'new.example', 'templates' => ['web' => 'wordpress', 'proxy' => 'caching']], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertCreated();

    $this->fake->assertRan('v-change-web-domain-tpl', fn ($a) => $a === ['carol', 'new.example', 'wordpress', 'no']);
    $this->fake->assertRan('v-change-web-domain-proxy-tpl', fn ($a) => $a === ['carol', 'new.example', 'caching', 'css,js,png,jpg', 'no']);
});

it('validates templates and the app port with the API field names', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'templates' => ['web' => 'evil']], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields', ['templates.web' => ['Please select a valid template.']]);
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'templates' => ['proxy' => 'nodejs-proxy'], 'backend_port' => 30006], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields', ['backend_port' => ['Please select one of the ports of the list.']]);
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'fastcgi_cache' => ['enabled' => true]], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields', ['fastcgi_cache' => ['The FastCGI cache needs NGINX with PHP-FPM.']]);
    $this->fake->assertNotRan('v-add-web-domain');
});

it('plans a create with its template steps without running it', function () {
    $this->postJson('/api/v2/users/bob/web-domains?dry_run=1', ['domain' => 'new.example', 'dns' => true, 'templates' => ['proxy' => 'nodejs-proxy'], 'backend_port' => 'auto'], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.*.command', ['v-add-web-domain', 'v-add-dns-domain', 'v-change-web-domain-proxy-tpl', 'v-change-web-domain-tpl-vars'])
        ->assertJsonPath('data.steps.3.args', ['bob', 'new.example', 'BACKEND_PORT=auto']);

    $this->fake->assertNotRan('v-add-web-domain');
});

it('refuses a key whose sets do not allow the create and runs nothing', function () {
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example'], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.commands', ['v-add-web-domain']);
    $this->fake->assertNotRan('v-add-web-domain');
});

it('refuses a v2-web key that also creates a DNS zone and names the missing command', function () {
    $this->postJson('/api/v2/users/bob/web-domains', ['domain' => 'new.example', 'dns' => true], apiAs($this->fake, 'bob', 'user', area: 'web'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.commands', ['v-add-dns-domain']);
    $this->fake->assertNotRan('v-add-web-domain');
    $this->fake->assertNotRan('v-add-dns-domain');
});

// Update (updateWebDomain)

it('moves a domain to another address with its DNS zone', function () {
    $this->fake->on('v-list-dns-domain')->returnsExit(ExitCode::NotExist, 'no zone');
    $this->fake->on('v-list-dns-domain', ['bob', 'example.com', 'json'])->returns('{}');
    $this->fake->on('v-list-mail-domain')->returnsExit(ExitCode::NotExist, 'no mail');
    $this->fake->on('v-change-web-domain-ip')->returns();
    $this->fake->on('v-change-dns-domain-ip')->returns();
    fakeRestarts($this->fake);

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['ip' => '10.0.0.20'], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.domain', 'example.com');

    $this->fake->assertRan('v-change-web-domain-ip', fn ($a) => $a === ['bob', 'example.com', '10.0.0.20', 'no']);
    $this->fake->assertRan('v-change-dns-domain-ip', fn ($a) => $a === ['bob', 'example.com', '10.0.0.20', 'no']);
    $this->fake->assertNotRan('v-add-web-domain-alias');
    $this->fake->assertNotRan('v-delete-web-domain-alias');
    $this->fake->assertRan('v-restart-web', times: 1);
});

it('sets and resets a custom document root', function () {
    $this->fake->on('v-change-web-domain-docroot')->returns();
    fakeRestarts($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['custom_document_root' => ['domain' => 'shop.example', 'folder' => 'app/']], $headers)->assertOk();
    $this->fake->assertRan('v-change-web-domain-docroot', fn ($a) => $a === ['bob', 'example.com', 'shop.example', 'app', 'yes']);

    apiWebDomainListed($this, 'bob', 'example.com', ['CUSTOM_DOCROOT' => '/home/bob/web/shop.example/public_html/app']);
    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['custom_document_root' => null], $headers)->assertOk();
    $this->fake->assertRan('v-change-web-domain-docroot', fn ($a) => $a === ['bob', 'example.com', 'default']);

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['custom_document_root' => ['domain' => 'carol.example']], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields', ['custom_document_root.domain' => ['Please select one of your domains.']]);
});

it('changes the templates for an administrator, keeping what was not sent', function () {
    $this->fake->on('v-change-web-domain-tpl')->returns();
    $this->fake->on('v-delete-web-domain-proxy')->returns();
    fakeRestarts($this->fake);

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['templates' => ['web' => 'wordpress']], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-change-web-domain-tpl', fn ($a) => $a === ['bob', 'example.com', 'wordpress', 'no']);
    $this->fake->assertNotRan('v-delete-web-domain-proxy');
    $this->fake->assertNotRan('v-change-web-domain-proxy-tpl');

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['proxy' => false], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-delete-web-domain-proxy', fn ($a) => $a === ['bob', 'example.com', 'no']);
});

it('lets users change templates with POLICY_USER_EDIT_WEB_TEMPLATES only', function () {
    $this->fake->on('v-change-web-domain-tpl')->returns();
    fakeRestarts($this->fake);

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['templates' => ['web' => 'wordpress']], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->fake->assertNotRan('v-change-web-domain-tpl');

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_USER_EDIT_WEB_TEMPLATES' => 'yes']);
    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['templates' => ['web' => 'wordpress']], apiAs($this->fake, 'bob', 'user'))->assertOk();
    $this->fake->assertRan('v-change-web-domain-tpl');
});

it('plans an update without running it', function () {
    $this->patchJson('/api/v2/users/bob/web-domains/example.com?dry_run=1', ['templates' => ['web' => 'wordpress']], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps', [['label' => 'Change the web template', 'command' => 'v-change-web-domain-tpl', 'args' => ['bob', 'example.com', 'wordpress', 'no']]]);
    $this->fake->assertNotRan('v-change-web-domain-tpl');
});

it('names the failed step and the steps that ran', function () {
    $this->fake->on('v-change-web-domain-tpl')->returns();
    $this->fake->on('v-change-web-domain-proxy-tpl')->returnsExit(ExitCode::Invalid, 'Error: invalid template');

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['templates' => ['web' => 'wordpress', 'proxy' => 'caching']], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_INVALID')
        ->assertJsonPath('error.step', ['label' => 'Change the proxy template', 'command' => 'v-change-web-domain-proxy-tpl'])
        ->assertJsonPath('error.applied', [['label' => 'Change the web template', 'command' => 'v-change-web-domain-tpl']]);
    $this->fake->assertNotRan('v-restart-web');
});

// Actions and delete

it('suspends and unsuspends a domain, a domain in that state answering as it is', function () {
    $this->fake->on('v-suspend-web-domain')->returns();
    $this->fake->on('v-unsuspend-web-domain')->returns();
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/users/bob/web-domains/shop.example/suspend', [], $headers)->assertOk()->assertJsonPath('data.suspended', true);
    $this->fake->assertNotRan('v-suspend-web-domain');

    $this->postJson('/api/v2/users/bob/web-domains/shop.example/unsuspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-unsuspend-web-domain', fn ($a) => $a === ['bob', 'shop.example']);
    $this->postJson('/api/v2/users/bob/web-domains/example.com/suspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-suspend-web-domain', fn ($a) => $a === ['bob', 'example.com']);
});

it('rebuilds a domain for administrators only', function () {
    $this->fake->on('v-rebuild-web-domain')->returns();

    $this->postJson('/api/v2/users/bob/web-domains/example.com/rebuild', [], apiAs($this->fake, 'bob', 'user'))->assertForbidden();
    $this->postJson('/api/v2/users/bob/web-domains/example.com/rebuild', [], apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
    $this->fake->assertNotRan('v-rebuild-web-domain');

    $this->postJson('/api/v2/users/bob/web-domains/example.com/rebuild', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-rebuild-web-domain', fn ($a) => $a === ['bob', 'example.com']);
});

it('purges the NGINX cache where NGINX serves the domains', function () {
    $this->fake->on('v-purge-nginx-cache')->returns();

    $this->postJson('/api/v2/users/bob/web-domains/example.com/purge-cache', [], apiAs($this->fake, 'bob', 'user'))->assertOk();
    $this->fake->assertRan('v-purge-nginx-cache', fn ($a) => $a === ['bob', 'example.com']);

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'PROXY_SYSTEM' => '']);
    $this->postJson('/api/v2/users/bob/web-domains/example.com/purge-cache', [], apiAs($this->fake, 'bob', 'user'))->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN');
});

it('deletes a domain with what it leaves behind', function () {
    $this->fake->on('v-delete-web-domain')->returns();
    $this->fake->on('v-delete-node-app')->returnsExit(ExitCode::NotExist, 'no app');
    $this->fake->on('v-list-docker-apps')->returnsJson(['APPS' => [['NAME' => 'shop', 'DOMAIN' => 'example.com'], ['NAME' => 'other', 'DOMAIN' => 'other.example']]]);
    $this->fake->on('v-delete-docker-app')->returns();

    $this->deleteJson('/api/v2/users/bob/web-domains/example.com', [], apiAs($this->fake, 'bob', 'user'))->assertNoContent();

    $this->fake->assertRan('v-delete-web-domain', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertRan('v-delete-web-domain-tpl-vars', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertRan('v-delete-docker-app', fn ($a) => $a === ['bob', 'shop', 'keep-data'], times: 1);
});

it('plans a delete without running it, and refuses a read-only key', function () {
    $this->deleteJson('/api/v2/users/bob/web-domains/example.com?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-web-domain');
    $this->deleteJson('/api/v2/users/bob/web-domains/example.com', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden();
    $this->fake->assertNotRan('v-delete-web-domain');
});

it('refuses every change to a v2-read key and runs nothing', function () {
    $headers = apiAs($this->fake, 'hostco', 'reseller', readOnly: true);

    $this->getJson('/api/v2/users/bob/web-domains/example.com', $headers)->assertOk();
    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['custom_document_root' => ['domain' => 'shop.example']], $headers)->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN');
    $this->postJson('/api/v2/users/bob/web-domains/example.com/suspend', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/users/bob/web-domains/example.com/purge-cache', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/web-domains/example.com', [], $headers)->assertForbidden();

    foreach (['v-change-web-domain-docroot', 'v-suspend-web-domain', 'v-purge-nginx-cache', 'v-delete-web-domain'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

// Aliases (addWebDomainAlias, deleteWebDomainAlias)

it('adds an alias with its DNS record and queues the Let’s Encrypt certificate again', function () {
    $this->fake->on('v-list-dns-domain')->returnsExit(ExitCode::NotExist, 'no zone');
    $this->fake->on('v-list-dns-domain', ['bob', 'example.com', 'json'])->returns('{}');
    foreach (['v-add-web-domain-alias', 'v-add-dns-on-web-alias', 'v-schedule-letsencrypt-domain', 'v-change-web-domain-stats'] as $command) {
        $this->fake->on($command)->returns();
    }
    fakeRestarts($this->fake);

    $this->postJson('/api/v2/users/bob/web-domains/example.com/aliases', ['alias' => 'New.Example.com'], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.domain', 'example.com');

    $this->fake->assertRan('v-add-web-domain-alias', fn ($a) => $a === ['bob', 'example.com', 'new.example.com', 'no']);
    $this->fake->assertRan('v-add-dns-on-web-alias', fn ($a) => $a === ['bob', 'new.example.com', '203.0.113.10', 'no']);
    $this->fake->assertRan('v-schedule-letsencrypt-domain', fn ($a) => $a === ['bob', 'example.com', 'www.example.com,blog.example.com,new.example.com', 'ecdsa-256']);
    $this->fake->assertNotRan('v-add-letsencrypt-domain');
    $this->fake->assertRan('v-change-web-domain-stats', fn ($a) => $a === ['bob', 'example.com', 'awstats']);
});

it('answers an alias the domain has with 409 and validates the alias', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson('/api/v2/users/bob/web-domains/example.com/aliases', ['alias' => 'blog.example.com'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->postJson('/api/v2/users/bob/web-domains/example.com/aliases', ['alias' => 'example.com'], $headers)->assertStatus(409);
    $this->postJson('/api/v2/users/bob/web-domains/example.com/aliases', ['alias' => 'bad alias'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['alias']]]);
    $this->fake->assertNotRan('v-add-web-domain-alias');
});

it('deletes an alias, and answers 404 for an alias the domain does not have', function () {
    $this->fake->on('v-list-dns-domain')->returnsExit(ExitCode::NotExist, 'no zone');
    $this->fake->on('v-delete-web-domain-alias')->returns();
    fakeRestarts($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->deleteJson('/api/v2/users/bob/web-domains/shop.example/aliases/www.shop.example', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-web-domain-alias', fn ($a) => $a === ['bob', 'shop.example', 'www.shop.example', 'no']);
    $this->fake->assertNotRan('v-delete-dns-on-web-alias');

    $this->deleteJson('/api/v2/users/bob/web-domains/shop.example/aliases/nope.shop.example', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/users/carol/web-domains/carol.example/aliases/www.carol.example', [], $headers)->assertNotFound();
});

// SSL (setWebDomainSsl, deleteWebDomainSsl)

it('installs an uploaded certificate, the key required', function () {
    $this->fake->on('v-add-web-domain-ssl')->returns();
    $this->fake->on('v-add-web-domain-ssl-force')->returns();
    fakeRestarts($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->putJson('/api/v2/users/bob/web-domains/shop.example/ssl', ['force' => true], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields', ['certificate' => ['The domain has no certificate: send one.']]);
    $this->putJson('/api/v2/users/bob/web-domains/shop.example/ssl', ['certificate' => 'CERT'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields', ['key' => ['The private key of a new certificate is required.']]);
    $this->fake->assertNotRan('v-add-web-domain-ssl');

    $this->putJson('/api/v2/users/bob/web-domains/shop.example/ssl', ['certificate' => 'CERT', 'key' => 'KEY', 'force' => true], $headers)->assertOk();
    $this->fake->assertRan('v-add-web-domain-ssl', fn ($a) => $a[0] === 'bob' && $a[1] === 'shop.example'
        && json_decode($a[2], true) === ['shop.example.crt' => 'CERT', 'shop.example.key' => 'KEY'] && $a[3] === 'same' && $a[4] === 'no');
    $this->fake->assertRan('v-add-web-domain-ssl-force', fn ($a) => $a === ['bob', 'shop.example']);
});

it('changes HSTS alone on a Let’s Encrypt certificate', function () {
    $this->fake->on('v-add-web-domain-ssl-hsts')->returns();
    fakeRestarts($this->fake);

    $this->putJson('/api/v2/users/bob/web-domains/example.com/ssl', ['hsts' => true], apiAs($this->fake, 'bob', 'user'))->assertOk();

    $this->fake->assertRan('v-add-web-domain-ssl-hsts', fn ($a) => $a === ['bob', 'example.com']);
    foreach (['v-add-letsencrypt-domain', 'v-delete-letsencrypt-domain', 'v-change-web-domain-sslcert', 'v-delete-web-domain-ssl-force'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('replaces a Let’s Encrypt certificate with an uploaded one', function () {
    $this->putJson('/api/v2/users/bob/web-domains/example.com/ssl?dry_run=1', ['certificate' => 'CERT', 'key' => 'KEY'], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps.*.command', ['v-delete-letsencrypt-domain', 'v-add-web-domain-ssl', 'v-add-web-domain-ssl-force'])
        ->assertJsonPath('data.steps.1.args', ['bob', 'example.com', '******', 'same', 'no']);
    $this->fake->assertNotRan('v-delete-letsencrypt-domain');
});

it('turns SSL off, a domain without SSL answering as it is', function () {
    $this->fake->on('v-delete-web-domain-ssl')->returns();
    fakeRestarts($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user');
    apiWebDomainListed($this, 'bob', 'example.com', ['SSL' => 'yes', 'LETSENCRYPT' => 'no', 'SSL_FORCE' => 'no']);

    $this->deleteJson('/api/v2/users/bob/web-domains/example.com/ssl', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-web-domain-ssl', fn ($a) => $a === ['bob', 'example.com', 'no']);

    $this->deleteJson('/api/v2/users/bob/web-domains/shop.example/ssl', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-web-domain-ssl', times: 1);
});

it('plans the removal of a Let’s Encrypt certificate', function () {
    $this->deleteJson('/api/v2/users/bob/web-domains/example.com/ssl?dry_run=1', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-letsencrypt-domain');
});

// Let's Encrypt (requestWebDomainLetsEncrypt)

it('queues a Let’s Encrypt certificate and answers 202 with the domain to poll', function () {
    $this->fake->on('v-schedule-letsencrypt-domain')->returns("17290000001234\n");
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt', [], $headers)
        ->assertStatus(202)
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/web-domains/shop.example')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000001234>; rel="monitor"')
        ->assertJsonPath('data.ssl.letsencrypt', false);
    $this->fake->assertRan('v-schedule-letsencrypt-domain', fn ($a) => $a === ['bob', 'shop.example', 'www.shop.example', 'ecdsa-256']);

    $this->postJson('/api/v2/users/bob/web-domains/example.com/letsencrypt', ['key_algorithm' => 'rsa'], $headers)->assertStatus(202);
    $this->fake->assertRan('v-schedule-letsencrypt-domain', fn ($a) => $a === ['bob', 'example.com', 'www.example.com,blog.example.com', 'rsa']);

    $this->postJson('/api/v2/users/bob/web-domains/example.com/letsencrypt', ['key_algorithm' => 'dsa'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/carol/web-domains/carol.example/letsencrypt', [], $headers)->assertNotFound();
});

it('plans a Let’s Encrypt request, and refuses it to a v2-read key', function () {
    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.steps', [['label' => 'Queue a Let’s Encrypt certificate', 'command' => 'v-schedule-letsencrypt-domain', 'args' => ['bob', 'shop.example', 'www.shop.example', 'ecdsa-256']]]);
    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.commands', ['v-schedule-letsencrypt-domain']);
    $this->fake->assertNotRan('v-schedule-letsencrypt-domain');
});
