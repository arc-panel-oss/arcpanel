<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Access keys and SSH keys (E7 batch 2 spec §8).

const BOB_KEY = 'AbCdEfGhIjKlMnOpQr12';
const BOB_OLD_KEY = 'ZyXwVuTsRqPoNmLkJi98';
const CAROL_KEY = 'CaRoLkEyCaRoLkEy0001';
const SECRET = 'very-secret-value-0123456789abcdefghijklmn';

/** @return array<string, array<string, mixed>> access keys by id */
function accessKeys(): array
{
    $key = fn (string $id, string $user, array $permissions, string $comment, string $date, string $time) => [
        'ACCESS_KEY_ID' => $id, 'SECRET_ACCESS_KEY' => SECRET, 'USER' => $user, 'PERMISSIONS' => $permissions,
        'COMMENT' => $comment, 'TIME' => $time, 'DATE' => $date,
    ];

    return [
        BOB_KEY => $key(BOB_KEY, 'bob', ['purge-nginx-cache'], 'deploy bot', '2026-10-05', '10:00:00'),
        BOB_OLD_KEY => $key(BOB_OLD_KEY, 'bob', ['update-dns-records'], '', '2026-01-02', '09:00:00'),
        CAROL_KEY => $key(CAROL_KEY, 'carol', ['purge-nginx-cache'], 'carol', '2026-10-01', '08:00:00'),
    ];
}

beforeEach(function () {
    $this->fake = fakeArc(['API_SYSTEM' => '2']);
    fakeServerUsers($this->fake);
    $keys = accessKeys();
    $this->fake->on('v-list-access-keys')->using(function (array $args) use ($keys) {
        $rows = [];
        foreach ($keys as $id => $row) {
            if ($row['USER'] === $args[0]) {
                unset($row['SECRET_ACCESS_KEY']);
                $rows[$id] = $row;
            }
        }

        return [0, (string) json_encode($rows)];
    });
    $this->fake->on('v-list-access-key')->using(fn (array $args) => isset($keys[$args[0]])
        ? [0, (string) json_encode($keys[$args[0]])]
        : [ExitCode::NotExist->value, 'Error: key not found']);
    $this->fake->on('v-list-apis')->returnsJson([
        'billing' => ['COMMANDS' => ['v-add-user'], 'ROLE' => 'admin'],
        'purge-nginx-cache' => ['COMMANDS' => ['v-purge-nginx-cache'], 'ROLE' => 'user'],
        'update-dns-records' => ['COMMANDS' => ['v-change-dns-record'], 'ROLE' => 'user'],
        'v2-read' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-accounts' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-web' => ['COMMANDS' => ['v-list-web-domain'], 'ROLE' => 'user', 'API_VERSION' => '2'],
    ]);
    $this->fake->on('v-list-user-ssh-key')->using(fn (array $args) => $args[0] === 'bob'
        ? [0, (string) json_encode([
            'bob@laptop' => ['ID' => 'bob@laptop', 'KEY' => 'SHA256:laptopfingerprint'],
            'filemanager.ssh.key' => ['ID' => 'filemanager.ssh.key', 'KEY' => 'SHA256:filemanager'],
        ])]
        : [0, '']);
});

// Access keys (§8.1, §8.2)

it('lists the access keys of a user, oldest first and without secrets', function () {
    signIn('bob', 'user');

    $this->get('/access-keys')->assertRedirect('/access-keys/bob');
    $this->get('/access-keys/bob')->assertOk()
        ->assertSeeInOrder([BOB_OLD_KEY, BOB_KEY])->assertSee('deploy bot')->assertDontSee(CAROL_KEY)
        ->assertSeeInOrder(['Access Key', 'Permissions', 'Created'])
        ->assertSeeInOrder(['deploy bot', BOB_KEY, 'purge-nginx-cache', '2026-10-05', '10:00:00'])
        ->assertSee('data-search="'.BOB_KEY.' deploy bot purge-nginx-cache"', false)
        ->assertSee('Are you sure you want to delete access key '.BOB_KEY.'?')->assertDontSee(SECRET);
    $this->get('/access-keys/carol')->assertNotFound();
    $this->get('/access-keys?owner=carol')->assertNotFound();
});

it('shows one key without its secret, and only to its owner', function () {
    signIn('bob', 'user');

    $this->get('/access-keys/bob/'.BOB_KEY)->assertOk()
        ->assertSee('Access Key ID')->assertSee('purge-nginx-cache')->assertSee('deploy bot')
        ->assertSeeInOrder(['Created', '2026-10-05', '10:00:00', 'Permissions', 'purge-nginx-cache', 'API v1', 'Purge the NGINX cache of web domains.'])
        ->assertDontSee(SECRET)->assertDontSee('Last chance')->assertDontSee('Bearer');
    $this->get('/access-keys/bob/'.CAROL_KEY)->assertNotFound();
    $this->get('/access-keys/bob/NoSuchKeyNoSuchKey00')->assertNotFound();
});

it('follows API_SYSTEM', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '0']);
    signIn('arcadmin', 'admin');
    $this->get('/access-keys/arcadmin')->assertNotFound();

    withSysConfig($this->fake, ['API_SYSTEM' => '1']);
    $this->get('/access-keys/arcadmin')->assertOk();
    $this->get('/access-keys/bob')->assertNotFound();
    signIn('bob', 'user');
    $this->get('/access-keys/bob')->assertNotFound();
    $this->post('/access-keys/bob', ['sets' => ['purge-nginx-cache']])->assertNotFound();
    $this->fake->assertNotRan('v-add-access-key');
});

it('lets administrators manage the keys of any user and keeps resellers to their own', function () {
    $this->fake->on('v-delete-access-key')->returns();
    fakeResellers($this->fake);

    signIn('arcadmin', 'admin');
    $this->get('/access-keys?owner=carol')->assertRedirect('/access-keys/carol');
    $this->get('/access-keys/carol')->assertOk()->assertSee(CAROL_KEY)->assertSee('Show access keys');
    $this->delete('/access-keys/carol/'.CAROL_KEY)->assertRedirect('/access-keys/carol');
    $this->fake->assertRan('v-delete-access-key', fn ($a) => $a === [CAROL_KEY]);

    signIn('hostco', 'reseller');
    $this->get('/access-keys/hostco')->assertOk()->assertDontSee('Show access keys');
    $this->get('/access-keys/bob')->assertForbidden();
    $this->get('/access-keys/carol')->assertNotFound();
});

it('offers user permission sets to users and every set to the root user', function () {
    signIn('bob', 'user');
    $this->get('/access-keys/bob/create')->assertOk()->assertSee('purge-nginx-cache')->assertSee('update-dns-records')->assertDontSee('billing');

    signIn('arcadmin', 'admin');
    $this->get('/access-keys/arcadmin/create')->assertOk()->assertSee('billing');
    $this->get('/access-keys/bob/create')->assertOk()->assertDontSee('billing');
});

it('asks for the comment first and groups the sets by API version, each with a short description', function () {
    signIn('bob', 'user');

    $this->get('/access-keys/bob/create')->assertOk()
        ->assertSeeInOrder(['Comment', 'What the key is for, for example deploy bot.',
            'REST API v2: full access', 'v2-read', 'Every read endpoint, no change.',
            'REST API v2: by area', 'v2-accounts', 'Users and packages.', 'v2-web', 'Web domains.',
            'REST API v1', 'purge-nginx-cache', 'Purge the NGINX cache of web domains.', 'update-dns-records', 'Change DNS records.', 'Save'])
        ->assertDontSee('REST API v2 only:');
});

it('creates a key and shows its secret once, without storing it', function () {
    $created = ['ACCESS_KEY_ID' => 'NeWkEyNeWkEyNeWkEy01', 'SECRET_ACCESS_KEY' => 'fresh-secret-abcdef', 'USER' => 'bob',
        'PERMISSIONS' => ['purge-nginx-cache', 'update-dns-records'], 'COMMENT' => 'ci', 'TIME' => '12:00:00', 'DATE' => '2026-10-06'];
    $this->fake->on('v-add-access-key')->returnsJson($created);
    signIn('bob', 'user');

    $this->post('/access-keys/bob', ['sets' => ['purge-nginx-cache', 'update-dns-records'], 'comment' => ' ci '])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Access key NeWkEyNeWkEyNeWkEy01 has been created successfully.')
        ->assertSee('Warning! Last chance to save secret key!')->assertSee('fresh-secret-abcdef')
        ->assertSee('Bearer token')->assertSee('NeWkEyNeWkEyNeWkEy01:fresh-secret-abcdef')
        ->assertSee('x-data="apAccessKeySecret()"', false)
        ->assertDontSee('arc whoami')->assertDontSee('/api/v2/me')
        ->assertSessionMissing('ok');
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === ['bob', 'purge-nginx-cache,update-dns-records', 'ci', 'json']);
    expect(json_encode(session()->all()))->not->toContain('fresh-secret-abcdef');
});

it('shows how to call REST API v2 with a new v2 key', function () {
    $this->fake->on('v-add-access-key')->returnsJson(['ACCESS_KEY_ID' => 'NeWkEyNeWkEyNeWkEy02', 'SECRET_ACCESS_KEY' => 'v2-secret_abc=',
        'USER' => 'bob', 'PERMISSIONS' => ['v2-read'], 'COMMENT' => '', 'TIME' => '12:00:00', 'DATE' => '2026-10-06']);
    signIn('bob', 'user');

    $this->post('/access-keys/bob', ['sets' => ['v2-read']])->assertOk()
        ->assertSee("curl -H 'Authorization: Bearer NeWkEyNeWkEyNeWkEy02:v2-secret_abc=' http://localhost/api/v2/me")
        ->assertSeeInOrder(['export ARCPANEL_ENDPOINT=http://localhost', 'export ARCPANEL_ACCESS_KEY_ID=NeWkEyNeWkEyNeWkEy02',
            "export ARCPANEL_SECRET_KEY='v2-secret_abc='", 'arc whoami']);
});

it('requires permissions and refuses sets of other roles', function () {
    signIn('bob', 'user');

    $this->post('/access-keys/bob', ['comment' => 'x'])->assertSessionHasErrors(['sets' => 'Field "Permissions" can not be blank.']);
    $this->post('/access-keys/bob', ['sets' => ['billing']])->assertSessionHasErrors(['sets' => 'API billing not allowed']);
    $this->fake->assertNotRan('v-add-access-key');
});

it('refuses a comment that v-add-access-key would refuse, next to the field', function () {
    signIn('bob', 'user');

    foreach (['x', 'deploy bot!', '-ci', str_repeat('a', 67)] as $comment) {
        $this->post('/access-keys/bob', ['sets' => ['v2-read'], 'comment' => $comment])->assertSessionHasErrors('comment');
    }
    $this->fake->assertNotRan('v-add-access-key');
});

it('deletes keys in bulk, of the owner only', function () {
    $this->fake->on('v-delete-access-key')->returns();
    signIn('bob', 'user');

    $this->post('/access-keys/bob/bulk', ['action' => 'delete', 'items' => [BOB_KEY, BOB_OLD_KEY]])
        ->assertRedirect('/access-keys/bob')->assertSessionHas('ok', '2 done, 0 failed');
    $this->post('/access-keys/bob/bulk', ['action' => 'delete', 'items' => [CAROL_KEY]])->assertNotFound();
    $this->delete('/access-keys/bob/'.CAROL_KEY)->assertNotFound();
    $this->fake->assertRan('v-delete-access-key', fn ($a) => $a === [CAROL_KEY], 0);
});

// SSH keys (§8.3)

it('lists SSH keys without the file manager key', function () {
    signIn('bob', 'user');

    $this->get('/ssh-keys')->assertRedirect('/ssh-keys/bob');
    $this->get('/ssh-keys/bob')->assertOk()
        ->assertSee('bob@laptop')->assertSee('SHA256:laptopfingerprint')->assertDontSee('filemanager.ssh.key')
        ->assertSee('1 SSH key')->assertSee('Are you sure you want to delete SSH key bob@laptop?');
    $this->get('/ssh-keys/carol')->assertNotFound();

    signIn('carol', 'user');
    $this->get('/ssh-keys/carol')->assertOk()->assertSee('No SSH keys');
});

it('adds SSH keys as web/add/key does', function () {
    $this->fake->on('v-add-user-ssh-key')->returns();
    signIn('bob', 'user');

    $this->post('/ssh-keys/bob', ['key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOk bob@desktop'])
        ->assertRedirect('/ssh-keys/bob')->assertSessionHas('ok', 'SSH Key has been created successfully.');
    $this->fake->assertRan('v-add-user-ssh-key', fn ($a) => $a === ['bob', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOk bob@desktop']);

    // without a comment the key gets an ID
    $this->post('/ssh-keys/bob', ['key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIXy']);
    $this->fake->assertRan('v-add-user-ssh-key', fn ($a) => preg_match('/^ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIXy [0-9a-f]{32}$/', $a[1]) === 1);

    $this->post('/ssh-keys/bob', ['key' => 'ssh-ed25519 AAAAOther bob@laptop'])->assertSessionHas('error', 'SSH Key already exists.');
    $this->post('/ssh-keys/bob', ['key' => ''])->assertSessionHasErrors(['key' => 'Field "SSH Key" can not be blank.']);
    $this->post('/ssh-keys/bob', ['key' => "ssh-ed25519 AAAA a\nssh-rsa BBBB b"])->assertSessionHasErrors(['key']);
});

it('deletes an SSH key and lets administrators manage any user', function () {
    $this->fake->on('v-delete-user-ssh-key')->returns();
    fakeResellers($this->fake);

    signIn('bob', 'user');
    $this->delete('/ssh-keys/bob/bob@laptop')->assertRedirect('/ssh-keys/bob')->assertSessionHas('ok', 'SSH key bob@laptop has been deleted.');
    $this->fake->assertRan('v-delete-user-ssh-key', fn ($a) => $a === ['bob', 'bob@laptop']);
    $this->delete('/ssh-keys/bob/ghost@nowhere')->assertNotFound();

    signIn('hostco', 'reseller');
    $this->get('/ssh-keys/bob')->assertForbidden();
    $this->post('/ssh-keys/bob', ['key' => 'ssh-ed25519 AAAA x'])->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->get('/ssh-keys/bob')->assertOk()->assertSee('Show SSH keys');
});

it('has a capability for every key route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()
        ->filter(fn ($name) => str_starts_with($name, 'access-keys.') || str_starts_with($name, 'ssh-keys.'));

    expect($routes)->toContain('access-keys.index', 'access-keys.list', 'access-keys.create', 'access-keys.store', 'access-keys.show',
        'access-keys.destroy', 'access-keys.bulk', 'ssh-keys.index', 'ssh-keys.list', 'ssh-keys.store', 'ssh-keys.destroy');
    foreach (['access-keys.view', 'access-keys.manage', 'ssh-keys.view', 'ssh-keys.manage'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});
