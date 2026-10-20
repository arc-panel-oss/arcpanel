<?php

use App\Http\Middleware\PreventRequestForgery;
use App\Services\Mail\HostingCredentialsMailer;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

/*
 * Webmail and database tools ports in the panel (E46,
 * docs/features/specs/2026-10-20-002-e46-webmail-db-admin-ports-design.md §6).
 */

beforeEach(function () {
    $this->fake = fakeArc([
        'IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail',
        'WEBMAIL_PORT' => '8086', 'WEBMAIL_LINK' => 'port', 'DB_ADMIN_PORT' => '8087', 'DB_PMA_ALIAS' => 'phpmyadmin',
    ]);
    fakeServerUsers($this->fake);
    fakeMailDomains($this->fake);
    fakeDatabases($this->fake);
});

// Open Webmail (§6.1)

it('opens webmail on the webmail port of the panel host', function () {
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts')->assertOk()
        ->assertSee('https://localhost:8086/?_user=info%40example.com', false)
        ->assertDontSee('webmail.example.com/?_user=', false);
    $this->get('/mail/bob/example.com/accounts/info/edit')->assertOk()
        ->assertSee('https://localhost:8086/?_user=info%40example.com', false);
    $this->get('/mail')->assertOk()->assertSee('href="https://localhost:8086/"', false);
});

it('opens webmail on the alias of the domain in domain mode, over HTTP while the domain has no SSL', function () {
    withSysConfig($this->fake, [
        'IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail',
        'WEBMAIL_PORT' => '8086', 'WEBMAIL_LINK' => 'domain',
    ]);
    signIn('bob', 'user');

    // example.com has SSL='no' in the fixture
    $this->get('/mail/bob/example.com/accounts')->assertOk()
        ->assertSee('http://webmail.example.com/?_user=info%40example.com', false)
        ->assertDontSee('localhost:8086', false);
});

it('falls back to the domain link when the webmail port is off', function () {
    withSysConfig($this->fake, ['IMAP_SYSTEM' => 'dovecot', 'WEBMAIL_SYSTEM' => 'roundcube', 'WEBMAIL_ALIAS' => 'webmail', 'WEBMAIL_PORT' => '']);
    signIn('bob', 'user');

    $this->get('/mail/bob/example.com/accounts')->assertOk()
        ->assertSee('http://webmail.example.com/?_user=info%40example.com', false);
});

it('keeps the domain link in the credentials mail', function () {
    $mailer = new class implements HostingCredentialsMailer
    {
        public array $sent = [];

        public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void {}

        public function sendMailCredentials(string $to, array $details, string $language): void
        {
            $this->sent[] = $details;
        }
    };
    app()->instance(HostingCredentialsMailer::class, $mailer);
    $this->fake->on('v-add-mail-account')->returns();
    signIn('bob', 'user');

    $this->post('/mail/bob/example.com/accounts', ['account' => 'new', 'password' => 'Secret-123', 'email' => 'me@example.net']);

    expect($mailer->sent[0]['webmail'] ?? null)->toBe('http://webmail.example.com/');
});

// Database tools (§6.1)

it('links phpMyAdmin on the database tools port, also when the panel is opened by IP', function () {
    signIn('bob', 'user');

    $this->get('/db')->assertOk()->assertSee('//localhost:8087/phpmyadmin/', false);
    $this->get('http://127.0.0.1/db')->assertOk()->assertSee('//127.0.0.1:8087/phpmyadmin/', false)
        ->assertDontSee('ap-btn is-disabled', false);
});

it('signs into phpMyAdmin on the database tools port', function () {
    withSysConfig($this->fake, ['DB_ADMIN_PORT' => '8087', 'DB_PMA_ALIAS' => 'phpmyadmin', 'PHPMYADMIN_KEY' => 'pma-key']);
    signIn('bob', 'user');

    $this->post('http://127.0.0.1/db/bob/bob_wp/phpmyadmin')->assertOk()
        ->assertSee('action="https://127.0.0.1:8087/phpmyadmin/arcpanel-sso.php"', false);
});

it('disables the database tool links by IP without the database tools port', function () {
    withSysConfig($this->fake, ['DB_ADMIN_PORT' => '', 'DB_PMA_ALIAS' => 'phpmyadmin']);
    signIn('bob', 'user');

    $this->get('http://127.0.0.1/db')->assertOk()->assertSee('ap-btn is-disabled', false);
});

it('keeps the host name links without the database tools port', function () {
    withSysConfig($this->fake, ['DB_ADMIN_PORT' => '', 'DB_PMA_ALIAS' => 'phpmyadmin', 'PHPMYADMIN_KEY' => 'pma-key']);
    signIn('bob', 'user');

    $this->post('/db/bob/bob_wp/phpmyadmin')->assertOk()
        ->assertSee('action="https://localhost/phpmyadmin/arcpanel-sso.php"', false);
    $this->post('http://127.0.0.1/db/bob/bob_wp/phpmyadmin')->assertForbidden();
});

// Server settings (§6.2)

it('changes the webmail port, the webmail link and the database tools port', function () {
    foreach (['v-change-sys-webmail-port', 'v-change-sys-webmail-link', 'v-change-sys-db-admin-port'] as $command) {
        $this->fake->on($command)->returns();
    }
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net', 'UPTIME' => '10', 'LOADAVERAGE' => '0 / 0 / 0']]);
    $this->fake->on('v-list-database-hosts')->returnsJson([]);
    signIn('arcadmin', 'admin');

    $this->get('/server/settings/mail')->assertOk()->assertSee('name="webmail_port"', false)->assertSee('value="8086"', false);
    $this->get('/server/settings/databases')->assertOk()->assertSee('name="db_admin_port"', false)->assertSee('value="8087"', false);

    // Unchanged values run nothing
    $this->post('/server/settings/mail', ['webmail_port' => '8086', 'webmail_link' => 'port'])->assertSessionHas('ok', 'Nothing has changed.');

    $this->post('/server/settings/mail', ['webmail_port' => '38460', 'webmail_link' => 'domain'])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-webmail-port', fn ($a) => $a === ['38460']);
    $this->fake->assertRan('v-change-sys-webmail-link', fn ($a) => $a === ['domain']);

    $this->post('/server/settings/mail', ['webmail_port' => ''])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-webmail-port', fn ($a) => $a === ['none']);

    $this->post('/server/settings/mail', ['webmail_port' => '80'])->assertSessionHasErrors('webmail_port');
    $this->post('/server/settings/mail', ['webmail_link' => 'elsewhere'])->assertSessionHasErrors('webmail_link');

    $this->post('/server/settings/databases', ['db_admin_port' => ''])->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-db-admin-port', fn ($a) => $a === ['none']);
});

// Cookies (§6.3)

it('sends no cookie that scripts can read except the display preferences', function () {
    signIn('bob', 'user');

    foreach (['/login', '/mail', '/db'] as $path) {
        $response = $this->get($path);
        foreach ($response->headers->getCookies() as $cookie) {
            expect($cookie->getName())->not->toBe('XSRF-TOKEN');
            if ($cookie->getName() !== 'arc_ui') {
                expect($cookie->isHttpOnly())->toBeTrue();
            }
        }
    }
});

it('refuses a same-site form post without the token, as from a tool port', function () {
    $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };
    $request = Request::create('https://localhost/server/settings/mail', 'POST', ['webmail_port' => '9999']);
    $request->headers->set('Sec-Fetch-Site', 'same-site');
    $request->setLaravelSession(app('session')->driver());

    expect(fn () => $middleware->handle($request, fn () => response('ran')))
        ->toThrow(TokenMismatchException::class);
});
