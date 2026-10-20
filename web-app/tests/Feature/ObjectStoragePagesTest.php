<?php

use App\Arc\ExitCode;

// Object storage pages, navigation and the server page (E44 spec §11).
// hostco owns bob (developer package, with object storage), resb owns carol
// (default package, without it).

beforeEach(function () {
    $this->fake = fakeArc(['OBJECT_STORAGE_SYSTEM' => 'silo']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['OBJECT_STORAGE' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['OBJECT_STORAGE' => 'no']]);
    $this->fake->on('v-list-user-bucket')->returnsExit(ExitCode::NotExist, 'Error: bucket does not exist');
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-list-user-buckets', ['bob', 'json'])->returnsJson(['bob-media' => storageBucket('bob-media', ['VERSIONING' => 'yes'])]);
    $this->fake->on('v-list-user-bucket', ['bob', 'bob-media', 'json'])->returnsJson(['bob-media' => storageBucket('bob-media', ['VERSIONING' => 'yes'])]);
    $this->fake->on('v-list-user-object-storage', ['bob', 'json'])->returnsJson(storageAccount());
    $this->fake->on('v-list-user-s3-keys', ['bob', 'json'])->returnsJson([
        'AKEXAMPLE1' => ['KEY' => 'AKEXAMPLE1', 'NAME' => 'app', 'BUCKET' => '', 'ACCESS' => 'rw', 'EXPIRES' => '', 'EXPIRED' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-19'],
    ]);
    $this->fake->on('v-list-user-buckets', ['carol', 'json'])->returnsJson([]);
    $this->fake->on('v-list-user-object-storage', ['carol', 'json'])->returnsJson(storageAccount(['ALLOWED' => 'no', 'BUCKETS' => '0', 'KEYS' => '0', 'QUOTA_USED' => '0']));
    $this->fake->on('v-list-user-s3-keys', ['carol', 'json'])->returnsJson([]);
});

it('shows the buckets, the keys, how to connect and the sidebar link', function () {
    signIn('bob', 'user');

    $this->get('/object-storage')->assertRedirect('/object-storage/bob');
    $this->get('/object-storage/bob')->assertOk()
        ->assertSee('bob-media')
        ->assertSee('AKEXAMPLE1')
        ->assertSee('https://s3.example.test')
        ->assertSee('no_check_bucket = true')
        ->assertSee('Add bucket')
        ->assertSee('href="'.route('storage').'"', false)
        ->assertDontSee('href="'.route('server.object-storage').'"', false);
});

it('hides the sidebar link without the grant and warns on the page', function () {
    signIn('carol', 'user');

    $this->get('/object-storage/carol')->assertOk()
        ->assertSee('Object storage is not included in the package of this account.')
        ->assertDontSee('Add bucket')
        ->assertDontSee('href="'.route('storage').'"', false);
});

it('scopes the page to the tree', function (string $principal, string $role, string $user, int $status) {
    signIn($principal, $role);

    $this->get("/object-storage/{$user}")->assertStatus($status);
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('answers 404 without Silo on the server and hides the link', function () {
    $this->fake = fakeArc(['OBJECT_STORAGE_SYSTEM' => '']);
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['OBJECT_STORAGE' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/object-storage/bob')->assertNotFound();
    $this->get('/dashboard')->assertDontSee('href="'.route('storage').'"', false);
});

it('creates, changes and deletes a bucket', function () {
    $this->fake->on('v-add-user-bucket')->returns();
    $this->fake->on('v-change-user-bucket')->returns();
    $this->fake->on('v-delete-user-bucket')->returns();
    signIn('hostco', 'reseller');

    $this->post('/object-storage/bob/buckets', ['name' => 'bob-new', 'quota' => '50', 'versioning' => '1'])
        ->assertRedirect('/object-storage/bob')->assertSessionHas('ok');
    $this->fake->assertRan('v-add-user-bucket', fn ($a) => $a === ['bob', 'bob-new', '50', 'yes']);
    $this->post('/object-storage/bob/buckets', ['name' => 'Bob_New', 'quota' => '50'])->assertSessionHasErrors('name');

    $this->post('/object-storage/bob/buckets/bob-media', ['quota' => '200'])->assertRedirect('/object-storage/bob');
    $this->fake->assertRan('v-change-user-bucket', fn ($a) => $a === ['bob', 'bob-media', '200', 'no']);

    $this->delete('/object-storage/bob/buckets/bob-media')->assertRedirect('/object-storage/bob');
    $this->fake->assertRan('v-delete-user-bucket', fn ($a) => $a[0] === 'bob' && $a[1] === 'bob-media');
    $this->delete('/object-storage/bob/buckets/bob-ghost')->assertNotFound();
});

it('passes the refusal of the command on', function () {
    $this->fake->on('v-add-user-bucket')->returnsExit(ExitCode::Limit, 'Error: the package allows 3 buckets (OBJECT_STORAGE_BUCKETS)');
    signIn('bob', 'user');

    $this->post('/object-storage/bob/buckets', ['name' => 'bob-more', 'quota' => '10'])
        ->assertRedirect('/object-storage/bob')->assertSessionHas('error');
});

it('shows a new key with its secret once and saves it in the secret store on request', function () {
    $this->fake->on('v-add-user-s3-key')->returnsJson(['KEY' => 'AKNEW2', 'SECRET' => 'very-secret-value', 'NAME' => 'deploy']);
    $this->fake->on('v-add-user-secret')->returns();
    signIn('bob', 'user');

    $this->post('/object-storage/bob/keys', ['name' => 'deploy', 'bucket' => 'bob-media', 'access' => 'ro', 'expires' => '2027-01-31', 'save_as' => '/s3/deploy'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('AKNEW2')
        ->assertSee('very-secret-value')
        ->assertSee('/s3/deploy');
    $this->fake->assertRan('v-add-user-s3-key', fn ($a) => $a === ['bob', 'deploy', 'bob-media', 'ro', '2027-01-31', 'json']);
    $this->fake->assertRan('v-add-user-secret', fn ($a) => $a[0] === 'bob' && $a[1] === '/s3/deploy');

    $this->post('/object-storage/bob/keys', ['name' => 'deploy', 'bucket' => 'carol-files', 'access' => 'rw'])->assertNotFound();
    $this->get('/object-storage/bob')->assertDontSee('very-secret-value');
});

it('deletes a key of the account only', function () {
    $this->fake->on('v-delete-user-s3-key')->returns();
    signIn('bob', 'user');

    $this->delete('/object-storage/bob/keys/AKEXAMPLE1')->assertRedirect('/object-storage/bob');
    $this->fake->assertRan('v-delete-user-s3-key', fn ($a) => $a === ['bob', 'AKEXAMPLE1']);
    $this->delete('/object-storage/bob/keys/AKOTHER9')->assertNotFound();
});

it('refuses changes in a read-only session', function () {
    withSysConfig($this->fake, ['OBJECT_STORAGE_SYSTEM' => 'silo', 'POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes', 'PACKAGE' => 'developer']]);
    signIn('bob', 'user');

    $this->get('/object-storage/bob')->assertOk()->assertDontSee('Add bucket');

    $this->post('/object-storage/bob/buckets', ['name' => 'bob-new', 'quota' => '50'])->assertForbidden();
    $this->fake->assertNotRan('v-add-user-bucket');
});

it('shows the service with the usage per account to administrators only', function () {
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson(['INSTALLED' => 'yes', 'VERSION' => 'RELEASE.2026-10-18T00-00-00Z', 'DATA' => '/srv/arcpanel-silo',
        'HOSTNAME' => 's3.example.test', 'ENDPOINT' => 'https://s3.example.test', 'STATE' => 'running', 'ACCOUNTS' => '1', 'BUCKETS' => '1', 'OBJECTS' => '3', 'BYTES' => '2048']);
    $this->fake->on('v-list-sys-buckets')->returnsJson(['OBJECTS' => ['bob' => ['bob-media' => storageBucket('bob-media')]]]);
    signIn('arcadmin', 'admin');

    $this->get('/server/object-storage')->assertOk()
        ->assertSee('RELEASE.2026-10-18T00-00-00Z')
        ->assertSee('/srv/arcpanel-silo')
        ->assertSee('href="'.route('storage.show', ['user' => 'bob']).'"', false);

    signIn('hostco', 'reseller');
    $this->get('/server/object-storage')->assertForbidden();
});

it('installs Silo as the system task and publishes the endpoint', function () {
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson(['INSTALLED' => 'no']);
    $this->fake->on('v-change-sys-config-value')->returns();
    $this->fake->on('v-start-sys-task')->returns();
    $this->fake->on('v-change-sys-silo-endpoint')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/object-storage')->assertOk()->assertSee('Object storage is not installed')->assertSee('Install object storage');
    $this->post('/server/object-storage/install', ['data_path' => '/srv/silo/'])->assertRedirect('/server/object-storage')->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['SILO_DATA', '/srv/silo']);
    $this->fake->assertRan('v-start-sys-task', fn ($a) => in_array('silo', $a, true));
    $this->post('/server/object-storage/install', ['data_path' => '../etc'])->assertSessionHasErrors('data_path');

    $this->post('/server/object-storage/endpoint', ['hostname' => 'S3.Example.test', 'letsencrypt' => '1'])->assertRedirect('/server/object-storage');
    $this->fake->assertRan('v-change-sys-silo-endpoint', fn ($a) => $a === ['s3.example.test', 'yes']);
    $this->post('/server/object-storage/endpoint', ['hostname' => ''])->assertRedirect('/server/object-storage');
    $this->fake->assertRan('v-change-sys-silo-endpoint', fn ($a) => $a === ['none']);
});

it('shows a running system task on the service page and holds the install until it ends', function () {
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson(['INSTALLED' => 'no']);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '17924741184798', 'KIND' => 'plugin', 'ARGS' => 'silo on', 'STATUS' => 'running',
        'EXIT' => '', 'START' => '2026-10-20 12:28:38', 'END' => '', 'LOG' => '$ v-add-sys-silo']);
    signIn('arcadmin', 'admin');

    $this->get('/server/object-storage')->assertOk()
        ->assertSee('Object storage is not installed')
        ->assertSee('x-bind:disabled="running"', false)
        ->assertSee('silo on');
});

it('names the service page Object storage in the sidebar while it is the only page', function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson(['INSTALLED' => 'no']);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    signIn('arcadmin', 'admin');

    $this->get('/server/object-storage')->assertOk()
        ->assertSee('<h1', false)
        ->assertDontSee('Object storage service');
});

it('removes Silo as the system task only when no bucket is left', function () {
    $status = fn (int $buckets) => ['INSTALLED' => 'yes', 'VERSION' => 'RELEASE.2026-10-18T00-00-00Z', 'DATA' => '/srv/arcpanel-silo', 'STATE' => 'running', 'BUCKETS' => (string) $buckets];
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson($status(1));
    $this->fake->on('v-list-sys-buckets')->returnsJson(['OBJECTS' => []]);
    $this->fake->on('v-start-sys-task')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/object-storage')->assertOk()->assertSee('Removing needs every bucket deleted first.');
    $this->delete('/server/object-storage')->assertRedirect('/server/object-storage')->assertSessionHas('error');
    $this->fake->assertNotRan('v-start-sys-task');

    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson($status(0));
    $this->delete('/server/object-storage')->assertRedirect('/server/object-storage')->assertSessionHas('ok');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => in_array('silo', $a, true) && in_array('off', $a, true));

    signIn('hostco', 'reseller');
    $this->delete('/server/object-storage')->assertForbidden();
});
