<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Services\Mail\CredentialsMailer;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
});

// List (spec §8.1)

it('lists only the domains of a user', function () {
    signIn('bob', 'user');

    $this->get('/web/domains')->assertOk()
        ->assertSee('example.com')->assertSee('shop.example')->assertSee('203.0.113.10')
        ->assertDontSee('carol.example')
        ->assertDontSee('All owners');
    $this->fake->assertNotRan('v-list-users');
});

it('lists the domains of every user for an admin, with an owner filter', function () {
    signIn('arcadmin', 'admin');

    $this->get('/web/domains')->assertOk()
        ->assertSee('example.com')->assertSee('carol.example')->assertSee('All owners')
        ->assertSeeInOrder(['carol.example', 'example.com', 'shop.example']);

    $this->get('/web/domains?owner=carol')->assertOk()
        ->assertSee('carol.example')->assertDontSee('shop.example');
    $this->get('/web/domains?owner=ghost')->assertNotFound();
});

it('answers 404 when a user filters by another owner', function () {
    signIn('bob', 'user');

    $this->get('/web/domains?owner=carol')->assertNotFound();
});

it('offers the bulk actions of the role', function () {
    signIn('bob', 'user');
    $this->get('/web/domains')->assertSee('value="suspend"', false)->assertDontSee('value="rebuild"', false);

    signIn('arcadmin', 'admin');
    $this->get('/web/domains')->assertSee('value="rebuild"', false)->assertSee('value="purge"', false);
});

it('shows an empty state without domains', function () {
    $this->fake->on('v-list-web-domains', ['bob', 'json'])->returnsJson([]);
    signIn('bob', 'user');

    $this->get('/web/domains')->assertOk()->assertSee('No web domains yet');
});

// Add (spec §8.2)

it('adds a domain with DNS zone and mail domain, as web/add/web', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain')->returns();
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-add-mail-domain')->returns();

    $this->get('/web/domains/create')->assertOk()->assertSee('Create DNS zone')->assertDontSee('Web Template');

    $this->post('/web/domains', ['domain' => 'WWW.New.Example', 'ip' => '10.0.0.10', 'dns' => '1', 'mail' => '1'])
        ->assertRedirect('/web/domains/bob/new.example/edit')
        ->assertSessionHas('ok');

    $this->fake->assertRan('v-add-web-domain', fn ($a) => $a === ['bob', 'new.example', '10.0.0.10', 'yes']);
    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a === ['bob', 'new.example', '203.0.113.10', '', '', '', '', '', '', '', '', 'no']);
    $this->fake->assertRan('v-add-mail-domain', fn ($a) => $a === ['bob', 'new.example']);
});

it('passes the aliases to v-add-web-domain', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain')->returns();

    $this->post('/web/domains', ['domain' => 'new.example', 'ip' => '10.0.0.20', 'aliases' => "a.new.example\nb.new.example"])->assertRedirect();

    $this->fake->assertRan('v-add-web-domain', fn ($a) => $a === ['bob', 'new.example', '10.0.0.20', 'yes', 'a.new.example,b.new.example']);
    $this->fake->assertNotRan('v-add-dns-domain');
});

it('refuses an IP that is not the owner’s and an invalid domain', function () {
    signIn('bob', 'user');

    $this->post('/web/domains', ['domain' => 'new.example', 'ip' => '10.9.9.9'])->assertSessionHasErrors('ip');
    $this->post('/web/domains', ['domain' => 'bad domain', 'ip' => '10.0.0.10'])->assertSessionHasErrors('domain');
    $this->fake->assertNotRan('v-add-web-domain');
});

it('shows the command error and keeps the input when the domain cannot be added', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain')->returnsExit(ExitCode::Exists, 'Error: web domain new.example exists');

    $this->from('/web/domains/create')->post('/web/domains', ['domain' => 'new.example', 'ip' => '10.0.0.10'])
        ->assertRedirect('/web/domains/create')
        ->assertSessionHas('error', 'Error: web domain new.example exists')
        ->assertSessionHasInput('domain', 'new.example');
});

it('lets an admin add a domain for another user and choose templates', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-add-web-domain')->returns();
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-change-web-domain-tpl')->returns();
    fakeRestarts($this->fake);
    $this->fake->on('v-list-web-domain', ['carol', 'new.example', 'json'])->returnsJson(['new.example' => commandFixture('v-list-web-domains/carol.json')['carol.example']]);

    $this->get('/web/domains/create?owner=carol')->assertOk()->assertSee('Web Template')->assertSee('Owner');

    $this->post('/web/domains', ['owner' => 'carol', 'domain' => 'new.example', 'ip' => '10.0.0.10', 'dns' => '1', 'template' => 'wordpress', 'backend_template' => 'default', 'proxy_template' => 'default'])
        ->assertRedirect('/web/domains/carol/new.example/edit');

    $this->fake->assertRan('v-add-web-domain', fn ($a) => $a[0] === 'carol');
    $this->fake->assertRan('v-change-web-domain-tpl', fn ($a) => $a === ['carol', 'new.example', 'wordpress', 'no']);
});

it('does not let a user add a domain for someone else', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/create?owner=carol')->assertNotFound();
    $this->post('/web/domains', ['owner' => 'carol', 'domain' => 'x.example', 'ip' => '10.0.0.10'])->assertNotFound();
    $this->fake->assertNotRan('v-add-web-domain');
});

// Edit page (spec §8.3)

it('shows every tab of the edit page, without the private key', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee(['General', 'SSL', 'Redirect', 'FTP', 'Statistics'])
        // File manager (E3 spec §7.7)
        ->assertSee('Open document root')->assertSee('/files/bob?path=web%2Fexample.com%2Fpublic_html', false)
        ->assertSee("Let's Encrypt E6")->assertSee('Dec 19 00:00:00 2026 GMT')
        ->assertDontSee('MIIBexampleKEY')
        ->assertDontSee('id="tab-advanced"', false);
});

it('shows the advanced tab to admins and with POLICY_USER_EDIT_WEB_TEMPLATES', function () {
    signIn('arcadmin', 'admin');
    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('id="tab-advanced"', false)->assertSee('Owner: bob');

    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_EDIT_WEB_TEMPLATES' => 'yes']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    signIn('bob', 'user');
    $this->get('/web/domains/bob/example.com/edit')->assertSee('id="tab-advanced"', false);
});

it('answers 404 for another user’s domain and for unknown domains', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/carol/carol.example/edit')->assertNotFound();
    $this->post('/web/domains/carol/carol.example/general', ['ip' => '10.0.0.10'])->assertNotFound();
    $this->get('/web/domains/bob/nope.example/edit')->assertNotFound();
    $this->fake->assertNotRan('v-change-web-domain-ip');
});

it('saves the general tab with only the changed values', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-dns-domain')->returnsExit(ExitCode::NotExist, 'no zone');
    $this->fake->on('v-add-web-domain-alias')->returns();
    $this->fake->on('v-add-letsencrypt-domain')->returns();
    $this->fake->on('v-change-web-domain-stats')->returns();
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/general', ['aliases' => "www.example.com\nblog.example.com\nnew.example.com", 'ip' => '10.0.0.10'])
        ->assertRedirect('/web/domains/bob/example.com/edit#general')
        ->assertSessionHas('ok', 'Changes have been saved.');

    $this->fake->assertRan('v-add-web-domain-alias', fn ($a) => $a === ['bob', 'example.com', 'new.example.com', 'no'], times: 1);
    $this->fake->assertNotRan('v-change-web-domain-ip');
    $this->fake->assertRan('v-restart-web', times: 1);
});

it('names the failed step and keeps applied steps', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-dns-domain')->returnsExit(ExitCode::NotExist, 'no zone');
    $this->fake->on('v-list-mail-domain')->returnsExit(ExitCode::NotExist, 'no mail');
    $this->fake->on('v-change-web-domain-ip')->returns();
    $this->fake->on('v-add-web-domain-alias')->returnsExit(ExitCode::Exists, 'Error: alias exists');

    $this->post('/web/domains/bob/example.com/general', ['aliases' => "www.example.com\nblog.example.com\ntaken.example", 'ip' => '10.0.0.20'])
        ->assertRedirect('/web/domains/bob/example.com/edit#general')
        ->assertSessionHas('error', 'Add alias taken.example failed')
        ->assertSessionHas('error_detail', 'Error: alias exists');

    $this->fake->assertRan('v-change-web-domain-ip');
    $this->fake->assertNotRan('v-restart-web');
});

it('validates each tab into its own error bag', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/general', ['ip' => 'not-an-ip'])
        ->assertRedirect('/web/domains/bob/example.com/edit#general')
        ->assertSessionHasErrors(['ip'], errorBag: 'general');
    $this->post('/web/domains/bob/example.com/general', ['ip' => '10.9.9.9'])
        ->assertSessionHasErrors(['ip'], errorBag: 'general');
    $this->post('/web/domains/bob/shop.example/ssl', ['ssl' => '1'])
        ->assertSessionHasErrors(['certificate', 'key'], errorBag: 'ssl');
    $this->post('/web/domains/bob/example.com/redirect', ['enabled' => '1', 'target' => 'custom', 'custom' => ''])
        ->assertSessionHasErrors(['custom'], errorBag: 'redirect');
});

it('saves the SSL tab', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain-ssl-hsts')->returns();
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/ssl', ['ssl' => '1', 'letsencrypt' => '1', 'key_algo' => 'ecdsa-256', 'force' => '1', 'hsts' => '1'])
        ->assertRedirect('/web/domains/bob/example.com/edit#ssl');

    $this->fake->assertRan('v-add-web-domain-ssl-hsts', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertNotRan('v-add-letsencrypt-domain');
});

it('installs an uploaded certificate', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain-ssl')->returns();
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/shop.example/ssl', ['ssl' => '1', 'certificate' => 'CERT', 'key' => 'KEY'])
        ->assertSessionHas('ok');

    $this->fake->assertRan('v-add-web-domain-ssl', fn ($a) => $a[0] === 'bob' && $a[1] === 'shop.example'
        && json_decode($a[2], true) === ['shop.example.crt' => 'CERT', 'shop.example.key' => 'KEY'] && $a[3] === 'same');
});

it('saves the redirect, statistics and FTP tabs', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain-redirect')->returns();
    $this->fake->on('v-delete-web-domain-stats')->returns();
    $this->fake->on('v-add-web-domain-ftp')->returns();
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/redirect', ['enabled' => '1', 'target' => 'www', 'code' => '301'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-web-domain-redirect', fn ($a) => $a === ['bob', 'example.com', 'www.example.com', '301']);

    $this->post('/web/domains/bob/example.com/stats', ['type' => 'none'])->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-web-domain-stats');

    $this->post('/web/domains/bob/example.com/ftp', [
        'accounts' => [['user' => 'bob_deploy', 'path' => 'releases']],
        'new' => ['user' => 'bob_web', 'password' => 'Secret-123', 'path' => 'public_html'],
    ])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-web-domain-ftp', fn ($a) => $a === ['bob', 'example.com', 'web', 'Secret-123', 'public_html']);
});

it('mails FTP credentials after the account was added', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-web-domain-ftp')->returns();
    $mailer = new class implements CredentialsMailer
    {
        public array $sent = [];

        public function sendFtpCredentials(string $to, string $domain, string $username, string $password, string $language): void
        {
            $this->sent[] = [$to, $domain, $username, $password];
        }
    };
    app()->instance(CredentialsMailer::class, $mailer);

    $this->post('/web/domains/bob/example.com/ftp', ['new' => ['user' => 'web', 'password' => 'Secret-123', 'email' => 'dev@example.com']])->assertSessionHas('ok');

    expect($mailer->sent)->toBe([['dev@example.com', 'example.com', 'bob_web', 'Secret-123']]);
});

it('refuses the advanced tab to users without the policy', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/advanced', ['template' => 'wordpress'])->assertForbidden();
    $this->fake->assertNotRan('v-change-web-domain-tpl');
});

it('saves the advanced tab for an admin and refuses unknown templates', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-change-web-domain-tpl')->returns();
    fakeRestarts($this->fake);

    $this->post('/web/domains/bob/example.com/advanced', ['template' => 'wordpress', 'backend_template' => 'default', 'proxy' => '1', 'proxy_template' => 'default', 'proxy_extensions' => 'css,js,png,jpg'])
        ->assertSessionHas('ok');
    $this->fake->assertRan('v-change-web-domain-tpl', fn ($a) => $a === ['bob', 'example.com', 'wordpress', 'no']);

    $this->post('/web/domains/bob/example.com/advanced', ['template' => 'evil'])->assertSessionHasErrors(['template'], errorBag: 'advanced');
});

// Single and bulk actions (spec §8.4)

it('suspends, unsuspends, rebuilds, purges and deletes a domain with one round of restarts', function () {
    signIn('arcadmin', 'admin');
    fakeRestarts($this->fake);
    foreach (['v-suspend-web-domain', 'v-unsuspend-web-domain', 'v-rebuild-web-domain', 'v-purge-nginx-cache', 'v-delete-web-domain'] as $command) {
        $this->fake->on($command)->returns();
    }

    $this->post('/web/domains/bob/example.com/suspend')->assertRedirect('/web/domains/bob/example.com/edit');
    $this->post('/web/domains/bob/shop.example/unsuspend')->assertSessionHas('ok');
    $this->post('/web/domains/bob/example.com/rebuild')->assertSessionHas('ok');
    $this->post('/web/domains/bob/example.com/purge-cache')->assertSessionHas('ok');
    $this->delete('/web/domains/bob/example.com')->assertRedirect('/web/domains');

    $this->fake->assertRan('v-suspend-web-domain', fn ($a) => $a === ['bob', 'example.com', 'no']);
    $this->fake->assertRan('v-delete-web-domain', fn ($a) => $a === ['bob', 'example.com', 'no']);
    $this->fake->assertRan('v-restart-web-backend', times: 5);
});

it('does not let users rebuild', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/rebuild')->assertForbidden();
    $this->post('/web/domains/bulk', ['action' => 'rebuild', 'items' => ['bob/example.com']])->assertForbidden();
    $this->fake->assertNotRan('v-rebuild-web-domain');
});

it('runs a bulk action and reports done and failed items', function () {
    signIn('bob', 'user');
    fakeRestarts($this->fake);
    $this->fake->on('v-suspend-web-domain', ['bob', 'example.com', 'no'])->returns();
    $this->fake->on('v-suspend-web-domain', ['bob', 'shop.example', 'no'])->returnsExit(ExitCode::Suspended, 'Error: already suspended');

    $this->post('/web/domains/bulk', ['action' => 'suspend', 'items' => ['bob/example.com', 'bob/shop.example']])
        ->assertRedirect('/web/domains')
        ->assertSessionHas('error', '1 done, 1 failed')
        ->assertSessionHas('error_detail', 'shop.example: Error: already suspended');
});

it('runs nothing when one bulk item belongs to another user', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bulk', ['action' => 'delete', 'items' => ['bob/example.com', 'carol/carol.example']])->assertNotFound();
    $this->fake->assertNotRan('v-delete-web-domain');
});

it('refuses every change in read-only mode', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('Read-only');
    foreach (['general', 'ssl', 'redirect', 'ftp', 'stats', 'suspend', 'purge-cache'] as $action) {
        $this->post("/web/domains/bob/example.com/{$action}")->assertForbidden();
    }
    $this->delete('/web/domains/bob/example.com')->assertForbidden();
    $this->post('/web/domains/bulk', ['action' => 'delete', 'items' => ['bob/example.com']])->assertForbidden();
    $this->post('/web/domains', ['domain' => 'x.example', 'ip' => '10.0.0.10'])->assertForbidden();
});

// Logs (spec §8.5)

it('shows the access and error log, and returns JSON for the refresh button', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-web-domain-accesslog', ['bob', 'example.com'])->returns("203.0.113.7 - - \"GET / HTTP/2.0\" 200 512\n");
    $this->fake->on('v-list-web-domain-errorlog', ['bob', 'example.com'])->returns("[error] boom\n");

    $this->get('/web/domains/bob/example.com/logs/access')->assertOk()->assertSee('GET / HTTP/2.0')->assertSee('/download/web-log/');
    $this->getJson('/web/domains/bob/example.com/logs/error')->assertOk()->assertExactJson(['lines' => ['[error] boom']]);
    $this->get('/web/domains/bob/example.com/logs/other')->assertNotFound();
    $this->get('/web/domains/carol/carol.example/logs/access')->assertNotFound();
});
