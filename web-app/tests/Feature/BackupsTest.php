<?php

use App\Arc\ArcCommandFake;
use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

const BK_SNAPSHOT = '4f2a91c0';
const BK_ARCHIVE = 'bob.2026-10-03_05-10-09.tar';

/**
 * Backups of bob (E7): the two archives of the fixture, one restic snapshot
 * with a web domain, a database and cron jobs, and a snapshot folder with a
 * file and a folder. carol has no backups.
 */
function fakeBackupData(ArcCommandFake $fake): void
{
    $archives = fn (string $user) => $user === 'bob' ? commandFixture('v-list-user-backups/bob.json') : [];
    $fake->on('v-list-user-backups')->using(fn (array $a) => [0, (string) json_encode((object) $archives($a[0]))]);
    $fake->on('v-list-user-backup')->using(function (array $a) use ($archives) {
        $row = $archives($a[0])[$a[1]] ?? null;

        return $row !== null ? [0, (string) json_encode([$a[1] => $row])] : [3, "Error: backup {$a[1]} doesn't exist"];
    });
    $fake->on('v-list-user-backups-restic')->using(fn (array $a) => [0, (string) json_encode($a[0] === 'bob' ? [[
        'time' => '2026-10-06T01:00:00.123Z', 'paths' => ['/home/bob'], 'hostname' => 'arcpanel-test', 'id' => BK_SNAPSHOT.str_repeat('0', 56), 'short_id' => BK_SNAPSHOT,
    ]] : [])]);
    $fake->on('v-list-user-backup-restic')->using(fn (array $a) => [0, (string) json_encode(['bob.2026-10-06_01-00-00' => [
        'TYPE' => 'restic', 'WEB' => 'example.com', 'DNS' => 'example.com', 'MAIL' => '', 'DB' => 'bob_wp', 'CRON' => 'yes', 'UDIR' => '',
    ]])]);
    $fake->on('v-list-user-files-restic')->using(fn (array $a) => [0, implode("\n", [
        json_encode(['struct_type' => 'snapshot', 'short_id' => BK_SNAPSHOT]),
        json_encode(['name' => 'tmp', 'type' => 'dir', 'path' => $a[2].'/tmp', 'mode' => 2147484141, 'struct_type' => 'node']),
        json_encode(['name' => 'note.txt', 'type' => 'file', 'path' => $a[2].'/note.txt', 'size' => 9, 'mode' => 420, 'struct_type' => 'node']),
    ])]);
    $fake->on('v-list-user-backup-exclusions')->returnsJson([
        'WEB' => ['example.com' => 'public_html/cache'], 'DNS' => ['example.com' => ''], 'MAIL' => [], 'DB' => [], 'CRON' => ['*' => ''], 'USER' => ['tmp' => '', 'apps/kuma' => ''],
    ]);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake, ['bob' => ['BACKUPS' => '3', 'BACKUPS_INCREMENTAL' => 'yes']]);
    fakeBackupData($this->fake);
});

// Archives (spec §10.2)

it('opens the backups of the effective user, or of the owner an administrator picks', function () {
    signIn('bob', 'user');
    $this->get('/backups')->assertRedirect('/backups/bob');
    $this->get('/backups?owner=carol')->assertNotFound();

    signIn('arcadmin', 'admin');
    $this->get('/backups?owner=carol')->assertRedirect('/backups/carol');
});

it('lists the archives of a user, newest first, with the snapshots tab', function () {
    signIn('bob', 'user');

    $this->get('/backups/bob')->assertOk()
        ->assertSeeInOrder(['bob.2026-10-03_05-10-09.tar', 'bob.2026-10-01_05-10-11.tar'])
        ->assertSee('/backups/bob/snapshots')->assertSee('Create Backup');
    $this->get('/backups/carol')->assertNotFound();
});

it('scopes resellers to their tree', function () {
    fakeResellers($this->fake, ['bob' => ['BACKUPS_INCREMENTAL' => 'yes']]);
    signIn('hostco', 'reseller');

    $this->get('/backups/bob')->assertOk()->assertSee(BK_ARCHIVE);
    $this->get('/backups/carol')->assertNotFound();
});

it('schedules a backup and explains a busy queue', function () {
    $this->fake->on('v-schedule-user-backup')->returns();
    signIn('bob', 'user');

    $this->post('/backups/bob')->assertRedirect('/backups/bob')
        ->assertSessionHas('ok', 'Task has been added to the queue. You will receive an email notification when your backup is ready for download.');
    $this->fake->assertRan('v-schedule-user-backup', fn ($a) => $a === ['bob']);

    $this->fake->on('v-schedule-user-backup')->returnsExit(ExitCode::Exists, 'Error: backup is already scheduled');
    $this->post('/backups/bob')->assertSessionHas('error', 'An existing backup task is already running, please wait for it to complete.');
});

it('restores a whole archive or the objects ticked on its page', function () {
    $this->fake->on('v-schedule-user-restore')->returns();
    signIn('bob', 'user');

    $this->get('/backups/bob/archives/'.BK_ARCHIVE)->assertOk()->assertSee('example.com')->assertSee('Restore Selected');
    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore')->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', BK_ARCHIVE]);

    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore', ['items' => ['web' => ['example.com', 'not-in-backup.example']]])->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', BK_ARCHIVE, 'example.com', 'no', 'no', 'no', 'no', 'no']);

    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore', ['items' => ['db' => ['bob_other']]])->assertSessionHas('error', 'No items were selected to restore.');
    $this->get('/backups/bob/archives/carol.2026-10-03_05-10-09.tar')->assertNotFound();
    $this->get('/backups/bob/archives/bob.missing.tar')->assertNotFound();
});

it('deletes one archive or several, and only archives of the user', function () {
    $this->fake->on('v-delete-user-backup')->returns();
    signIn('bob', 'user');

    $this->delete('/backups/bob/archives/'.BK_ARCHIVE)->assertRedirect('/backups/bob')->assertSessionHas('ok', 'Backup '.BK_ARCHIVE.' has been deleted.');
    $this->post('/backups/bob/bulk', ['action' => 'delete', 'items' => [BK_ARCHIVE, 'bob.2026-10-01_05-10-11.tar']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->post('/backups/bob/bulk', ['action' => 'delete', 'items' => [BK_ARCHIVE, 'carol.2026-10-01.tar']])->assertNotFound();
    $this->fake->assertRan('v-delete-user-backup', times: 3);
});

it('serves a local archive through NGINX and fetches a remote one first', function () {
    $dir = sys_get_temp_dir().'/arc-backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/'.BK_ARCHIVE);
    config(['arc.backup_dir' => $dir]);
    $this->fake->on('v-schedule-user-backup-download')->returns();
    signIn('bob', 'user');

    $this->get('/backups/bob/archives/'.BK_ARCHIVE.'/download')->assertOk()
        ->assertHeader('X-Accel-Redirect', '/backup/'.BK_ARCHIVE)
        ->assertHeader('Content-Type', 'application/x-tar');
    $this->get('/backups/bob/archives/bob.2026-10-01_05-10-11.tar/download')->assertRedirect('/backups/bob')
        ->assertSessionHas('ok', 'Download of remote backup file has been scheduled.');
    $this->fake->assertRan('v-schedule-user-backup-download', fn ($a) => $a === ['bob', 'bob.2026-10-01_05-10-11.tar']);

    unlink($dir.'/'.BK_ARCHIVE);
    rmdir($dir);
});

// Snapshots (spec §10.2)

it('lists, schedules, restores and deletes restic snapshots', function () {
    foreach (['v-schedule-user-backup-restic', 'v-schedule-user-restore-restic', 'v-delete-user-backup-restic'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->get('/backups/bob/snapshots')->assertOk()->assertSee(BK_SNAPSHOT)->assertSee('2026-10-06 01:00')->assertSee('/files/bob?snapshot='.BK_SNAPSHOT);
    $this->post('/backups/bob/snapshots')->assertSessionHas('ok', 'Snapshot has been scheduled.');
    $this->get('/backups/bob/snapshots/'.BK_SNAPSHOT)->assertOk()->assertSee('example.com')->assertSee('bob_wp')->assertSee('All cron jobs');

    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore')->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT]);
    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore', ['type' => 'db', 'object' => 'bob_wp'])->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT, 'db', 'bob_wp']);
    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore', ['type' => 'cron'])->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT, 'cron', 'yes']);
    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore', ['type' => 'web', 'object' => 'other.example'])->assertNotFound();

    $this->delete('/backups/bob/snapshots/'.BK_SNAPSHOT)->assertSessionHas('ok', 'Snapshot '.BK_SNAPSHOT.' has been deleted.');
    $this->get('/backups/bob/snapshots/0123abcd')->assertNotFound();
});

it('has no snapshots without incremental backups in the package', function () {
    fakeServerUsers($this->fake, ['bob' => ['BACKUPS_INCREMENTAL' => 'no']]);
    signIn('bob', 'user');

    $this->get('/backups/bob')->assertOk()->assertDontSee('/backups/bob/snapshots');
    $this->get('/backups/bob/snapshots')->assertNotFound();
    $this->get('/files/bob?snapshot='.BK_SNAPSHOT)->assertNotFound();
});

// Exclusions (spec §10.2, D5)

it('shows the exclusions with a toggle per Docker app and keeps DNS and CRON on save', function () {
    $this->fake->on('v-list-docker-apps')->using(fn (array $a) => [0, (string) json_encode(dockerAppList($a[0], [dockerApp('kuma')]))]);
    $this->fake->on('v-update-user-backup-exclusions')->returns();
    signIn('bob', 'user');

    $this->get('/backups/bob/exclusions')->assertOk()
        ->assertSee('example.com:public_html/cache')->assertSee('Docker app Uptime Kuma (~/apps/kuma)')
        ->assertSee('Snapshots ignore exclusions.')->assertDontSee('Node.js runtimes');

    $this->post('/backups/bob/exclusions', ['web' => "example.com:public_html/cache\nshop.example", 'mail' => '', 'db' => '*', 'userdir' => "tmp\nlogs"])
        ->assertRedirect('/backups/bob/exclusions')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-update-user-backup-exclusions', fn ($a) => $a[0] === 'bob'
        && (string) $a[1] === "WEB='example.com:public_html/cache,shop.example'\nDNS='example.com'\nMAIL=''\nDB='*'\nCRON='*'\nUSER='tmp,logs'\n");

    $this->post('/backups/bob/exclusions', ['userdir' => 'tmp', 'toggles' => ['apps/kuma']])->assertSessionHas('ok');
    $this->fake->assertRan('v-update-user-backup-exclusions', fn ($a) => str_contains((string) $a[1], "USER='tmp,apps/kuma'"));
});

it('refuses invalid exclusion lines', function () {
    signIn('bob', 'user');

    $this->post('/backups/bob/exclusions', ['userdir' => "tmp\n../etc"])->assertSessionHasErrors('userdir');
    $this->post('/backups/bob/exclusions', ['web' => "bad'domain"])->assertSessionHasErrors('web');
    $this->fake->assertNotRan('v-update-user-backup-exclusions');
});

// Snapshot mode of the file manager (spec §10.3)

it('opens a snapshot folder in the file manager, read-only with Restore', function () {
    signIn('bob', 'user');

    $html = $this->get('/files/bob?snapshot='.BK_SNAPSHOT.'&path=tmp')->assertOk()
        ->assertSee('Snapshot '.BK_SNAPSHOT)->assertSee('note.txt')->getContent();
    $props = json_decode(html_entity_decode((string) str($html)->match('/data-island="file-manager" data-props="([^"]*)"/')), true);
    expect($props['snapshot'])->toMatchArray(['id' => BK_SNAPSHOT, 'restore' => true])
        ->and($props['abilities']['manage'])->toBeFalse()
        ->and($props['listing']['path'])->toBe('tmp');
    $this->fake->assertRan('v-list-user-files-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT, '/home/bob/tmp']);

    $this->getJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/list?path=tmp')->assertOk()
        ->assertJsonPath('path', 'tmp')->assertJsonPath('entries.0.name', 'tmp')->assertJsonPath('entries.1.name', 'note.txt')
        ->assertJsonPath('entries.1.writable', false);
    $this->get('/files/bob?snapshot=../../x')->assertNotFound();
    $this->get('/files/bob?snapshot=0123abcd')->assertNotFound();
});

it('queues the restore of one file and maps the command errors', function () {
    $this->fake->on('v-schedule-user-restore-file-restic')->returns();
    signIn('bob', 'user');

    $this->postJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/restore', ['path' => 'tmp/note.txt'])->assertOk()
        ->assertJsonPath('message', 'note.txt will be restored from the snapshot. The restore runs in the background.');
    $this->fake->assertRan('v-schedule-user-restore-file-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT, '/home/bob/tmp/note.txt']);

    $this->fake->on('v-schedule-user-restore-file-restic')->returnsExit(ExitCode::Invalid, 'Error: invalid path format');
    $this->postJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/restore', ['path' => 'tmp/a b.txt'])->assertStatus(422)
        ->assertJsonPath('error.message', 'This name cannot be restored on its own: restore its folder instead.');
    $this->fake->on('v-schedule-user-restore-file-restic')->returnsExit(ExitCode::Exists, 'Error: restore is already scheduled');
    $this->postJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/restore', ['path' => 'tmp/note.txt'])->assertStatus(409);
    $this->postJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/restore', ['path' => ''])->assertStatus(422);
});

it('keeps another tree out of the snapshot routes', function () {
    fakeResellers($this->fake, ['bob' => ['BACKUPS_INCREMENTAL' => 'yes'], 'carol' => ['BACKUPS_INCREMENTAL' => 'yes']]);
    signIn('resb', 'reseller');

    $this->get('/files/bob?snapshot='.BK_SNAPSHOT)->assertNotFound();
    $this->postJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/restore', ['path' => 'tmp/note.txt'])->assertNotFound();
    $this->fake->assertNotRan('v-schedule-user-restore-file-restic');
});

it('refuses every change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes', 'BACKUPS_INCREMENTAL' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/backups/bob')->assertOk()->assertDontSee('Create Backup');
    $this->post('/backups/bob')->assertForbidden();
    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore')->assertForbidden();
    $this->delete('/backups/bob/archives/'.BK_ARCHIVE)->assertForbidden();
    $this->post('/backups/bob/snapshots')->assertForbidden();
    $this->post('/backups/bob/exclusions', ['userdir' => 'tmp'])->assertForbidden();
    $this->postJson('/files/bob/api/snapshots/'.BK_SNAPSHOT.'/restore', ['path' => 'tmp/note.txt'])->assertForbidden();
});

it('has a capability for every backup route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'backups.'));

    expect($routes)->not->toBeEmpty();
    foreach (['backups.view', 'backups.create', 'backups.restore', 'backups.delete', 'backups.exclusions.edit'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});
