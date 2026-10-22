<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

const BK_SNAPSHOT = '4f2a91c0';
const BK_ARCHIVE = 'bob.2026-10-03_05-10-09.tar';

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake, ['bob' => ['BACKUPS' => '3', 'BACKUPS_INCREMENTAL' => 'yes']]);
    fakeBackupData($this->fake);
    fakeBackupObjects($this->fake);
});

// Archives (spec §10.2)

it('opens the backups of the effective user, or of the owner an administrator picks', function () {
    signIn('bob', 'user');
    $this->get('/backups')->assertRedirect('/backups/bob');
    $this->get('/backups?owner=carol')->assertNotFound();

    signIn('arcadmin', 'admin');
    $this->get('/backups?owner=carol')->assertRedirect('/backups/carol');
});

it('lists the archives of a user, newest first, with their destinations and job', function () {
    signIn('bob', 'user');

    $this->get('/backups/bob/archives')->assertOk()
        ->assertSeeInOrder(['bob.2026-10-03_05-10-09.tar', 'bob.2026-10-01_05-10-11.tar'])
        ->assertSee('/backups/bob/snapshots')->assertSee('/backups/bob/history')->assertSee('Create Backup')
        ->assertSee('/backups/bob/restore?archive='.BK_ARCHIVE, false);
    $this->get('/backups/carol/archives')->assertNotFound();
});

it('scopes resellers to their tree', function () {
    fakeResellers($this->fake, ['bob' => ['BACKUPS_INCREMENTAL' => 'yes']]);
    signIn('hostco', 'reseller');

    $this->get('/backups/bob')->assertOk();
    $this->get('/backups/bob/archives')->assertOk()->assertSee(BK_ARCHIVE);
    $this->get('/backups/bob/history')->assertOk();
    $this->get('/backups/bob/restore')->assertOk();
    foreach (['', '/archives', '/history', '/restore', '/snapshots', '/exclusions'] as $path) {
        $this->get('/backups/carol'.$path)->assertNotFound();
    }
});

it('schedules a backup and explains a busy queue', function () {
    $this->fake->on('v-schedule-user-backup')->returns();
    signIn('bob', 'user');

    $this->post('/backups/bob')->assertRedirect('/backups/bob/archives')
        ->assertSessionHas('ok', 'Task has been added to the queue. You will receive an email notification when your backup is ready for download.');
    $this->fake->assertRan('v-schedule-user-backup', fn ($a) => $a === ['bob']);

    $this->fake->on('v-schedule-user-backup')->returnsExit(ExitCode::Exists, 'Error: backup is already scheduled');
    $this->post('/backups/bob')->assertSessionHas('error', 'An existing backup task is already running, please wait for it to complete.');
});

// Overview, history and the restore wizard (E48 spec §8.1)

it('shows the last run, the next runs and the destinations of the jobs that cover the account', function () {
    signIn('bob', 'user');

    $html = $this->get('/backups/bob')->assertOk()
        ->assertSee('Partial')->assertSee('2026-10-22 05:10:02')->assertSee('3 min')
        ->assertSee('2026-10-23 05:10:00')->assertSee('2026-10-22 23:15:00')
        ->assertSee('2 archives')->assertSee('1 snapshot')->assertSee('Back up now')
        ->getContent();
    // Destinations by name only, never their settings; a destination no covering job uses stays hidden
    expect($html)->toContain('offsite')->toContain('local')->not->toContain('unused')->not->toContain('secret-bucket-name');
    // One archive job covers bob, so Back up now names it
    expect($html)->toContain('name="job" value="179267621466"');
});

it('leaves the snapshot job out of the overview without incremental backups', function () {
    fakeServerUsers($this->fake, ['bob' => ['BACKUPS_INCREMENTAL' => 'no']]);
    signIn('bob', 'user');

    $this->get('/backups/bob')->assertOk()->assertSee('2026-10-23 05:10:00')->assertDontSee('2026-10-22 23:15:00')->assertDontSee('1 snapshot');
});

it('backs up with a job that covers the account, and only with such a job', function () {
    $this->fake->on('v-schedule-user-backup')->returns();
    signIn('bob', 'user');

    $this->post('/backups/bob', ['job' => '179267621466', 'from' => 'overview'])->assertRedirect('/backups/bob')->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-backup', fn ($a) => $a === ['bob', 'shell', '179267621466']);
    // A snapshot job and an unknown job are refused
    $this->post('/backups/bob', ['job' => '179267621499'])->assertNotFound();
    $this->post('/backups/bob', ['job' => '1'])->assertNotFound();
    $this->fake->assertRan('v-schedule-user-backup', times: 1);
});

it('lists the runs of the account with their destinations', function () {
    signIn('bob', 'user');

    $this->get('/backups/bob/history')->assertOk()
        ->assertSeeInOrder(['2026-10-22 05:10:02', '2026-10-21 10:00:00'])
        ->assertSee('Nightly archives')->assertSee('offsite')->assertSee('not enough disk space');
});

it('filters the archives by destination', function () {
    $this->fake->on('v-list-user-backups')->returnsJson([
        BK_ARCHIVE => ['TYPE' => 'local', 'DESTINATIONS' => 'local,offsite', 'JOB' => '179267621466', 'SIZE' => '412', 'DATE' => '2026-10-03', 'TIME' => '05:10:09',
            'WEB' => 'example.com', 'DNS' => '', 'MAIL' => '', 'DB' => '', 'CRON' => '', 'UDIR' => ''],
        'bob.2026-10-01_05-10-11.tar' => ['TYPE' => 'local', 'DESTINATIONS' => 'local', 'JOB' => '', 'SIZE' => '400', 'DATE' => '2026-10-01', 'TIME' => '05:10:11',
            'WEB' => 'example.com', 'DNS' => '', 'MAIL' => '', 'DB' => '', 'CRON' => '', 'UDIR' => ''],
    ]);
    signIn('bob', 'user');

    $this->get('/backups/bob/archives')->assertOk()->assertSee('All destinations')->assertSee('Nightly archives')
        ->assertSee(BK_ARCHIVE)->assertSee('bob.2026-10-01_05-10-11.tar');
    $this->get('/backups/bob/archives?destination=offsite')->assertOk()
        ->assertSee(BK_ARCHIVE)->assertDontSee('bob.2026-10-01_05-10-11.tar');
});

it('walks through the restore wizard for an archive', function () {
    $this->fake->on('v-schedule-user-restore')->returns();
    signIn('bob', 'user');

    $this->get('/backups/bob/restore')->assertOk()->assertSee(BK_ARCHIVE)->assertSee(BK_SNAPSHOT);
    $this->get('/backups/bob/restore?archive='.BK_ARCHIVE)->assertOk()
        ->assertSee('Everything')->assertSee('example.com')->assertSee('name="wizard" value="1"', false);
    $this->get('/backups/bob/restore?archive=carol.2026-10-03_05-10-09.tar')->assertNotFound();

    $wizard = ['wizard' => '1'];
    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore', $wizard + ['mode' => 'all', 'items' => ['web' => ['example.com']]])
        ->assertRedirect('/backups/bob/restore?archive='.BK_ARCHIVE)->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', BK_ARCHIVE]);

    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore', $wizard + ['mode' => 'some', 'items' => ['web' => ['example.com']]])->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore', fn ($a) => $a === ['bob', BK_ARCHIVE, 'example.com', 'no', 'no', 'no', 'no', 'no']);

    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore', $wizard + ['mode' => 'some'])
        ->assertRedirect('/backups/bob/restore?archive='.BK_ARCHIVE)->assertSessionHas('error', 'No items were selected to restore.');
    $this->fake->assertRan('v-schedule-user-restore', times: 2);
});

it('walks through the restore wizard for a snapshot', function () {
    $this->fake->on('v-schedule-user-restore-restic')->returns();
    signIn('bob', 'user');

    $this->get('/backups/bob/restore?snapshot='.BK_SNAPSHOT)->assertOk()->assertSee('bob_wp')->assertSee('name="pick"', false);
    $wizard = ['wizard' => '1'];
    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore', $wizard + ['mode' => 'all'])
        ->assertRedirect('/backups/bob/restore?snapshot='.BK_SNAPSHOT)->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT]);
    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore', $wizard + ['mode' => 'some', 'pick' => 'db:bob_wp'])->assertSessionHas('ok');
    $this->fake->assertRan('v-schedule-user-restore-restic', fn ($a) => $a === ['bob', BK_SNAPSHOT, 'db', 'bob_wp']);
    $this->post('/backups/bob/snapshots/'.BK_SNAPSHOT.'/restore', $wizard + ['mode' => 'some'])->assertSessionHas('error', 'No items were selected to restore.');
    $this->fake->assertRan('v-schedule-user-restore-restic', times: 2);
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

it('leaves mail and DNS out of the backup pages on a server without them', function () {
    $this->fake = fakeArc(['DNS_SYSTEM' => '', 'MAIL_SYSTEM' => '']);
    fakeServerUsers($this->fake, ['bob' => ['BACKUPS' => '3', 'BACKUPS_INCREMENTAL' => 'yes']]);
    fakeBackupData($this->fake);
    signIn('bob', 'user');

    $this->get('/backups/bob/archives')->assertOk()->assertSee('Daily backups of websites, databases, cron jobs and files.');
    $this->get('/backups/bob/restore?archive='.BK_ARCHIVE)->assertOk()->assertDontSee('Mail Domains')->assertDontSee('DNS Domains');
    // The archive holds neither; the snapshot holds a DNS zone, so its card stays
    $this->get('/backups/bob/archives/'.BK_ARCHIVE)->assertOk()
        ->assertSee('Current websites, databases and files are replaced.')
        ->assertDontSee('Mail Domains')->assertDontSee('DNS Domains')->assertSee('Web Domains');
    $this->get('/backups/bob/snapshots/'.BK_SNAPSHOT)->assertOk()
        ->assertSee('Current websites, databases and files are replaced.')
        ->assertDontSee('Mail Domains')->assertSee('DNS Domains');
});

it('names mail in the backup pages on a server with mail', function () {
    signIn('bob', 'user');

    $this->get('/backups/bob/archives')->assertOk()->assertSee('Daily backups of websites, mail, databases, cron jobs and files.');
    $this->get('/backups/bob/archives/'.BK_ARCHIVE)->assertOk()->assertSee('Mail Domains')->assertSee('DNS Domains');
});

it('deletes one archive or several, and only archives of the user', function () {
    $this->fake->on('v-delete-user-backup')->returns();
    signIn('bob', 'user');

    $this->delete('/backups/bob/archives/'.BK_ARCHIVE)->assertRedirect('/backups/bob/archives')->assertSessionHas('ok', 'Backup '.BK_ARCHIVE.' has been deleted.');
    $this->post('/backups/bob/bulk', ['action' => 'delete', 'items' => [BK_ARCHIVE, 'bob.2026-10-01_05-10-11.tar']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->post('/backups/bob/bulk', ['action' => 'delete', 'items' => [BK_ARCHIVE, 'carol.2026-10-01.tar']])->assertNotFound();
    $this->fake->assertRan('v-delete-user-backup', times: 3);
});

it('serves a local archive through NGINX and fetches a remote one first', function () {
    $dir = sys_get_temp_dir().'/arc-backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/'.BK_ARCHIVE);
    withSysConfig($this->fake, ['BACKUP' => $dir]);
    config(['arc.backup_dir' => $dir]);
    $this->fake->on('v-schedule-user-backup-download')->returns();
    signIn('bob', 'user');

    $this->get('/backups/bob/archives/'.BK_ARCHIVE.'/download')->assertOk()
        ->assertHeader('X-Accel-Redirect', '/backup/'.BK_ARCHIVE)
        ->assertHeader('Content-Type', 'application/x-tar');
    // A link only asks; the button posts (E39 W5 L22)
    $this->get('/backups/bob/archives/bob.2026-10-01_05-10-11.tar/download')->assertOk()
        ->assertSee('action="'.url('/backups/bob/archives/bob.2026-10-01_05-10-11.tar/download').'"', false)
        ->assertSee('method="post"', false);
    $this->fake->assertRan('v-schedule-user-backup-download', times: 0);
    $this->post('/backups/bob/archives/bob.2026-10-01_05-10-11.tar/download')->assertRedirect('/backups/bob/archives')
        ->assertSessionHas('ok', 'Download of remote backup file has been scheduled.');
    $this->fake->assertRan('v-schedule-user-backup-download', fn ($a) => $a === ['bob', 'bob.2026-10-01_05-10-11.tar']);
    // A local archive needs no fetch
    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/download')->assertRedirect('/backups/bob/archives/'.BK_ARCHIVE.'/download');
    $this->fake->assertRan('v-schedule-user-backup-download', times: 1);

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

it('explains the snapshots tab without incremental backups in the package', function () {
    fakeServerUsers($this->fake, ['bob' => ['BACKUPS_INCREMENTAL' => 'no']]);
    signIn('bob', 'user');

    $this->get('/backups/bob')->assertOk()->assertSee('/backups/bob/snapshots');
    $this->get('/backups/bob/snapshots')->assertOk()->assertSee('Incremental backups are not part of this plan')
        ->assertDontSee(BK_SNAPSHOT)->assertDontSee('Create Snapshot');
    $this->fake->assertNotRan('v-list-user-backups-restic');
    $this->post('/backups/bob/snapshots')->assertNotFound();
    $this->get('/backups/bob/restore?snapshot='.BK_SNAPSHOT)->assertNotFound();
    $this->get('/files/bob?snapshot='.BK_SNAPSHOT)->assertNotFound();
});

// Exclusions (spec §10.2, D5; structured lists of E48 spec §8.1)

it('shows the exclusions as lists of the account records, with a toggle per Docker app', function () {
    $this->fake->on('v-list-docker-apps')->using(fn (array $a) => [0, (string) json_encode(dockerAppList($a[0], [dockerApp('kuma')]))]);
    signIn('bob', 'user');

    $html = $this->get('/backups/bob/exclusions')->assertOk()
        ->assertSee('Docker app Uptime Kuma (~/apps/kuma)')->assertSee('Snapshots ignore exclusions.')->assertDontSee('Node.js runtimes')
        ->assertSee('DNS exclusions set from the command line are kept.')
        ->getContent();
    // example.com is ticked with its folder, shop.example listed and not ticked; the databases come from v-list-databases
    expect($html)->toContain('name="web[]" value="example.com" x-model="on" x-bind:disabled="all" checked')
        ->toContain('name="web_sub[example.com]" value="public_html/cache"')
        ->toContain('name="web[]" value="shop.example" x-model="on" x-bind:disabled="all" >')
        ->toContain('name="db[]" value="bob_shop"')->toContain('name="mail[]" value="example.com"')
        ->toContain('name="cron" value="1"');
    expect(substr_count($html, 'name="cron"'))->toBe(1);
});

it('saves the structured exclusions and keeps DNS', function () {
    $this->fake->on('v-list-docker-apps')->using(fn (array $a) => [0, (string) json_encode(dockerAppList($a[0], [dockerApp('kuma')]))]);
    $this->fake->on('v-update-user-backup-exclusions')->returns();
    signIn('bob', 'user');

    $this->post('/backups/bob/exclusions', [
        'web' => ['example.com', 'shop.example'], 'web_sub' => ['example.com' => "public_html/cache, tmp\nlogs", 'shop.example' => ''],
        'mail' => [], 'db_all' => '1', 'db' => ['bob_wp'], 'cron' => '1', 'userdir' => "tmp\nlogs",
    ])->assertRedirect('/backups/bob/exclusions')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-update-user-backup-exclusions', fn ($a) => $a[0] === 'bob'
        && (string) $a[1] === "WEB='example.com:public_html/cache:tmp:logs,shop.example'\nDNS='example.com'\nMAIL=''\nDB='*'\nCRON='*'\nUSER='tmp,logs'\n");

    // Nothing ticked: the lists are emptied and the cron jobs are backed up again
    $this->post('/backups/bob/exclusions', ['userdir' => 'tmp', 'toggles' => ['apps/kuma']])->assertSessionHas('ok');
    $this->fake->assertRan('v-update-user-backup-exclusions', fn ($a) => (string) $a[1] === "WEB=''\nDNS='example.com'\nMAIL=''\nDB=''\nCRON=''\nUSER='tmp,apps/kuma'\n");
});

it('refuses invalid exclusion entries', function () {
    signIn('bob', 'user');

    $this->post('/backups/bob/exclusions', ['userdir' => "tmp\n../etc"])->assertSessionHasErrors('userdir');
    $this->post('/backups/bob/exclusions', ['web' => ["bad'domain"]])->assertSessionHasErrors('web.0');
    $this->post('/backups/bob/exclusions', ['web' => ['example.com'], 'web_sub' => ['example.com' => '../etc']])->assertSessionHasErrors('web_sub.example.com');
    $this->post('/backups/bob/exclusions', ['db' => ['bob wp']])->assertSessionHasErrors('db.0');
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

    $this->get('/backups/bob')->assertOk()->assertDontSee('Back up now');
    $this->get('/backups/bob/archives')->assertOk()->assertDontSee('Create Backup');
    $this->post('/backups/bob')->assertForbidden();
    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/restore')->assertForbidden();
    $this->post('/backups/bob/archives/'.BK_ARCHIVE.'/download')->assertForbidden();
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
