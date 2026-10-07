<?php

use App\Arc\ExitCode;
use App\Services\Backups\BackupPaths;

// REST API v2 backups (E34 batch 3 spec §6.1). Snapshots and exclusions are M4.

const API_BK_ARCHIVE = 'bob.2026-10-03_05-10-09.tar';
const API_BK_OLD = 'bob.2026-10-01_05-10-11.tar';

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['bob' => ['BACKUPS' => '3', 'BACKUPS_INCREMENTAL' => 'yes']]);
    fakeBackupData($this->fake);
});

it('lists backups inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/backups", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('meta.limit', 3)
            ->assertJsonPath('meta.incremental', true)
            ->assertJsonPath('data.0.name', API_BK_ARCHIVE);
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

it('describes one archive and refuses a foreign or invalid name', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE, $headers)->assertOk()
        ->assertJsonPath('data.name', API_BK_ARCHIVE)
        ->assertJsonPath('data.type', 'tar')
        ->assertJsonPath('data.content.web', ['example.com']);
    $this->getJson('/api/v2/users/bob/backups/carol.2026-10-03_05-10-09.tar', $headers)->assertNotFound();
    $this->getJson('/api/v2/users/bob/backups/bob..secret.tar', $headers)->assertNotFound();
});

it('creates a backup with a v2-backup key and follows it with Link', function () {
    $this->fake->on('v-schedule-user-backup', ['bob', 'json'])->returnsJson(['ID' => '9001']);

    $this->postJson('/api/v2/users/bob/backups', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:9001>; rel="monitor"')
        ->assertJsonPath('data.id', 'queue:bob:9001')
        ->assertJsonPath('data.status', 'queued');
    $this->fake->assertRan('v-schedule-user-backup', fn ($a) => $a === ['bob', 'json']);
});

it('answers 409 with the queued task when a backup is already scheduled', function () {
    $this->fake->on('v-list-user-tasks', ['bob', 'json'])->returnsJson([
        '8001' => ['ID' => '8001', 'KIND' => 'backup', 'ARGS' => '', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
    ]);
    $this->fake->on('v-schedule-user-backup', ['bob', 'json'])->returnsExit(ExitCode::Exists, 'Error: backup is already scheduled');

    $this->postJson('/api/v2/users/bob/backups', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing.id', 'queue:bob:8001')
        ->assertJsonPath('error.existing.status', 'queued');
    $this->fake->assertRan('v-schedule-user-backup');
});

it('refuses writes to a read-only key but allows download', function () {
    $dir = sys_get_temp_dir().'/arc-api-backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/'.API_BK_ARCHIVE);
    config(['arc.backup_dir' => $dir]);
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/backups', [], $readHeaders)->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-schedule-user-backup');
    $this->deleteJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE, [], $readHeaders)->assertForbidden();
    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', [], $readHeaders)->assertForbidden();

    $this->get('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/download', $readHeaders)->assertOk()
        ->assertHeader('X-Accel-Redirect', BackupPaths::accelRedirect(API_BK_ARCHIVE));

    unlink($dir.'/'.API_BK_ARCHIVE);
    rmdir($dir);
});

it('plans a create without running it and refuses a v2-read key before any command', function () {
    $this->postJson('/api/v2/users/bob/backups?dry_run=1', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-schedule-user-backup');
    $this->fake->assertNotRan('v-schedule-user-backup');

    $this->postJson('/api/v2/users/bob/backups', [], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-schedule-user-backup');
    $this->fake->assertNotRan('v-schedule-user-backup');
});

it('restores everything or a selection and validates unknown objects', function () {
    $this->fake->on('v-schedule-user-restore')->returnsJson(['ID' => '9002']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:9002>; rel="monitor"')
        ->assertJsonPath('data.kind', 'restore');
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', API_BK_ARCHIVE, 'json']);

    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', ['web' => ['example.com']], $headers)->assertStatus(202);
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', API_BK_ARCHIVE, 'example.com', 'no', 'no', 'no', 'no', 'no', 'json']);

    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', ['web' => ['missing.example']], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['web']]]);
});

it('deletes an archive at once', function () {
    $this->fake->on('v-delete-user-backup')->returns();
    apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->deleteJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE, [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertNoContent();
    $this->fake->assertRan('v-delete-user-backup', fn ($a) => $a === ['bob', API_BK_ARCHIVE]);
});

it('serves a local archive and schedules a remote one with Link', function () {
    $dir = sys_get_temp_dir().'/arc-api-backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/'.API_BK_ARCHIVE);
    config(['arc.backup_dir' => $dir]);
    $this->fake->on('v-schedule-user-backup-download', ['bob', API_BK_OLD, 'json'])->returnsJson(['ID' => '9003']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->get('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/download', $headers)->assertOk()
        ->assertHeader('X-Accel-Redirect', BackupPaths::accelRedirect(API_BK_ARCHIVE));

    $this->getJson('/api/v2/users/bob/backups/'.API_BK_OLD.'/download', $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:9003>; rel="monitor"');
    $this->fake->assertRan('v-schedule-user-backup-download', fn ($a) => $a === ['bob', API_BK_OLD, 'json']);

    unlink($dir.'/'.API_BK_ARCHIVE);
    rmdir($dir);
});
