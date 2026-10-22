<?php

use App\Arc\ExitCode;

// REST API v2 backups (E34 batch 3 spec §6.1–§6.3).

const API_BK_ARCHIVE = 'bob.2026-10-03_05-10-09.tar';
const API_BK_OLD = 'bob.2026-10-01_05-10-11.tar';
const API_BK_SNAPSHOT = '4f2a91c0';

/** BACKUP of arcpanel.conf, and the folder nginx serves at /backup/ (D35). */
function apiBackupFolder($fake, string $dir, ?string $served = null): void
{
    withSysConfig($fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'BACKUP' => $dir]);
    config(['arc.backup_dir' => $served ?? $dir]);
}

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
    $this->fake->on('v-schedule-user-backup', ['bob', 'json'])->returnsJson(['ID' => '17290000009001']);

    $this->postJson('/api/v2/users/bob/backups', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertStatus(202)->assertDocumentedHeaders('createBackup')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009001>; rel="monitor"')
        ->assertJsonPath('data.id', 'queue:bob:17290000009001')
        ->assertJsonPath('data.status', 'queued');
    $this->fake->assertRan('v-schedule-user-backup', fn ($a) => $a === ['bob', 'json']);
});

it('creates a backup with an archive job that covers the user, and only with such a job (E48)', function () {
    $this->fake->on('v-schedule-user-backup')->returnsJson(['ID' => '17290000009002']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->postJson('/api/v2/users/bob/backups', ['job' => '179267621466'], $headers)->assertStatus(202)->assertJsonPath('data.id', 'queue:bob:17290000009002');
    $this->fake->assertRan('v-schedule-user-backup', fn ($a) => $a === ['bob', 'json', '179267621466']);
    // The snapshot job, an unknown job, a bad value
    $this->postJson('/api/v2/users/bob/backups', ['job' => '179267621499'], $headers)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['job']]]);
    $this->postJson('/api/v2/users/bob/backups', ['job' => '179200000000'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/backups', ['job' => 'x; rm'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/backups?dry_run=1', ['job' => '179267621466'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'json', '179267621466']);
    $this->fake->assertRan('v-schedule-user-backup', null, 1);

    // A paused job backs up on request too, as Run now does
    $this->fake->on('v-list-backup-jobs', ['json'])->returnsJson(['179267621466' => ['ID' => '179267621466', 'NAME' => 'Nightly archives', 'ENGINE' => 'archive',
        'SCOPE' => 'all', 'SCHEDULE' => 'daily:05:10', 'TARGETS' => 'local:-1:-1:-1:-1:-1', 'STATE' => 'paused']]);
    $this->postJson('/api/v2/users/bob/backups', ['job' => '179267621466'], $headers)->assertStatus(202);
    $this->fake->assertRan('v-schedule-user-backup', null, 2);
});

it('names the job and the destinations of an archive (E48)', function () {
    $archive = [API_BK_ARCHIVE => ['TYPE' => 'local', 'SIZE' => '2', 'WEB' => 'example.com', 'DNS' => '', 'MAIL' => '',
        'DB' => '', 'CRON' => '', 'UDIR' => '', 'RUNTIME' => '1', 'TIME' => '05:10:09', 'DATE' => '2026-10-03', 'JOB' => '179267621466',
        'DESTINATIONS' => 'local,offsite', 'ENCRYPTED' => 'offsite']];
    $this->fake->on('v-list-user-backups')->returnsJson($archive);
    $this->fake->on('v-list-user-backup')->returnsJson($archive);
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE, $headers)->assertOk()
        ->assertJsonPath('data.job', '179267621466')->assertJsonPath('data.destinations', ['local', 'offsite'])->assertJsonPath('data.encrypted', true);
    $this->getJson('/api/v2/users/bob/backups', $headers)->assertOk()->assertJsonPath('data.0.destinations', ['local', 'offsite']);
    // Before E48 the type of the backup host stands for the destination
    fakeBackupData($this->fake);
    $this->getJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE, $headers)->assertOk()
        ->assertJsonPath('data.job', null)->assertJsonPath('data.destinations', ['local'])->assertJsonPath('data.encrypted', false);
});

it('answers 409 with the queued task when a backup is already scheduled', function () {
    $this->fake->on('v-list-user-tasks', ['bob', 'json'])->returnsJson([
        '17290000008001' => ['ID' => '17290000008001', 'KIND' => 'backup', 'ARGS' => '', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
    ]);
    $this->fake->on('v-schedule-user-backup', ['bob', 'json'])->returnsExit(ExitCode::Exists, 'Error: backup is already scheduled');

    $this->postJson('/api/v2/users/bob/backups', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing.id', 'queue:bob:17290000008001')
        ->assertJsonPath('error.existing.status', 'queued');
    $this->fake->assertRan('v-schedule-user-backup');
});

it('answers 409 with the newest running or queued task that blocks the request', function () {
    // is_backup_scheduled also refuses while a backup runs, and for snapshot,
    // download and file-restore lines of the user
    $this->fake->on('v-list-user-tasks', ['bob', 'json'])->returnsJson([
        '17290000207003' => ['ID' => '17290000207003', 'KIND' => 'file-restore', 'ARGS' => API_BK_SNAPSHOT.' tmp/a.txt', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:30:00', 'START' => '', 'END' => '', 'LOG' => ''],
        '17290000107002' => ['ID' => '17290000107002', 'KIND' => 'snapshot', 'ARGS' => '', 'STATUS' => 'running', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:20:00', 'START' => '2026-10-08 09:20:05', 'END' => '', 'LOG' => ''],
        '17290000007001' => ['ID' => '17290000007001', 'KIND' => 'backup', 'ARGS' => '', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:10:00', 'START' => '', 'END' => '', 'LOG' => ''],
        '17290000307004' => ['ID' => '17290000307004', 'KIND' => 'backup', 'ARGS' => '', 'STATUS' => 'failed', 'EXIT' => '1', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:40:00', 'START' => '', 'END' => '', 'LOG' => ''],
    ]);
    $this->fake->on('v-schedule-user-backup', ['bob', 'json'])->returnsExit(ExitCode::Exists, 'Error: backup is already scheduled');
    $this->fake->on('v-schedule-user-restore')->returnsExit(ExitCode::Exists, 'Error: restore is already scheduled');
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->postJson('/api/v2/users/bob/backups', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.existing.id', 'queue:bob:17290000107002')
        ->assertJsonPath('error.existing.status', 'running');
    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.existing.id', 'queue:bob:17290000207003')
        ->assertJsonPath('error.existing.kind', 'file-restore');
});

it('refuses writes to a read-only key but allows download', function () {
    $dir = sys_get_temp_dir().'/arc-api-backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/'.API_BK_ARCHIVE);
    apiBackupFolder($this->fake, $dir);
    $readHeaders = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/backups', [], $readHeaders)->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-schedule-user-backup');
    $this->deleteJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE, [], $readHeaders)->assertForbidden();
    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', [], $readHeaders)->assertForbidden();

    $this->get('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/download', $readHeaders)->assertOk()
        ->assertHeader('X-Accel-Redirect', '/backup/'.API_BK_ARCHIVE);

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
    $this->fake->on('v-schedule-user-restore')->returnsJson(['ID' => '17290000009002']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', [], $headers)->assertStatus(202)->assertDocumentedHeaders('restoreBackup')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009002>; rel="monitor"')
        ->assertJsonPath('data.kind', 'restore');
    // FORMAT is the ninth argument: a full restore passes the six types as '' (everything)
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', API_BK_ARCHIVE, '', '', '', '', '', '', 'json'], times: 1);

    $this->postJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/restore', ['web' => ['example.com']], $headers)->assertStatus(202)->assertDocumentedHeaders('restoreBackup');
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
    apiBackupFolder($this->fake, $dir);
    $this->fake->on('v-schedule-user-backup-download', ['bob', API_BK_OLD, 'json'])->returnsJson(['ID' => '17290000009003']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->get('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/download', $headers)->assertOk()
        ->assertHeader('X-Accel-Redirect', '/backup/'.API_BK_ARCHIVE);

    $this->getJson('/api/v2/users/bob/backups/'.API_BK_OLD.'/download', $headers)->assertStatus(202)->assertDocumentedHeaders('downloadBackup')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009003>; rel="monitor"');
    $this->fake->assertRan('v-schedule-user-backup-download', fn ($a) => $a === ['bob', API_BK_OLD, 'json']);

    unlink($dir.'/'.API_BK_ARCHIVE);
    rmdir($dir);
});

it('looks for archives in the BACKUP folder and refuses one that nginx does not serve', function () {
    $dir = sys_get_temp_dir().'/arc-api-backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/'.API_BK_ARCHIVE);
    // nginx serves /backup/ only; ARC_BACKUP_DIR alone does not move the archives
    apiBackupFolder($this->fake, $dir, served: '/backup');
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->getJson('/api/v2/users/bob/backups/'.API_BK_ARCHIVE.'/download', $headers)
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'E_SERVER')
        ->assertHeaderMissing('X-Accel-Redirect');
    $this->fake->assertNotRan('v-schedule-user-backup-download');

    unlink($dir.'/'.API_BK_ARCHIVE);
    rmdir($dir);
});

it('lists snapshots inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/snapshots", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('data.0.short_id', API_BK_SNAPSHOT);
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('answers 404 on every snapshot path without incremental backups', function () {
    $headers = apiAs($this->fake, 'carol', 'user', readOnly: true);
    $this->getJson('/api/v2/users/carol/snapshots', $headers)->assertNotFound();
    $this->getJson('/api/v2/users/carol/snapshots/'.API_BK_SNAPSHOT, $headers)->assertNotFound();
    $this->getJson('/api/v2/users/carol/snapshots/'.API_BK_SNAPSHOT.'/files', $headers)->assertNotFound();
    $this->postJson('/api/v2/users/carol/snapshots', [], apiAs($this->fake, 'carol', 'user', area: 'backup'))->assertNotFound();
});

it('describes one snapshot and refuses an unknown or invalid id', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT, $headers)->assertOk()
        ->assertJsonPath('data.short_id', API_BK_SNAPSHOT)
        ->assertJsonPath('data.content.web', ['example.com'])
        ->assertJsonPath('data.content.db', ['bob_wp'])
        ->assertJsonPath('data.content.cron', true);
    $this->getJson('/api/v2/users/bob/snapshots/deadbeef', $headers)->assertNotFound();
    $this->getJson('/api/v2/users/bob/snapshots/not-hex-id!', $headers)->assertNotFound();
});

it('answers 409 with the queued task when a snapshot backup is already scheduled', function () {
    $this->fake->on('v-list-user-tasks', ['bob', 'json'])->returnsJson([
        '17290000008001' => ['ID' => '17290000008001', 'KIND' => 'snapshot', 'ARGS' => '', 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
    ]);
    $this->fake->on('v-schedule-user-backup-restic', ['bob', 'json'])->returnsExit(ExitCode::Exists, 'Error: backup is already scheduled');

    $this->postJson('/api/v2/users/bob/snapshots', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing.id', 'queue:bob:17290000008001')
        ->assertJsonPath('error.existing.status', 'queued');
    $this->fake->assertRan('v-schedule-user-backup-restic');
});

it('answers 409 with the queued task when a snapshot restore is already scheduled', function () {
    $this->fake->on('v-list-user-tasks', ['bob', 'json'])->returnsJson([
        '17290000008002' => ['ID' => '17290000008002', 'KIND' => 'snapshot-restore', 'ARGS' => API_BK_SNAPSHOT, 'STATUS' => 'queued', 'EXIT' => '', 'ERROR' => '',
            'QUEUED' => '2026-10-08 09:00:00', 'START' => '', 'END' => '', 'LOG' => ''],
    ]);
    $this->fake->on('v-schedule-user-restore-restic')->returnsExit(ExitCode::Exists, 'Error: restore is already scheduled');

    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing.id', 'queue:bob:17290000008002')
        ->assertJsonPath('error.existing.status', 'queued');
    $this->fake->assertRan('v-schedule-user-restore-restic');
});

it('creates a snapshot with Link and lists snapshot files as read-only entries', function () {
    $this->fake->on('v-schedule-user-backup-restic', ['bob', 'json'])->returnsJson(['ID' => '17290000009010']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->postJson('/api/v2/users/bob/snapshots', [], $headers)->assertStatus(202)->assertDocumentedHeaders('createSnapshot')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009010>; rel="monitor"')
        ->assertJsonPath('data.kind', 'snapshot');
    $this->fake->assertRan('v-schedule-user-backup-restic', fn ($a) => $a === ['bob', 'json']);

    $files = $this->getJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/files', apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertOk()
        ->json('data');
    $note = collect($files)->firstWhere('name', 'note.txt');
    expect($note)->not->toBeNull()
        ->and($note['path'])->toBe('note.txt')
        ->and($note['writable'])->toBeFalse()
        ->and($note['type'])->toBe('file');
});

it('restores a snapshot, one object, or one path and validates bad paths', function () {
    $this->fake->on('v-schedule-user-restore-restic')->returnsJson(['ID' => '17290000009011']);
    $this->fake->on('v-schedule-user-restore-file-restic')->returnsJson(['ID' => '17290000009012']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', [], $headers)->assertStatus(202)->assertDocumentedHeaders('restoreSnapshot')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009011>; rel="monitor"');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', API_BK_SNAPSHOT, '', '', 'json']);

    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', ['type' => 'db', 'object' => 'bob_wp'], $headers)->assertStatus(202)->assertDocumentedHeaders('restoreSnapshot');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', API_BK_SNAPSHOT, 'db', 'bob_wp', 'json']);

    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', ['path' => 'tmp/note.txt'], $headers)->assertStatus(202)->assertDocumentedHeaders('restoreSnapshot')
        ->assertHeader('Link', '</api/v2/tasks/queue:bob:17290000009012>; rel="monitor"');
    $this->fake->assertRan('v-schedule-user-restore-file-restic', fn ($a) => $a === ['bob', API_BK_SNAPSHOT, '/home/bob/tmp/note.txt', 'json']);

    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', ['path' => ''], $headers)->assertUnprocessable();
    $this->fake->on('v-schedule-user-restore-file-restic')->returnsExit(ExitCode::Invalid, 'Error: invalid path format');
    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', ['path' => 'tmp/a b.txt'], $headers)->assertUnprocessable();
});

it('deletes a snapshot at once', function () {
    $this->fake->on('v-delete-user-backup-restic')->returns();
    $this->deleteJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT, [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertNoContent();
    $this->fake->assertRan('v-delete-user-backup-restic', fn ($a) => $a === ['bob', API_BK_SNAPSHOT]);
});

it('reads and updates backup exclusions', function () {
    $this->fake->on('v-update-user-backup-exclusions')->returns();
    $read = apiAs($this->fake, 'bob', 'user', readOnly: true);
    $write = apiAs($this->fake, 'bob', 'user', area: 'backup');

    $this->getJson('/api/v2/users/bob/backup-exclusions', $read)->assertOk()
        ->assertJsonPath('data.web', ['example.com:public_html/cache'])
        ->assertJsonPath('data.user', ['tmp', 'apps/kuma']);

    $this->putJson('/api/v2/users/bob/backup-exclusions', ['web' => ['other.example']], $write)->assertOk()
        ->assertJsonPath('data.web', ['other.example'])
        ->assertJsonPath('data.dns', ['example.com']);
    $this->fake->assertRan('v-update-user-backup-exclusions');
});

it('refuses snapshot and exclusion writes to a read-only key', function () {
    $read = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->postJson('/api/v2/users/bob/snapshots', [], $read)->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-schedule-user-backup-restic');
    $this->postJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT.'/restore', [], $read)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/snapshots/'.API_BK_SNAPSHOT, [], $read)->assertForbidden();
    $this->putJson('/api/v2/users/bob/backup-exclusions', ['web' => []], $read)->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-update-user-backup-exclusions');
});

it('plans snapshot writes without running them and refuses v2-read before changes', function () {
    $this->postJson('/api/v2/users/bob/snapshots?dry_run=1', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-schedule-user-backup-restic');
    $this->fake->assertNotRan('v-schedule-user-backup-restic');

    $this->postJson('/api/v2/users/bob/snapshots', [], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden();
    $this->fake->assertNotRan('v-schedule-user-backup-restic');
});
