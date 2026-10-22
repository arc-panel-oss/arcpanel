<?php

use App\Arc\ExitCode;
use App\Auth\SudoMode;

// The administrators' Backups hub (E48 spec §8.2): destinations, jobs,
// activity and settings over the v-*-backup-destination and -job commands.

/** `v-list-backup-destinations json`, as the command prints it */
function backupDestinations(array $overrides = []): array
{
    $rows = [
        'local' => ['NAME' => 'local', 'DRIVER' => 'local', 'DESCRIPTION' => '', 'ENCRYPT' => 'no', 'BWLIMIT' => '', 'RESTIC_PREFIX' => 'restic',
            'HOST_KEY' => '', 'STATE' => 'ok', 'CHECKED' => '2026-10-22 20:37:00', 'FAILURES' => '0', 'KIT' => '', 'CREATED' => '2026-10-22 20:36:54',
            'UPDATED' => '2026-10-22 20:36:54', 'PATH' => '/backup', 'SECRETS' => '', 'JOBS' => '179267621466'],
        'offsite' => ['NAME' => 'offsite', 'DRIVER' => 'sftp', 'DESCRIPTION' => 'Storage box', 'ENCRYPT' => 'yes', 'BWLIMIT' => '10M', 'RESTIC_PREFIX' => 'restic',
            'HOST_KEY' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'STATE' => 'failing', 'CHECKED' => '2026-10-22 21:00:00', 'FAILURES' => '3',
            'KIT' => '', 'CREATED' => '2026-10-22 20:40:00', 'UPDATED' => '2026-10-22 20:40:00', 'HOST' => 'box.example.net', 'PORT' => '23',
            'USERNAME' => 'u123', 'PATH' => 'arc', 'SECRETS' => 'PASSWORD', 'JOBS' => ''],
    ];

    return array_replace_recursive($rows, $overrides);
}

/** `v-list-backup-jobs json` */
function backupJobs(): array
{
    return ['179267621466' => ['ID' => '179267621466', 'NAME' => 'Nightly archives', 'ENGINE' => 'archive', 'SCOPE' => 'all', 'EXCLUDE_USERS' => 'carol',
        'SCHEDULE' => 'daily:05:10', 'TARGETS' => 'local:-1:-1:-1:-1:-1', 'STATE' => 'enabled', 'LAST_RUN' => '17926789985709',
        'NEXT_RUN' => '2026-10-23 05:10:00', 'NEXT_RUN_TS' => '1792707000', 'CREATED' => '2026-10-22 20:36:54', 'UPDATED' => '2026-10-22 21:23:09',
        'LAST_STATUS' => 'partial', 'LAST_END' => '2026-10-22 21:23:26', 'RUNNING' => 'no']];
}

/** `v-list-backup-job-runs [ID] json` */
function backupJobRuns(): array
{
    return [
        ['JOB' => '179267621466', 'JOB_NAME' => 'Nightly archives', 'RUN' => '17926789985709', 'TRIGGER' => 'schedule', 'STATUS' => 'partial',
            'START' => now()->subHour()->format('Y-m-d H:i:s'), 'END' => now()->subMinutes(50)->format('Y-m-d H:i:s'), 'USERS' => '2', 'DONE' => '2', 'FAILED' => '0', 'SKIPPED' => '1', 'PID' => ''],
        ['JOB' => '179267621466', 'JOB_NAME' => 'Nightly archives', 'RUN' => '17926000000001', 'TRIGGER' => 'cli', 'STATUS' => 'success',
            'START' => '2026-10-01 05:10:00', 'END' => '2026-10-01 05:12:00', 'USERS' => '2', 'DONE' => '2', 'FAILED' => '0', 'SKIPPED' => '0', 'PID' => ''],
    ];
}

beforeEach(function () {
    $this->fake = fakeArc(['BACKUP' => '/backup', 'BACKUP_MODE' => 'gzip', 'BACKUP_GZIP' => '9', 'BACKUP_PARALLEL' => '1', 'BACKUP_LA_LIMIT' => '4',
        'BACKUP_REPORT' => 'failures', 'BACKUP_TEMP' => '/backup', 'POLICY_BACKUP_SUSPENDED_USERS' => 'no', 'BACKUP_SYSTEM' => 'local,sftp']);
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-backup-destinations', ['json'])->returnsJson(backupDestinations());
    $this->fake->on('v-list-backup-jobs', ['json'])->returnsJson(backupJobs());
    $this->fake->on('v-list-backup-job-runs')->returnsJson(backupJobRuns());
    $this->fake->on('v-list-user-packages', ['json'])->returnsJson(['default' => [], 'gold' => []]);
    $this->fake->on('v-list-sys-backup-runs')->returnsJson(['AS_OF' => '1', 'USERS' => ['bob', 'carol'], 'CHANGED_USERS' => ['bob'], 'OBJECTS' => [
        'bob' => ['17926789981107' => ['ENGINE' => 'tar', 'TRIGGER' => 'schedule', 'TASK' => '', 'STATUS' => 'succeeded',
            'START' => now()->subMinutes(55)->format('Y-m-d H:i:s'), 'END' => now()->subMinutes(54)->format('Y-m-d H:i:s'), 'EXIT' => '0', 'ERROR' => '',
            'ARCHIVE' => 'bob.2026-10-22_21-23-19.tar', 'SIZE' => '2', 'JOB' => '179267621466', 'DESTS' => 'local:ok,offsite:failed']],
    ]]);
    $this->fake->on('v-check-backup-destination')->returnsJson(['local' => ['OK' => 'yes', 'ERROR' => '', 'HOST_KEY' => '', 'LATENCY_MS' => '12']]);
});

// ------------------------------------------------------------------ pages

it('shows the overview with the runs of the last day, the next runs, failing destinations and missing kits', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/backups')->assertOk()
        ->assertSee('Destination offsite fails')
        ->assertSee('Download the recovery kits')
        ->assertSee('Nightly archives')->assertSee('2026-10-23 05:10:00')
        ->assertSee('Daily at 05:10')
        ->assertSee('href="'.route('server.backups.activity', ['status' => 'partial']).'"', false);
});

it('lists destinations without their secrets, and jobs with schedule, accounts and targets', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/backups/destinations')->assertOk()
        ->assertSee('u123@box.example.net:arc')->assertSee('No recovery kit')->assertSee('Failing')
        ->assertSee('Storage box')->assertSee(route('server.backups.jobs.edit', ['job' => '179267621466']), false);
    $this->get('/server/backups/jobs')->assertOk()
        ->assertSee('Daily at 05:10')->assertSee('All users')->assertSee('Except carol')->assertSee('Partial');
    // The wizard offers every driver; the job form every destination, package and user
    $this->get('/server/backups/destinations/new')->assertOk()->assertSee('Where should the backups go?')
        ->assertSee('rclone authorize "drive"', false)->assertSee('data-driver-fields="onedrive"', false);
    $this->get('/server/backups/jobs/new')->assertOk()->assertSee('offsite (SFTP, encrypted)')->assertSee('value="gold"', false)
        ->assertSee('value="carol"', false)->assertSee('apBackupJob(', false);
});

it('keeps the hub for administrators: users and resellers get 403 and run nothing', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        foreach (['/server/backups', '/server/backups/destinations', '/server/backups/destinations/new', '/server/backups/destinations/local',
            '/server/backups/jobs', '/server/backups/jobs/new', '/server/backups/jobs/179267621466', '/server/backups/activity',
            '/server/backups/activity/179267621466/17926789985709', '/server/backups/settings', '/server/backups/jobs/preview?preset=daily'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/server/backups/destinations', ['name' => 'x', 'driver' => 'local', 'fields' => ['PATH' => '/backup/x']])->assertForbidden();
        $this->delete('/server/backups/destinations/local')->assertForbidden();
        $this->post('/server/backups/destinations/local/check')->assertForbidden();
        $this->post('/server/backups/jobs/179267621466/run')->assertForbidden();
        $this->post('/server/backups/jobs/179267621466/enabled', ['enabled' => '0'])->assertForbidden();
        $this->post('/server/backups/settings', ['mode' => 'gzip', 'level' => '9', 'temp' => '/backup', 'parallel' => '2', 'la_limit' => '4', 'report' => 'off'])->assertForbidden();
    }
    foreach (['v-add-backup-destination', 'v-delete-backup-destination', 'v-check-backup-destination', 'v-start-backup-job', 'v-suspend-backup-job', 'v-change-sys-config-value'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('shows the hub read-only to an administrator looking at a protected one', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);

    $this->get('/server/backups/destinations')->assertOk()->assertDontSee('Add destination');
    $this->get('/server/backups/jobs/179267621466')->assertOk()->assertDontSee('Run now');
    $this->post('/server/backups/jobs/179267621466/run')->assertForbidden();
    $this->fake->assertNotRan('v-start-backup-job');
});

it('answers 404 for destinations, jobs and runs that do not exist', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/backups/destinations/nosuch')->assertNotFound();
    $this->get('/server/backups/jobs/179200000000')->assertNotFound();
    $this->get('/server/backups/activity/179267621466/17920000000000')->assertNotFound();
    $this->delete('/server/backups/destinations/nosuch')->assertNotFound();
    $this->post('/server/backups/jobs/179200000000/run')->assertNotFound();
});

it('is a hub in the server navigation of administrators only, and the settings tab links to it', function () {
    signIn('arcadmin', 'admin');
    $this->get('/server/backups')->assertSee('href="'.route('server.backups.destinations').'"', false)
        ->assertSee('href="'.route('server.backups.jobs').'"', false)->assertSee('Schedules');

    fakeResellers($this->fake);
    signIn('hostco', 'reseller');
    $this->get('/web/domains')->assertDontSee('href="'.route('server.backups').'"', false);
});

// ------------------------------------------------------------ destinations

it('adds a destination with an options file and a directory of secrets, then tests it', function () {
    $this->fake->on('v-add-backup-destination')->returns();
    $this->fake->on('v-check-backup-destination', fn ($a) => $a[0] === 'box')->returnsJson(['box' => ['OK' => 'no', 'ERROR' => '',
        'HOST_KEY' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'LATENCY_MS' => '']]);
    signIn('arcadmin', 'admin');

    $this->post('/server/backups/destinations', ['name' => 'box', 'driver' => 'sftp', 'fields' => ['HOST' => 'box.example.net', 'USERNAME' => 'u123', 'PORT' => '23',
        'PATH' => 'arc', 'ENCRYPT' => 'yes'], 'secrets' => ['PASSWORD' => 'Sftp-pass-1', 'PRIVATE_KEY' => '', 'PASSPHRASE' => '']])
        ->assertRedirect(route('server.backups.destinations.edit', ['destination' => 'box']))
        ->assertSessionHas('check', fn ($check) => $check['name'] === 'box' && $check['hostKey'] !== '');
    $this->fake->assertRan('v-add-backup-destination', function ($a) {
        return $a[0] === 'box' && $a[1] === 'sftp'
            && str_contains($a[2], "HOST='box.example.net'\n") && str_contains($a[2], "ENCRYPT='yes'\n") && str_contains($a[2], 'TLS_VERIFY') === false
            && json_decode($a[3], true) === ['PASSWORD' => 'Sftp-pass-1'];
    });
    $this->fake->assertRan('v-check-backup-destination', fn ($a) => $a === ['box', '', 'json']);
});

it('checks the fields of the driver and never keeps a secret in the session', function () {
    $this->fake->on('v-add-backup-destination')->returnsExit(ExitCode::Exists, 'Error: backup destination box exists');
    signIn('arcadmin', 'admin');

    $this->post('/server/backups/destinations', ['name' => 'Box!', 'driver' => 'sftp', 'fields' => ['HOST' => 'bad host', 'PORT' => '99999'], 'secrets' => []])
        ->assertSessionHasErrors(['name', 'fields.HOST', 'fields.PORT', 'fields.USERNAME', 'secrets.PASSWORD']);
    $this->post('/server/backups/destinations', ['name' => 'loc', 'driver' => 'local', 'fields' => ['PATH' => '/etc/backup']])->assertSessionHasErrors('fields.PATH');
    $this->post('/server/backups/destinations', ['name' => 'g', 'driver' => 'gdrive', 'fields' => ['AUTH' => 'token'], 'secrets' => ['TOKEN' => 'not json']])
        ->assertSessionHasErrors('secrets.TOKEN');
    $this->fake->assertNotRan('v-add-backup-destination');

    $this->post('/server/backups/destinations', ['name' => 'box', 'driver' => 's3', 'fields' => ['BUCKET' => 'arc-backups', 'ACCESS_KEY_ID' => 'AKIDEXAMPLE'],
        'secrets' => ['SECRET_ACCESS_KEY' => 's3-secret-value']])
        ->assertSessionHas('error', 'Error: backup destination box exists')->assertSessionHasInput('fields.BUCKET', 'arc-backups');
    expect(session()->getOldInput('secrets'))->toBeNull();
});

it('changes a destination: an empty secret keeps the stored one, the driver stays', function () {
    $this->fake->on('v-change-backup-destination')->returns();
    $this->fake->on('v-check-backup-destination', fn ($a) => $a[0] === 'offsite')->returnsJson(['offsite' => ['OK' => 'yes', 'ERROR' => '', 'HOST_KEY' => '', 'LATENCY_MS' => '40']]);
    signIn('arcadmin', 'admin');

    $this->get('/server/backups/destinations/offsite')->assertOk()->assertSee('Stored. Leave empty to keep it.')
        ->assertSee('value="box.example.net"', false)->assertSee('Recovery kit')->assertDontSee('name="driver"', false);
    $this->put('/server/backups/destinations/offsite', ['fields' => ['HOST' => 'box2.example.net', 'USERNAME' => 'u123', 'PATH' => 'arc', 'ENCRYPT' => 'yes'],
        'secrets' => ['PASSWORD' => '']])->assertSessionHas('ok', 'Destination offsite has been saved.');
    $this->fake->assertRan('v-change-backup-destination', fn ($a) => count($a) === 2 && $a[0] === 'offsite' && str_contains($a[1], "HOST='box2.example.net'"));
    $this->put('/server/backups/destinations/nosuch', ['fields' => ['PATH' => '/backup/x']])->assertNotFound();
});

it('pins the host key the administrator confirms, and refuses anything else as a key', function () {
    $this->fake->on('v-check-backup-destination', fn ($a) => $a[0] === 'offsite')->returnsJson(['offsite' => ['OK' => 'yes', 'ERROR' => '', 'HOST_KEY' => '', 'LATENCY_MS' => '40']]);
    signIn('arcadmin', 'admin');

    $this->post('/server/backups/destinations/offsite/check', ['accept' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG'])
        ->assertSessionHas('check', fn ($check) => $check['ok'] && $check['latency'] === '40');
    $this->fake->assertRan('v-check-backup-destination', fn ($a) => $a === ['offsite', 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'json']);
    $this->post('/server/backups/destinations/offsite/check', ['accept' => '; rm -rf /'])->assertStatus(422);
});

it('refuses to delete a destination that a job uses, with the command\'s message', function () {
    $this->fake->on('v-delete-backup-destination')->returnsExit(ExitCode::InUse, 'Error: backup destination local is used by job 179267621466');
    signIn('arcadmin', 'admin');

    $this->delete('/server/backups/destinations/local')->assertSessionHas('error', 'Error: backup destination local is used by job 179267621466');
});

it('gives the recovery kit as a file only after the password was confirmed', function () {
    $this->fake->on('v-export-backup-destination-key')->returns("# ArcPanel recovery kit: offsite\nAGE-SECRET-KEY-1EXAMPLE\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/backups/destinations/offsite/kit')->assertRedirect(route('sudo.show'));
    $this->fake->assertNotRan('v-export-backup-destination-key');

    signIn('arcadmin', 'admin', ['SUDO_UNTIL' => time() + SudoMode::SECONDS]);
    $this->post('/server/backups/destinations/offsite/kit')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="arcpanel-recovery-kit-offsite.txt"')
        ->assertHeader('Cache-Control', 'no-store, private');
    // A destination without encryption has no kit
    $this->post('/server/backups/destinations/local/kit')->assertNotFound();
});

// -------------------------------------------------------------------- jobs

it('adds a job from the schedule preset, the scope and the target rows', function () {
    $this->fake->on('v-add-backup-job')->returns("179270000012\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/backups/jobs', ['name' => 'Hourly snapshots', 'engine' => 'snapshot', 'preset' => 'hourly', 'minute' => '15',
        'scope' => 'packages', 'packages' => ['gold'], 'exclude' => ['carol'],
        'targets' => [['destination' => 'offsite', 'keep_last' => '24', 'daily' => '7', 'weekly' => '', 'monthly' => '', 'yearly' => '']], 'enabled' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect(route('server.backups.jobs'))->assertSessionHas('ok', 'Job Hourly snapshots has been added.');
    $this->fake->assertRan('v-add-backup-job', fn ($a) => $a === ['Hourly snapshots', 'snapshot', 'packages:gold', 'hourly:15', 'offsite:24:7:-1:-1:-1', 'carol', 'enabled']);

    // Archives keep only their count; a cron schedule; a paused job
    $this->post('/server/backups/jobs', ['name' => 'Weekdays', 'engine' => 'archive', 'preset' => 'cron', 'cron' => '30  2 * * 1-5',
        'scope' => 'users', 'users' => ['bob'], 'targets' => [['destination' => 'local', 'keep_last' => '', 'daily' => '5']]]);
    $this->fake->assertRan('v-add-backup-job', fn ($a) => $a === ['Weekdays', 'archive', 'users:bob', 'cron:30 2 * * 1-5', 'local:-1:-1:-1:-1:-1', '', 'paused']);
});

it('checks the job form before running anything', function () {
    $this->fake->on('v-add-backup-job')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/backups/jobs', ['name' => "Bad 'name'", 'engine' => 'mirror', 'preset' => 'cron', 'cron' => '$(reboot)', 'scope' => 'users',
        'targets' => []])->assertSessionHasErrors(['name', 'engine', 'cron', 'users', 'targets']);
    $this->post('/server/backups/jobs', ['name' => 'Snap', 'engine' => 'snapshot', 'preset' => 'daily', 'hour' => '3', 'minute' => '0', 'scope' => 'all',
        'targets' => [['destination' => 'local'], ['destination' => 'local']]])->assertSessionHasErrors(['targets.0.keep_last', 'targets.0.destination']);
    $this->fake->assertNotRan('v-add-backup-job');
});

it('changes, pauses, runs and deletes a job', function () {
    foreach (['v-change-backup-job', 'v-suspend-backup-job', 'v-start-backup-job', 'v-delete-backup-job'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('arcadmin', 'admin');

    $this->get('/server/backups/jobs/179267621466')->assertOk()->assertSee('Nightly archives')->assertSee('Recent runs');
    $this->put('/server/backups/jobs/179267621466', ['name' => 'Nightly archives', 'engine' => 'archive', 'preset' => 'weekly', 'weekday' => '7', 'hour' => '1', 'minute' => '5',
        'scope' => 'all', 'targets' => [['destination' => 'local', 'keep_last' => '3']]])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-backup-job', fn ($a) => $a === ['179267621466', 'Nightly archives', 'archive', 'all', 'weekly:7:01:05', 'local:3:-1:-1:-1:-1', '']);

    $this->post('/server/backups/jobs/179267621466/enabled', ['enabled' => '0'])->assertSessionHas('ok', 'Job Nightly archives is paused.');
    $this->fake->assertRan('v-suspend-backup-job', fn ($a) => $a === ['179267621466']);
    $this->post('/server/backups/jobs/179267621466/run')->assertRedirect(route('server.backups.activity', ['job' => '179267621466']));
    $this->fake->assertRan('v-start-backup-job', fn ($a) => $a === ['179267621466']);
    $this->delete('/server/backups/jobs/179267621466')->assertRedirect(route('server.backups.jobs'));
    $this->fake->assertRan('v-delete-backup-job', fn ($a) => $a === ['179267621466']);
});

it('previews the next runs of a schedule with the dispatcher\'s code', function () {
    $this->fake->on('v-list-backup-schedule', fn ($a) => $a[0] === 'daily:05:10')->returnsJson([
        ['TIMESTAMP' => '1792707000', 'TIME' => '2026-10-23 05:10', 'TIMEZONE' => 'Asia/Bangkok'],
        ['TIMESTAMP' => '1792793400', 'TIME' => '2026-10-24 05:10', 'TIMEZONE' => 'Asia/Bangkok'],
    ]);
    $this->fake->on('v-list-backup-schedule', fn ($a) => $a[0] === 'cron:0 0 31 2 *')->returnsExit(ExitCode::Invalid, 'Error: invalid schedule');
    signIn('arcadmin', 'admin');

    $this->getJson('/server/backups/jobs/preview?preset=daily&hour=5&minute=10')->assertOk()
        ->assertJson(['valid' => true, 'describe' => 'Daily at 05:10', 'times' => ['2026-10-23 05:10', '2026-10-24 05:10']]);
    $this->getJson('/server/backups/jobs/preview?preset=cron&cron='.rawurlencode('0 0 31 2 *'))->assertOk()->assertJson(['valid' => false]);
    $this->getJson('/server/backups/jobs/preview?preset=cron&cron='.rawurlencode('$(id)'))->assertStatus(422);
});

// ---------------------------------------------------------------- activity

it('lists job runs with filters and opens the result of each account', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/backups/activity')->assertOk()->assertSee('17926789985709', false)->assertSee('Partial')->assertSee('Succeeded');
    $this->get('/server/backups/activity?status=success')->assertOk()->assertDontSee(route('server.backups.activity.show', ['job' => '179267621466', 'run' => '17926789985709']), false);
    $this->get('/server/backups/activity?user=bob')->assertOk()
        ->assertSee(route('server.backups.activity.show', ['job' => '179267621466', 'run' => '17926789985709']), false)
        ->assertDontSee(route('server.backups.activity.show', ['job' => '179267621466', 'run' => '17926000000001']), false);
    $this->get('/server/backups/activity?status=bogus')->assertSessionHasErrors('status');

    $this->get('/server/backups/activity/179267621466/17926789985709')->assertOk()
        ->assertSee(route('backups.archives.index', ['user' => 'bob']), false)->assertSee('offsite')->assertSee('2 MB');
});

// ---------------------------------------------------------------- settings

it('saves the backup settings as configuration keys', function () {
    $this->fake->on('v-change-sys-config-value')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/backups/settings')->assertOk()->assertSee('Accounts at once');
    $this->post('/server/backups/settings', ['mode' => 'zstd', 'level' => '6', 'temp' => '/srv/tmp', 'parallel' => '3', 'la_limit' => '8', 'report' => 'daily', 'suspended' => '1'])
        ->assertSessionHas('ok', 'Changes have been saved.');
    foreach ([['BACKUP_MODE', 'zstd'], ['BACKUP_GZIP', '6'], ['BACKUP_TEMP', '/srv/tmp'], ['BACKUP_PARALLEL', '3'], ['BACKUP_LA_LIMIT', '8'],
        ['BACKUP_REPORT', 'daily'], ['POLICY_BACKUP_SUSPENDED_USERS', 'yes']] as $pair) {
        $this->fake->assertRan('v-change-sys-config-value', fn ($a) => $a === $pair);
    }
    $this->post('/server/backups/settings', ['mode' => 'gzip', 'level' => '9', 'temp' => '/etc', 'parallel' => '99', 'la_limit' => '0', 'report' => 'loud'])
        ->assertSessionHasErrors(['temp', 'parallel', 'la_limit', 'report']);
});
