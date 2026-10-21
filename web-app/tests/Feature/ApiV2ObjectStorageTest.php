<?php

use App\Arc\ExitCode;

// REST API v2, object storage on Silo (E44 spec §10).

beforeEach(function () {
    $this->fake = fakeApi(['OBJECT_STORAGE_SYSTEM' => 'silo']);
    fakeResellers($this->fake);
    $this->fake->on('v-list-user-bucket')->returnsExit(ExitCode::NotExist, 'Error: bucket does not exist');
    foreach (['bob' => 'bob-media', 'carol' => 'carol-files'] as $user => $bucket) {
        $this->fake->on('v-list-user-buckets', [$user, 'json'])->returnsJson([$bucket => storageBucket($bucket)]);
        $this->fake->on('v-list-user-bucket', [$user, $bucket, 'json'])->returnsJson([$bucket => storageBucket($bucket)]);
        $this->fake->on('v-list-user-object-storage', [$user, 'json'])->returnsJson(storageAccount());
        $this->fake->on('v-list-user-s3-keys', [$user, 'json'])->returnsJson([
            'AKEXAMPLE1' => ['KEY' => 'AKEXAMPLE1', 'NAME' => 'app', 'BUCKET' => '', 'ACCESS' => 'rw', 'EXPIRES' => '', 'EXPIRED' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-19'],
        ]);
    }
});

it('lists buckets inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/buckets", apiAs($this->fake, $principal, $role, readOnly: true, area: 'storage'))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('data.0.used_bytes', 2048)->assertJsonPath('data.0.full', false)
            ->assertJsonPath('meta.storage.quota_limit_mb', 1024)
            ->assertJsonPath('meta.storage.endpoint', 'https://s3.example.test')
            ->assertJsonPath('meta.storage.path_style', true);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('answers 404 on every account route without Silo on the server', function () {
    $this->fake = fakeApi(['OBJECT_STORAGE_SYSTEM' => '']);
    fakeResellers($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'storage');

    $this->getJson('/api/v2/users/bob/buckets', $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'bob-new', 'quota_mb' => 10], $headers)->assertNotFound();
    $this->getJson('/api/v2/users/bob/s3-keys', $headers)->assertNotFound();
    $this->getJson('/api/v2/buckets', apiAs($this->fake, 'arcadmin', 'admin', readOnly: true))->assertNotFound();
});

it('creates a bucket with a key that holds only v2-storage', function () {
    $this->fake->on('v-add-user-bucket')->returns();
    $this->fake->on('v-list-user-bucket', ['bob', 'bob-new', 'json'])->returnsJson(['bob-new' => storageBucket('bob-new', ['QUOTA' => '50', 'VERSIONING' => 'yes', 'USED' => '0', 'OBJECTS' => '0'])]);

    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'bob-new', 'quota_mb' => 50, 'versioning' => true], apiAs($this->fake, 'bob', 'user', area: 'storage'))
        ->assertCreated()
        ->assertHeader('Location', url('/api/v2/users/bob/buckets/bob-new'))
        ->assertJsonPath('data.name', 'bob-new')
        ->assertJsonPath('data.quota_mb', 50)
        ->assertJsonPath('data.versioning', true);
    $this->fake->assertRan('v-add-user-bucket', fn ($a) => $a === ['bob', 'bob-new', '50', 'yes']);
});

it('answers 409 with existing for its own bucket and passes the refusals of the command on', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'storage');
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'bob-media', 'quota_mb' => 10], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing', '/api/v2/users/bob/buckets/bob-media');

    $this->fake->on('v-add-user-bucket')->returnsExit(ExitCode::Exists, 'Error: the bucket name taken-name is taken');
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'taken-name', 'quota_mb' => 10], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->on('v-add-user-bucket')->returnsExit(ExitCode::Limit, 'Error: the package allows 3 buckets (OBJECT_STORAGE_BUCKETS)');
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'bob-four', 'quota_mb' => 10], $headers)->assertStatus(422)
        ->assertJsonPath('error.code', 'E_LIMIT');
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'Bad_Name', 'quota_mb' => 10], $headers)->assertStatus(422);
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'bob-zero', 'quota_mb' => 0], $headers)->assertStatus(422);
});

it('plans without running and refuses a v2-read key before any command', function () {
    $this->postJson('/api/v2/users/bob/buckets?dry_run=1', ['name' => 'bob-new', 'quota_mb' => 10], apiAs($this->fake, 'bob', 'user', area: 'storage'))->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Add bucket bob-new', 'command' => 'v-add-user-bucket', 'args' => ['bob', 'bob-new', '10', 'no']]);
    $this->fake->assertNotRan('v-add-user-bucket');

    $read = apiAs($this->fake, 'bob', 'user', readOnly: true);
    $this->postJson('/api/v2/users/bob/buckets', ['name' => 'bob-new', 'quota_mb' => 10], $read)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/buckets/bob-media', [], $read)->assertForbidden();
    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'k'], $read)->assertForbidden();
    $this->fake->assertNotRan('v-add-user-bucket');
    $this->fake->assertNotRan('v-delete-user-bucket');
    $this->fake->assertNotRan('v-add-user-s3-key');
});

it('changes a bucket only when something changes and deletes it', function () {
    $this->fake->on('v-change-user-bucket')->returns();
    $this->fake->on('v-delete-user-bucket')->returns();
    $headers = apiAs($this->fake, 'hostco', 'reseller', area: 'storage');

    $this->patchJson('/api/v2/users/bob/buckets/bob-media', ['quota_mb' => 100], $headers)->assertOk();
    $this->fake->assertNotRan('v-change-user-bucket');
    $this->patchJson('/api/v2/users/bob/buckets/bob-media', ['quota_mb' => 200], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-bucket', fn ($a) => $a === ['bob', 'bob-media', '200', 'no']);
    $this->patchJson('/api/v2/users/bob/buckets/bob-media', ['versioning' => true], $headers)->assertOk();
    $this->fake->assertRan('v-change-user-bucket', fn ($a) => $a === ['bob', 'bob-media', '100', 'yes']);
    $this->patchJson('/api/v2/users/bob/buckets/ghost-bucket', ['quota_mb' => 5], $headers)->assertNotFound();

    $this->deleteJson('/api/v2/users/bob/buckets/bob-media', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-bucket', fn ($a) => $a === ['bob', 'bob-media']);
    $this->deleteJson('/api/v2/users/carol/buckets/carol-files', [], $headers)->assertNotFound();
    $this->fake->assertRan('v-delete-user-bucket', null, 1);
});

it('creates an S3 key whose secret is in the answer once and can be saved in the secret store', function () {
    $this->fake->on('v-add-user-s3-key')->returnsJson(['KEY' => 'AKNEW2', 'SECRET' => 'very-secret-value', 'NAME' => 'deploy']);
    $this->fake->on('v-add-user-secret')->returns();
    $this->fake->on('v-list-user-s3-keys', ['bob', 'json'])->returnsJson([
        'AKNEW2' => ['KEY' => 'AKNEW2', 'NAME' => 'deploy', 'BUCKET' => 'bob-media', 'ACCESS' => 'ro', 'EXPIRES' => '2027-01-31', 'EXPIRED' => 'no', 'TIME' => '11:00:00', 'DATE' => '2026-10-19'],
    ]);

    // Saving the secret writes to the secret store, which needs v2-secrets
    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'deploy', 'bucket' => 'bob-media', 'access' => 'ro', 'expires_on' => '2027-01-31', 'save_as' => '/s3/deploy'],
        apiKey($this->fake, 'bob', 'user', ['v2-storage', 'v2-secrets']))
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.id', 'AKNEW2')
        ->assertJsonPath('data.secret', 'very-secret-value')
        ->assertJsonPath('data.bucket', 'bob-media')
        ->assertJsonPath('data.access', 'ro')
        ->assertJsonPath('data.expires_on', '2027-01-31')
        ->assertJsonPath('data.saved_as', '/s3/deploy');
    $this->fake->assertRan('v-add-user-s3-key', fn ($a) => $a === ['bob', 'deploy', 'bob-media', 'ro', '2027-01-31', 'json']);
    $this->fake->assertRan('v-add-user-secret', fn ($a) => $a[0] === 'bob' && $a[1] === '/s3/deploy' && $a[3] === 'S3 key AKNEW2');

    // Listing never shows a secret
    $this->getJson('/api/v2/users/bob/s3-keys', apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'storage'))->assertOk()
        ->assertJsonMissingPath('data.0.secret')
        ->assertDontSee('very-secret-value');
});

it('keeps the secret store out of v2-storage: save_as needs v2-secrets', function () {
    expect(apiSetCommands('v2-storage'))->not->toContain('v-add-user-secret')->not->toContain('v-list-user-secret-store');
    $this->fake->on('v-add-user-s3-key')->returnsJson(['KEY' => 'AKNEW2', 'SECRET' => 'very-secret-value', 'NAME' => 'deploy']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'storage');

    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'deploy', 'save_as' => '/s3/deploy'], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-add-user-s3-key');
    $this->fake->assertNotRan('v-add-user-secret');
    $this->getJson('/api/v2/users/bob/secrets', $headers)->assertForbidden();

    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'deploy'], $headers)->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.secret', 'very-secret-value');
});

it('refuses a key for a bucket of another account and limits from the command', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'storage');
    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'k', 'bucket' => 'carol-files'], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'k', 'expires_on' => '2020-01-01'], $headers)->assertStatus(422);
    $this->fake->on('v-add-user-s3-key')->returnsExit(ExitCode::Limit, 'Error: an account holds at most 20 S3 keys');
    $this->postJson('/api/v2/users/bob/s3-keys', ['name' => 'k'], $headers)->assertStatus(422)->assertJsonPath('error.code', 'E_LIMIT');
});

it('deletes an S3 key of the account and answers 404 for another one', function () {
    $this->fake->on('v-delete-user-s3-key')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'storage');
    $this->deleteJson('/api/v2/users/bob/s3-keys/AKEXAMPLE1', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-user-s3-key', fn ($a) => $a === ['bob', 'AKEXAMPLE1']);
    $this->deleteJson('/api/v2/users/bob/s3-keys/AKGHOST99', [], $headers)->assertNotFound();
});

it('lists every account\'s buckets for administrators and resellers only', function () {
    $this->fake->on('v-list-sys-buckets')->returnsJson([
        'AS_OF' => '1792231200', 'USERS' => ['bob', 'carol'], 'CHANGED_USERS' => ['bob', 'carol'],
        'OBJECTS' => ['bob' => ['bob-media' => storageBucket('bob-media')], 'carol' => ['carol-files' => storageBucket('carol-files')]],
    ]);

    $this->getJson('/api/v2/buckets', apiAs($this->fake, 'arcadmin', 'admin', readOnly: true, area: 'storage'))->assertOk()
        ->assertJsonPath('data.*.name', ['bob-media', 'carol-files'])
        ->assertJsonPath('data.1.user', 'carol');
    $this->getJson('/api/v2/buckets', apiAs($this->fake, 'hostco', 'reseller', readOnly: true, area: 'storage'))->assertOk()
        ->assertJsonPath('data.*.name', ['bob-media']);
    $this->getJson('/api/v2/buckets', apiAs($this->fake, 'bob', 'user', readOnly: true, area: 'storage'))->assertForbidden();
});

it('shows and installs the service for administrators only', function () {
    $this->fake = fakeApi(['OBJECT_STORAGE_SYSTEM' => '']);
    fakeResellers($this->fake);
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson(['INSTALLED' => 'no', 'VERSION' => '', 'DATA' => '', 'HOSTNAME' => '', 'ENDPOINT' => '',
        'STATE' => 'stopped', 'REGION' => 'us-east-1', 'ACCOUNTS' => 0, 'BUCKETS' => 0, 'OBJECTS' => 0, 'BYTES' => 0]);
    $this->fake->on('v-change-sys-config-value')->returns();
    $this->fake->on('v-start-sys-task')->returns("20261019120000\n");
    $admin = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/object-storage', $admin)->assertOk()->assertJsonPath('data.installed', false);
    $this->getJson('/api/v2/system/object-storage', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertForbidden();
    $admin = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/object-storage?dry_run=1', ['data_path' => '/srv/s3'], $admin)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-change-sys-config-value')
        ->assertJsonPath('data.steps.1.args', ['plugin', 'silo', 'on']);
    $this->fake->assertNotRan('v-start-sys-task');

    $this->postJson('/api/v2/system/object-storage', ['data_path' => '/srv/s3/'], $admin)->assertStatus(202)
        ->assertDocumentedHeaders('installObjectStorage')
        ->assertHeader('Link', '</api/v2/tasks/sys:20261019120000>; rel="monitor"');
    $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === ['SILO_DATA', '/srv/s3']);
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['plugin', 'silo', 'on']);
    $this->postJson('/api/v2/system/object-storage', ['data_path' => '/srv/../etc'], $admin)->assertStatus(422);
});

it('sets and removes the endpoint', function () {
    $this->fake->on('v-change-sys-silo-endpoint')->returns();
    $this->fake->on('v-list-sys-silo', ['json'])->returnsJson(['INSTALLED' => 'yes', 'VERSION' => '20261018000000.0.0-1PGSTY', 'DATA' => '/srv/arcpanel-silo',
        'HOSTNAME' => 's3.example.test', 'ENDPOINT' => 'https://s3.example.test', 'STATE' => 'running', 'REGION' => 'us-east-1',
        'ACCOUNTS' => 2, 'BUCKETS' => 2, 'OBJECTS' => 6, 'BYTES' => 4096]);
    $admin = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->putJson('/api/v2/system/object-storage/endpoint', ['hostname' => 'S3.Example.test'], $admin)->assertOk()
        ->assertJsonPath('data.endpoint', 'https://s3.example.test')
        ->assertJsonPath('data.buckets', 2);
    $this->fake->assertRan('v-change-sys-silo-endpoint', fn ($a) => $a === ['s3.example.test', 'yes']);
    $this->putJson('/api/v2/system/object-storage/endpoint', ['hostname' => 's3.example.test', 'letsencrypt' => false], $admin)->assertOk();
    $this->fake->assertRan('v-change-sys-silo-endpoint', fn ($a) => $a === ['s3.example.test', 'no']);
    $this->putJson('/api/v2/system/object-storage/endpoint', ['hostname' => null], $admin)->assertOk();
    $this->fake->assertRan('v-change-sys-silo-endpoint', fn ($a) => $a === ['none']);
    $this->putJson('/api/v2/system/object-storage/endpoint', ['hostname' => 'not a host'], $admin)->assertStatus(422);
});
