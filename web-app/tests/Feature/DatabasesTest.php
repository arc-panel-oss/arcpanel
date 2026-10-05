<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use App\Services\Mail\HostingCredentialsMailer;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeDatabases($this->fake);
});

// List (spec §6.2)

it('lists only the databases of a user', function () {
    signIn('bob', 'user');

    $this->get('/db')->assertOk()
        ->assertSee('bob_wp')->assertSee('bob_shop')->assertSee('bob_shopuser')
        ->assertDontSee('carol_app')->assertDontSee('All owners')
        ->assertSee('phpMyAdmin')->assertDontSee('phpPgAdmin');
});

it('lists the databases of every user for an admin, with an owner filter', function () {
    signIn('arcadmin', 'admin');

    $this->get('/db')->assertOk()
        ->assertSee('bob_wp')->assertSee('carol_app')->assertSee('All owners')
        ->assertSeeInOrder(['bob_shop', 'bob_wp', 'carol_app']);
    $this->get('/db?owner=carol')->assertOk()->assertSee('carol_app')->assertDontSee('bob_wp');
    $this->get('/db?owner=ghost')->assertNotFound();
});

it('scopes the list of a reseller to its tree', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/db')->assertOk()->assertSee('bob_wp')->assertDontSee('carol_app');
    $this->get('/db?owner=carol')->assertNotFound();
    $this->get('/db/carol/carol_app/edit')->assertNotFound();
    $this->get('/db/bob/bob_wp/edit')->assertOk();
});

it('offers rebuild to administrators only', function () {
    signIn('bob', 'user');
    $this->get('/db')->assertOk()->assertDontSee('value="rebuild"', false);

    signIn('arcadmin', 'admin');
    $this->get('/db')->assertOk()->assertSee('value="rebuild"', false);
});

it('shows an empty state without databases', function () {
    signIn('arcadmin', 'admin');

    $this->get('/db?owner=arcadmin')->assertOk()->assertSee('No databases for this owner.');
});

// Add (spec §6.2)

it('adds a database as web/add/db, with the password in a file', function () {
    $this->fake->on('v-add-database')->returns();
    signIn('bob', 'user');

    $this->get('/db/create')->assertOk()->assertSee('bob_')->assertSee('utf8mb4')->assertDontSee('strongly advised');
    $this->post('/db', [
        'database' => 'blog', 'dbuser' => 'blog', 'password' => 'Secret-123', 'type' => 'mysql', 'host' => 'localhost', 'charset' => 'utf8mb4',
    ])->assertRedirect('/db/bob/bob_blog/edit')->assertSessionHas('ok', 'Database bob_blog has been created successfully.');

    $this->fake->assertRan('v-add-database', fn ($a) => $a === ['bob', 'blog', 'blog', 'Secret-123', 'mysql', 'localhost', 'utf8mb4']);
});

it('mails the credentials when an address is given', function () {
    $this->fake->on('v-add-database')->returns();
    $mailer = new class implements HostingCredentialsMailer
    {
        public array $sent = [];

        public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void
        {
            $this->sent[] = [$to, $database, $dbUser, $password, $dbAdmin];
        }

        public function sendMailCredentials(string $to, array $details, string $language): void {}
    };
    app()->instance(HostingCredentialsMailer::class, $mailer);
    signIn('bob', 'user');

    $this->post('/db', [
        'database' => 'blog', 'dbuser' => 'admin', 'password' => 'Secret-123', 'email' => 'dev@example.com',
        'type' => 'mysql', 'host' => 'localhost', 'charset' => 'utf8mb4',
    ])->assertRedirect();

    expect($mailer->sent)->toBe([['dev@example.com', 'bob_blog', 'bob_admin', 'Secret-123', 'https://localhost/phpmyadmin/']]);
});

it('validates the add form as the legacy page', function () {
    signIn('bob', 'user');

    $this->post('/db', ['database' => '', 'dbuser' => 'x', 'password' => 'weak', 'email' => 'nope', 'type' => 'mysql', 'host' => 'localhost', 'charset' => 'utf8mb4'])
        ->assertSessionHasErrors(['database', 'password', 'email']);
    $this->post('/db', ['database' => 'a', 'dbuser' => 'a', 'password' => 'Secret-123', 'type' => 'pgsql', 'host' => 'localhost', 'charset' => 'utf8mb4'])
        ->assertSessionHasErrors(['type']);
    $this->post('/db', ['database' => 'a', 'dbuser' => 'a', 'password' => 'Secret-123', 'type' => 'mysql', 'host' => 'db.example', 'charset' => 'utf8mb4'])
        ->assertSessionHasErrors(['host']);
    $this->fake->assertNotRan('v-add-database');
});

it('keeps the input and shows the command error when the database cannot be added', function () {
    $this->fake->on('v-add-database')->returnsExit(ExitCode::Exists, 'Error: database bob_wp exists');
    signIn('bob', 'user');

    $this->from('/db/create')->post('/db', ['database' => 'wp', 'dbuser' => 'wp', 'password' => 'Secret-123', 'type' => 'mysql', 'host' => 'localhost', 'charset' => 'utf8mb4'])
        ->assertRedirect('/db/create')->assertSessionHas('error', 'Error: database bob_wp exists')
        ->assertSessionHasInput('database', 'wp')->assertSessionMissing('_old_input.password');
});

it('lets an admin add a database for another user and warns about its own account', function () {
    $this->fake->on('v-add-database')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/db/create')->assertOk()->assertSee('strongly advised')->assertSee('Use this owner');
    $this->get('/db/create?owner=carol')->assertOk()->assertDontSee('strongly advised');
    $this->post('/db', ['owner' => 'carol', 'database' => 'x', 'dbuser' => 'x', 'password' => 'Secret-123', 'type' => 'mysql', 'host' => 'localhost', 'charset' => 'latin1'])
        ->assertRedirect('/db/carol/carol_x/edit');
    $this->fake->assertRan('v-add-database', fn ($a) => $a[0] === 'carol' && $a[6] === 'latin1');
});

it('does not let a user add a database for someone else', function () {
    signIn('bob', 'user');

    $this->get('/db/create?owner=carol')->assertNotFound();
    $this->post('/db', ['owner' => 'carol', 'database' => 'x', 'dbuser' => 'x', 'password' => 'Secret-123', 'type' => 'mysql', 'host' => 'localhost', 'charset' => 'utf8mb4'])
        ->assertNotFound();
});

// Edit (spec §6.2)

it('shows the edit form without the prefix and answers 404 for unknown databases', function () {
    signIn('bob', 'user');

    $this->get('/db/bob/bob_shop/edit')->assertOk()->assertSee('value="shopuser"', false)->assertSee('UTF8MB4');
    $this->get('/db/bob/bob_nope/edit')->assertNotFound();
    $this->get('/db/carol/carol_app/edit')->assertNotFound();
});

it('changes only the user name and password that were given', function () {
    $this->fake->on('v-change-database-user')->returns();
    $this->fake->on('v-change-database-password')->returns();
    signIn('bob', 'user');

    $this->post('/db/bob/bob_wp/edit', ['dbuser' => 'wp', 'password' => ''])
        ->assertRedirect('/db/bob/bob_wp/edit')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertNotRan('v-change-database-user');
    $this->fake->assertNotRan('v-change-database-password');

    $this->post('/db/bob/bob_wp/edit', ['dbuser' => 'wpnew', 'password' => 'Secret-456'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-database-user', fn ($a) => $a === ['bob', 'bob_wp', 'wpnew']);
    $this->fake->assertRan('v-change-database-password', fn ($a) => $a === ['bob', 'bob_wp', 'Secret-456']);
});

it('names the failed step of an edit', function () {
    $this->fake->on('v-change-database-user')->returnsExit(ExitCode::Exists, 'Error: user exists');
    signIn('bob', 'user');

    $this->post('/db/bob/bob_wp/edit', ['dbuser' => 'taken'])
        ->assertSessionHas('error', 'Change the database user failed')->assertSessionHas('error_detail', 'Error: user exists');
});

// Single and bulk actions (spec §6.1)

it('suspends, unsuspends and deletes a database', function () {
    foreach (['v-suspend-database', 'v-unsuspend-database', 'v-delete-database'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/db/bob/bob_wp/suspend')->assertSessionHas('ok', 'Database bob_wp has been suspended.');
    $this->post('/db/bob/bob_shop/unsuspend')->assertSessionHas('ok', 'Database bob_shop has been unsuspended.');
    $this->delete('/db/bob/bob_wp')->assertRedirect('/db')->assertSessionHas('ok', 'Database bob_wp has been deleted.');

    $this->fake->assertRan('v-suspend-database', fn ($a) => $a === ['bob', 'bob_wp']);
    $this->fake->assertRan('v-delete-database', fn ($a) => $a === ['bob', 'bob_wp']);
});

it('runs a bulk action and reports done and failed items', function () {
    $this->fake->on('v-delete-database')->returns();
    $this->fake->on('v-delete-database', ['bob', 'bob_shop'])->returnsExit(ExitCode::InUse, 'Error: in use');
    signIn('bob', 'user');

    $this->post('/db/bulk', ['action' => 'delete', 'items' => ['bob/bob_wp', 'bob/bob_shop']])
        ->assertRedirect('/db')->assertSessionHas('error', '1 done, 1 failed')->assertSessionHas('error_detail', 'bob_shop: Error: in use');
});

it('runs nothing when one bulk item belongs to another user, and refuses rebuild to users', function () {
    signIn('bob', 'user');

    $this->post('/db/bulk', ['action' => 'suspend', 'items' => ['bob/bob_wp', 'carol/carol_app']])->assertNotFound();
    $this->post('/db/bulk', ['action' => 'rebuild', 'items' => ['bob/bob_wp']])->assertForbidden();
    $this->fake->assertNotRan('v-suspend-database');
    $this->fake->assertNotRan('v-rebuild-database');
});

it('lets an admin rebuild databases of several users', function () {
    $this->fake->on('v-rebuild-database')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/db/bulk', ['action' => 'rebuild', 'items' => ['bob/bob_wp', 'carol/carol_app']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->fake->assertRan('v-rebuild-database', times: 2);
});

it('serves a database dump through NGINX', function () {
    $this->fake->on('v-dump-database', ['bob', 'bob_wp', 'file', 'gzip'])->returns("/backup/bob_bob_wp_mysql_2026-10-06_10-00-00.sql.gz\n");
    signIn('bob', 'user');

    $this->post('/db/bob/bob_wp/download')->assertOk()
        ->assertHeader('X-Accel-Redirect', '/backup/bob_bob_wp_mysql_2026-10-06_10-00-00.sql.gz')
        ->assertHeader('Content-Disposition', 'attachment; filename="bob_bob_wp_mysql_2026-10-06_10-00-00.sql.gz"');
});

it('signs into phpMyAdmin with a short-lived token when single sign-on is set up', function () {
    withSysConfig($this->fake, ['PHPMYADMIN_KEY' => 'pma-key']);
    signIn('bob', 'user');

    $this->get('/db')->assertOk()->assertSee('/db/bob/bob_wp/phpmyadmin');
    $response = $this->post('/db/bob/bob_wp/phpmyadmin');
    $response->assertRedirect();
    $url = $response->headers->get('Location');
    expect($url)->toStartWith('https://localhost/phpmyadmin/hestia-sso.php?');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['database'])->toBe('bob_wp')->and($query['user'])->toBe('bob')
        ->and(password_verify('bob_wp'.'bob'.'127.0.0.1'.$query['exp'].'pma-key', $query['hestia_token']))->toBeTrue();

    // suspended databases have no sign-on
    $this->post('/db/bob/bob_shop/phpmyadmin')->assertNotFound();
});

it('has no single sign-on without PHPMYADMIN_KEY', function () {
    signIn('bob', 'user');

    $this->get('/db')->assertOk()->assertDontSee('/db/bob/bob_wp/phpmyadmin');
    $this->post('/db/bob/bob_wp/phpmyadmin')->assertForbidden();
});

it('refuses every change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/db')->assertOk();
    foreach (['edit', 'suspend', 'unsuspend', 'download', 'phpmyadmin'] as $action) {
        $this->post("/db/bob/bob_wp/{$action}")->assertForbidden();
    }
    $this->delete('/db/bob/bob_wp')->assertForbidden();
    $this->post('/db/bulk', ['action' => 'delete', 'items' => ['bob/bob_wp']])->assertForbidden();
    $this->post('/db', ['database' => 'x'])->assertForbidden();
});

it('has a capability for every database route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'db.'));

    expect($routes)->not->toBeEmpty();
    foreach (['db.view', 'db.create', 'db.update', 'db.delete'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});
