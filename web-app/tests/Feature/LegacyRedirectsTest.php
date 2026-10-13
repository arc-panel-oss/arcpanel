<?php

// Old URLs after the 2.0 cut-over (E8 spec §5.3, §5.4).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-log-action')->returns();
});

// The /app prefix of 1.x

it('sends /app URLs to the root URL with the query string', function () {
    $this->get('/app/web/domains?owner=bob')->assertStatus(301)->assertRedirect('/web/domains?owner=bob');
    $this->get('/app')->assertStatus(301)->assertRedirect('/');
    $this->get('/app/')->assertStatus(301)->assertRedirect('/');
});

it('keeps /app redirects on this host', function () {
    $location = $this->get('/app//example.net/x')->assertStatus(301)->headers->get('Location');

    expect($location)->toBe(url('/example.net/x'));
});

// Legacy page paths

it('sends legacy pages without an owner to their panel page, also for guests', function (string $legacy, string $target) {
    $this->get($legacy)->assertStatus(301)->assertRedirect($target);
})->with([
    'web list' => ['/list/web/', '/web/domains'],
    'web list, index.php' => ['/list/web/index.php', '/web/domains'],
    'web add' => ['/add/web/', '/web/domains/create'],
    'users' => ['/list/user/', '/users'],
    'add user' => ['/add/user/', '/users/create'],
    'packages' => ['/list/package/', '/packages'],
    'package edit' => ['/edit/package/?package=default', '/packages/default/edit'],
    'firewall rule' => ['/edit/firewall/?rule=2', '/server/firewall/2/edit'],
    'ip' => ['/edit/ip/?ip=10.0.0.10', '/server/ips/10.0.0.10/edit'],
    'service report' => ['/list/server/?cpu', '/server/services/status/cpu'],
    'service config' => ['/edit/server/nginx/', '/server/services/nginx/config'],
    'white label' => ['/edit/server/whitelabel/', '/server/settings/white-label'],
    'graphs' => ['/list/rrd/?period=weekly', '/server/rrd?period=weekly'],
    'file manager' => ['/fm/', '/files'],
]);

it('sends legacy pages of an object to the page of the effective user', function () {
    signIn('bob', 'user');

    $this->get('/edit/web/?domain=example.com')->assertStatus(302)->assertRedirect('/web/domains/bob/example.com/edit');
    $this->get('/list/web-log/?domain=example.com&type=error')->assertRedirect('/web/domains/bob/example.com/logs/error');
    $this->get('/download/web-log/?domain=example.com&type=access')->assertRedirect('/web/domains/bob/example.com/logs/access/download');
    $this->get('/edit/dns/?domain=example.com&record_id=4')->assertRedirect('/dns/bob/example.com/records?edit=4');
    $this->get('/edit/mail/?domain=example.com&account=info')->assertRedirect('/mail/bob/example.com/accounts/info/edit');
    $this->get('/list/backup/incremental/?snapshot=0123abcd&browse=yes')->assertRedirect('/files/bob?snapshot=0123abcd');
    $this->get('/edit/user/')->assertRedirect('/account');
    // A user cannot name another owner.
    $this->get('/edit/web/?domain=carol.example&user=carol')->assertRedirect('/web/domains/bob/carol.example/edit');
});

it('lets an administrator name the owner, but not while impersonating', function () {
    signIn('arcadmin', 'admin');
    $this->get('/edit/web/?domain=carol.example&user=carol')->assertRedirect('/web/domains/carol/carol.example/edit');
    $this->get('/edit/user/?user=bob')->assertRedirect('/users/bob/edit');
    $this->get('/list/stats/?user=bob')->assertStatus(302)->assertRedirect('/stats?owner=bob');

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/edit/web/?domain=carol.example&user=carol')->assertRedirect('/web/domains/bob/carol.example/edit');
});

it('sends guests who open an object page to the login page', function () {
    $this->get('/edit/web/?domain=example.com')->assertStatus(302)->assertRedirect('/login');
});

it('answers 404 for unknown legacy paths, invalid parameters and form posts', function () {
    signIn('bob', 'user');

    $this->get('/list/nothing/')->assertNotFound();
    $this->get('/edit/web/')->assertNotFound();
    $this->get('/edit/cron/?job=abc')->assertNotFound();
    $this->get('/edit/mail/?domain=example.com&account=a%20b')->assertNotFound();
    $this->post('/list/web/')->assertNotFound();
});

it('sends the 1.x logout link and the reset e-mail links to the panel pages', function () {
    $this->get('/logout/')->assertStatus(301)->assertRedirect('/login');
    // The code goes into the session, not into the next URL (E39 W2 D9)
    $this->get('/reset/?action=confirm&user=bob&code=abc123')->assertRedirect('/reset/confirm');
    expect(session('reset'))->toBe(['user' => 'bob', 'code' => 'abc123']);
    $this->get('/reset/?action=code&user=bob')->assertRedirect('/reset/code?user=bob');
    $this->get('/reset/')->assertOk();
});

// Web log download (spec §5.4)

it('downloads the last 5000 lines of a log', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-web-domain-errorlog', ['bob', 'example.com', '5000'])->returns("[error] one\n[error] two\n");

    $response = $this->get('/web/domains/bob/example.com/logs/error/download')->assertOk()
        ->assertDownload('example.com.error-log.txt');

    expect($response->streamedContent())->toBe("[error] one\n[error] two\n");
});

it('lets an administrator download any log', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-list-web-domain-accesslog', ['carol', 'carol.example', '5000'])->returns("line\n");

    $this->get('/web/domains/carol/carol.example/logs/access/download')->assertOk()->assertDownload('carol.example.access-log.txt');
});

it('answers 404 for a log outside the user', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/carol/carol.example/logs/access/download')->assertNotFound();
    $this->get('/web/domains/bob/example.com/logs/other/download')->assertNotFound();
    $this->fake->assertNotRan('v-list-web-domain-accesslog');
});

it('scopes log downloads to the reseller tree', function () {
    fakeResellers($this->fake);
    $this->fake->on('v-list-web-domain-accesslog', ['bob', 'example.com', '5000'])->returns("line\n");

    signIn('hostco', 'reseller');
    $this->get('/web/domains/bob/example.com/logs/access/download')->assertOk();

    signIn('resb', 'reseller');
    $this->get('/web/domains/bob/example.com/logs/access/download')->assertNotFound();
});
