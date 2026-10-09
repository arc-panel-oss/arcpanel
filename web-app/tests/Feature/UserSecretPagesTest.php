<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Secret store pages (E38 spec §7).

const STORE_VALUE = 'p4ss-never-shown-0123456789';

/** @return array<string, mixed> `v-list-user-secret-store USER json` */
function userSecretList(string $user): array
{
    if ($user !== 'bob') {
        return [];
    }

    return [
        '/shop/db_password' => ['NAME' => '/shop/db_password', 'DESCRIPTION' => 'Shop database', 'VERSION' => '3',
            'FINGERPRINT' => 'a1b2c3d4', 'USED_BY' => '2', 'TIME' => '10:00:00', 'DATE' => '2026-10-09'],
        '/mail/token' => ['NAME' => '/mail/token', 'DESCRIPTION' => '', 'VERSION' => '1',
            'FINGERPRINT' => '0f0f0f0f', 'USED_BY' => '0', 'TIME' => '09:00:00', 'DATE' => '2026-10-08'],
    ];
}

/** @return array<string, mixed> `v-list-user-secret USER NAME json` */
function userSecretItem(string $name): array
{
    return [$name => ['NAME' => $name, 'DESCRIPTION' => 'Shop database', 'VERSION' => '3', 'FINGERPRINT' => 'a1b2c3d4',
        'REFERENCE' => '{{secret:'.$name.'}}',
        'VERSIONS' => [
            ['VERSION' => '2', 'FINGERPRINT' => 'eeee1111', 'TIME' => '09:00:00', 'DATE' => '2026-10-08'],
            ['VERSION' => '3', 'FINGERPRINT' => 'a1b2c3d4', 'TIME' => '10:00:00', 'DATE' => '2026-10-09'],
        ],
        'USED_BY' => ['node:shop.bob.example', 'docker:shop'], 'TIME' => '10:00:00', 'DATE' => '2026-10-09']];
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    $this->fake->on('v-list-user-secret-store')->using(fn (array $args) => [0, (string) json_encode(userSecretList($args[0]))]);
    $this->fake->on('v-list-user-secret')->using(fn (array $args) => isset(userSecretList($args[0])[$args[1]])
        ? [0, (string) json_encode(userSecretItem($args[1]))]
        : [ExitCode::NotExist->value, "Error: secret {$args[1]} not found"]);
});

it('lists the secrets of an account without values', function () {
    signIn('bob', 'user');

    $this->get('/secrets')->assertRedirect('/secrets/bob');
    $this->get('/secrets/bob')->assertOk()
        ->assertSee('/shop/db_password')->assertSee('Shop database')->assertSee('a1b2c3d4')
        ->assertSee('/mail/token')->assertSee('2 secrets')
        ->assertSee('2 apps')->assertSee('Not used')
        ->assertDontSee(STORE_VALUE);
    $this->get('/secrets/carol')->assertNotFound();

    signIn('carol', 'user');
    $this->get('/secrets/carol')->assertOk()->assertSee('No secrets');
});

it('lets resellers and administrators manage the secrets of their customers', function () {
    signIn('hostco', 'reseller');
    $this->get('/secrets/bob')->assertOk()->assertSee('/shop/db_password');
    $this->get('/secrets/carol')->assertNotFound();

    signIn('arcadmin', 'admin');
    $this->get('/secrets/carol')->assertOk();
    $this->get('/secrets/bob')->assertOk()->assertSee('Show secrets');
});

it('shows one secret with its versions, its reference and the apps that use it', function () {
    signIn('bob', 'user');

    $this->get('/secrets/bob/item?name=/shop/db_password')->assertOk()
        ->assertSee('{{secret:/shop/db_password}}')->assertSee('eeee1111')->assertSee('Version 2')
        ->assertSee('shop.bob.example')->assertSee('Restart the 2 apps that use it')
        ->assertSee('Used by 2 apps')->assertSee('Roll back to version 2?')
        ->assertSee('name="version" value="2"', false)
        ->assertSee(route('node.apps.show', ['user' => 'bob', 'domain' => 'shop.bob.example']), false)
        ->assertDontSee(STORE_VALUE);
    $this->get('/secrets/bob/item?name=/nope')->assertNotFound();
    $this->get('/secrets/bob/item?name=bad name')->assertNotFound();
});

it('adds a secret and passes the value in a file', function () {
    $this->fake->on('v-add-user-secret')->returns();
    signIn('bob', 'user');

    $this->post('/secrets/bob', ['name' => '/shop/api_key', 'value' => STORE_VALUE, 'description' => 'Payment API'])
        ->assertRedirect('/secrets/bob/item?name=%2Fshop%2Fapi_key')
        ->assertSessionHas('ok', 'Secret /shop/api_key has been created. Use it as {{secret:/shop/api_key}}.');
    $this->fake->assertRan('v-add-user-secret', fn ($a) => $a === ['bob', '/shop/api_key', STORE_VALUE, 'Payment API']);
    expect(json_encode(session()->all()))->not->toContain(STORE_VALUE);

    $this->post('/secrets/bob', ['name' => 'no-slash', 'value' => STORE_VALUE])->assertSessionHasErrors(['name']);
    expect(json_encode(session()->all()))->not->toContain(STORE_VALUE);
    $this->post('/secrets/bob/item/value', ['name' => '/shop/db_password', 'value' => STORE_VALUE, 'restart' => 'maybe'])
        ->assertSessionHasErrors(['restart']);
    expect(json_encode(session()->all()))->not->toContain(STORE_VALUE);
    $this->post('/secrets/bob', ['name' => '/a/../b', 'value' => 'x'])->assertSessionHasErrors(['name']);
    $this->post('/secrets/bob', ['name' => '/ok', 'value' => ''])->assertSessionHasErrors(['value']);
    $this->post('/secrets/bob', ['name' => '/ok', 'value' => 'x', 'description' => "it's"])->assertSessionHasErrors(['description']);
    $this->post('/secrets/bob', ['name' => '/ok', 'value' => 'x', 'description' => 'db|prod'])->assertSessionHasErrors(['description']);
});

it('shows the reason when the command refuses a new secret', function () {
    $this->fake->on('v-add-user-secret')->returnsExit(ExitCode::Exists, 'Error: secret /shop/db_password already exists');
    signIn('bob', 'user');

    $this->post('/secrets/bob', ['name' => '/shop/db_password', 'value' => STORE_VALUE])
        ->assertRedirect('/secrets/bob')->assertSessionHas('error', 'secret /shop/db_password already exists');
});

it('replaces a value and restarts the apps that use it on request', function () {
    $this->fake->on('v-change-user-secret')->returns("node:shop.bob.example\ndocker:shop");
    signIn('bob', 'user');

    $this->post('/secrets/bob/item/value', ['name' => '/shop/db_password', 'value' => 'new-value-1', 'restart' => '1'])
        ->assertRedirect('/secrets/bob/item?name=%2Fshop%2Fdb_password')
        ->assertSessionHas('ok', 'Secret /shop/db_password has been changed. Restarted: node:shop.bob.example, docker:shop.');
    $this->fake->assertRan('v-change-user-secret', fn ($a) => $a === ['bob', '/shop/db_password', 'new-value-1', 'yes']);

    $this->post('/secrets/bob/item/value', ['name' => '/shop/db_password', 'value' => 'new-value-2'])
        ->assertSessionHas('ok', 'Secret /shop/db_password has been changed. Restart these apps to use it: node:shop.bob.example, docker:shop.');
    $this->fake->assertRan('v-change-user-secret', fn ($a) => $a === ['bob', '/shop/db_password', 'new-value-2', 'no']);

    // an app that does not restart is named; the value is changed anyway
    $this->fake->on('v-change-user-secret')->returns("node:shop.bob.example
docker:shop
failed:docker:shop");
    $this->post('/secrets/bob/item/value', ['name' => '/shop/db_password', 'value' => 'new-value-3', 'restart' => '1'])
        ->assertSessionHas('ok', 'Secret /shop/db_password has been changed. Restarted: node:shop.bob.example, docker:shop. These apps did not restart: docker:shop.');
});

it('rolls back to a kept version and changes the description', function () {
    $this->fake->on('v-rollback-user-secret')->returns();
    $this->fake->on('v-change-user-secret-description')->returns();
    signIn('bob', 'user');

    $this->post('/secrets/bob/item/rollback', ['name' => '/shop/db_password', 'version' => '2'])
        ->assertSessionHas('ok', 'Secret /shop/db_password has been rolled back to version 2.');
    $this->fake->assertRan('v-rollback-user-secret', fn ($a) => $a === ['bob', '/shop/db_password', '2', 'no']);
    $this->post('/secrets/bob/item/rollback', ['name' => '/shop/db_password', 'version' => '2', 'restart' => '1'])
        ->assertSessionHas('ok', 'Secret /shop/db_password has been rolled back to version 2.');
    $this->fake->assertRan('v-rollback-user-secret', fn ($a) => $a === ['bob', '/shop/db_password', '2', 'yes']);
    $this->post('/secrets/bob/item/rollback', ['name' => '/shop/db_password', 'version' => 'x'])->assertSessionHasErrors(['version']);

    $this->patch('/secrets/bob/item', ['name' => '/shop/db_password', 'description' => 'Shop DB'])
        ->assertSessionHas('ok', 'The description has been saved.');
    $this->fake->assertRan('v-change-user-secret-description', fn ($a) => $a === ['bob', '/shop/db_password', 'Shop DB']);
});

it('refuses to delete a used secret unless forced', function () {
    $this->fake->on('v-delete-user-secret')->using(fn (array $args) => ($args[2] ?? 'no') === 'yes'
        ? [0, '']
        : [ExitCode::InUse->value, 'Error: secret /shop/db_password is used by node:shop.bob.example docker:shop']);
    signIn('bob', 'user');

    $this->delete('/secrets/bob/item', ['name' => '/shop/db_password'])
        ->assertRedirect('/secrets/bob/item?name=%2Fshop%2Fdb_password')
        ->assertSessionHas('error', 'secret /shop/db_password is used by node:shop.bob.example docker:shop');
    $this->delete('/secrets/bob/item', ['name' => '/shop/db_password', 'force' => '1'])
        ->assertRedirect('/secrets/bob')->assertSessionHas('ok', 'Secret /shop/db_password has been deleted.');
    $this->fake->assertRan('v-delete-user-secret', fn ($a) => $a === ['bob', '/shop/db_password', 'yes']);
    $this->delete('/secrets/bob/item', ['name' => '/ghost'])->assertNotFound();
});

it('refuses changes outside the tree and in read-only sessions', function () {
    signIn('hostco', 'reseller');
    $this->post('/secrets/carol', ['name' => '/x', 'value' => 'y'])->assertNotFound();

    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');
    $this->get('/secrets/bob')->assertOk()->assertDontSee('Add Secret');
    $this->post('/secrets/bob', ['name' => '/x', 'value' => 'y'])->assertForbidden();
    $this->delete('/secrets/bob/item', ['name' => '/shop/db_password'])->assertForbidden();
    $this->fake->assertNotRan('v-add-user-secret');
});

it('has a capability for every secret route and a navigation entry', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'secrets.'));

    expect($routes)->toContain('secrets.index', 'secrets.list', 'secrets.show', 'secrets.store', 'secrets.value',
        'secrets.rollback', 'secrets.describe', 'secrets.destroy');
    expect(Capabilities::names())->toContain('secrets.view', 'secrets.manage');

    signIn('bob', 'user');
    $this->get('/secrets/bob')->assertSee('href="'.url('/secrets').'"', false);
});
