<?php

use App\Arc\ExitCode;

// REST API v2, backup destinations, jobs and their runs (E48 spec §9). The
// fake keeps the destinations and jobs like the commands do.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->dests = backupDestinations();
    $this->jobs = backupJobs();
    $this->fake->on('v-list-backup-destinations', ['json'])->using(fn () => [0, (string) json_encode($this->dests)]);
    $this->fake->on('v-list-backup-jobs', ['json'])->using(fn () => [0, (string) json_encode($this->jobs)]);
    $this->fake->on('v-list-backup-job-runs')->returnsJson(backupJobRuns());
    $this->fake->on('v-add-backup-destination')->using(function (array $a) {
        $this->dests[$a[0]] = ['NAME' => $a[0], 'DRIVER' => $a[1], 'STATE' => 'unchecked', 'SECRETS' => implode(',', array_keys(json_decode($a[3] ?? '{}', true))), 'JOBS' => '',
            'CREATED' => '2026-10-22 22:00:00'];
        foreach (explode("\n", trim($a[2])) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $this->dests[$a[0]][$key] = trim($value, "'");
        }

        return [0, ''];
    });
    $this->fake->on('v-add-backup-job')->using(function (array $a) {
        $this->jobs['179270000012'] = ['ID' => '179270000012', 'NAME' => $a[0], 'ENGINE' => $a[1], 'SCOPE' => $a[2], 'SCHEDULE' => $a[3], 'TARGETS' => $a[4],
            'EXCLUDE_USERS' => $a[5], 'STATE' => $a[6], 'NEXT_RUN' => '2026-10-23 02:30:00', 'LAST_STATUS' => '', 'RUNNING' => 'no'];

        return [0, "179270000012\n"];
    });
    foreach (['v-change-backup-destination', 'v-delete-backup-destination', 'v-change-backup-job', 'v-delete-backup-job', 'v-suspend-backup-job',
        'v-unsuspend-backup-job', 'v-start-backup-job'] as $command) {
        $this->fake->on($command)->returns();
    }
    $this->fake->on('v-check-backup-destination')->returnsJson(['offsite' => ['OK' => 'no', 'ERROR' => '',
        'HOST_KEY' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'LATENCY_MS' => '']]);
});

/** Every write endpoint with a valid body. */
function backupApiWrites(): array
{
    return [
        ['post', '/api/v2/system/backup-destinations', ['name' => 'box', 'driver' => 'local', 'fields' => ['PATH' => '/backup/box']]],
        ['patch', '/api/v2/system/backup-destinations/offsite', ['fields' => ['DESCRIPTION' => 'Box']]],
        ['delete', '/api/v2/system/backup-destinations/offsite', []],
        ['post', '/api/v2/system/backup-destinations/offsite/check', []],
        ['post', '/api/v2/system/backup-jobs', ['name' => 'Night', 'engine' => 'archive', 'schedule' => 'daily:02:30', 'targets' => [['destination' => 'local']]]],
        ['patch', '/api/v2/system/backup-jobs/179267621466', ['name' => 'Nights']],
        ['delete', '/api/v2/system/backup-jobs/179267621466', []],
        ['post', '/api/v2/system/backup-jobs/179267621466/run', []],
    ];
}

const BACKUP_API_COMMANDS = ['v-add-backup-destination', 'v-change-backup-destination', 'v-delete-backup-destination', 'v-check-backup-destination',
    'v-add-backup-job', 'v-change-backup-job', 'v-delete-backup-job', 'v-suspend-backup-job', 'v-unsuspend-backup-job', 'v-start-backup-job'];

it('lists destinations, jobs and runs for administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $headers = apiAs($this->fake, $principal, $role, readOnly: true);

    $destinations = $this->getJson('/api/v2/system/backup-destinations', $headers)->assertStatus($status);
    $jobs = $this->getJson('/api/v2/system/backup-jobs', $headers)->assertStatus($status);
    $runs = $this->getJson('/api/v2/system/backup-job-runs', $headers)->assertStatus($status);
    $this->getJson('/api/v2/system/backup-destinations/offsite', $headers)->assertStatus($status);
    $this->getJson('/api/v2/system/backup-jobs/179267621466', $headers)->assertStatus($status);
    $this->getJson('/api/v2/system/backup-job-runs/17926000000001', $headers)->assertStatus($status);
    if ($status !== 200) {
        return;
    }
    $destinations->assertJsonPath('meta.total', 2)->assertJsonPath('data.*.name', ['local', 'offsite'])
        ->assertJsonPath('data.1', [
            'name' => 'offsite', 'driver' => 'sftp', 'description' => 'Storage box', 'encrypt' => true, 'bwlimit' => '10M',
            'fields' => ['HOST' => 'box.example.net', 'PORT' => '23', 'USERNAME' => 'u123', 'PATH' => 'arc'], 'secrets' => ['PASSWORD'],
            'state' => 'failing', 'checked_at' => '2026-10-22T21:00:00', 'host_key' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
            'recovery_kit_exported_at' => null, 'jobs' => [], 'created_at' => '2026-10-22T20:40:00',
        ]);
    $jobs->assertJsonPath('meta.total', 1)->assertJsonPath('data.0', [
        'id' => '179267621466', 'name' => 'Nightly archives', 'engine' => 'archive', 'enabled' => true, 'schedule' => 'daily:05:10',
        'schedule_text' => 'Daily at 05:10', 'scope' => 'all', 'packages' => [], 'users' => [], 'exclude_users' => ['carol'],
        'targets' => [['destination' => 'local', 'keep_last' => null, 'daily' => null, 'weekly' => null, 'monthly' => null, 'yearly' => null]],
        'next_run_at' => '2026-10-23T05:10:00', 'last_status' => 'partial', 'last_finished_at' => '2026-10-22T21:23:26', 'running' => false,
    ]);
    $runs->assertJsonPath('meta.total', 2)->assertJsonPath('data.1', [
        'id' => '17926000000001', 'job' => '179267621466', 'job_name' => 'Nightly archives', 'trigger' => 'cli', 'status' => 'success',
        'started_at' => '2026-10-01T05:10:00', 'finished_at' => '2026-10-01T05:12:00', 'users' => 2, 'done' => 2, 'failed' => 0, 'skipped' => 0,
    ]);
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every write to non-administrators before any command', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);
    foreach (backupApiWrites() as [$method, $uri, $body]) {
        $this->json($method, $uri, $body, $headers)->assertForbidden();
    }
    foreach (BACKUP_API_COMMANDS as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('refuses every write to a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'read');
    foreach (backupApiWrites() as [$method, $uri, $body]) {
        $this->json($method, $uri, $body, $headers)->assertForbidden();
    }
    foreach (BACKUP_API_COMMANDS as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('runs nothing on a dry run', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');
    foreach (backupApiWrites() as [$method, $uri, $body]) {
        $this->json($method, $uri.'?dry_run=1', $body, $headers)->assertOk()->assertJsonPath('data.dry_run', true);
    }
    foreach (BACKUP_API_COMMANDS as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('answers 404 for an unknown destination, job or run', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/system/backup-destinations/nosuch', $headers)->assertNotFound();
    $this->patchJson('/api/v2/system/backup-destinations/nosuch', ['fields' => ['DESCRIPTION' => 'x']], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/system/backup-destinations/nosuch', [], $headers)->assertNotFound();
    $this->postJson('/api/v2/system/backup-destinations/nosuch/check', [], $headers)->assertNotFound();
    $this->getJson('/api/v2/system/backup-jobs/179200000000', $headers)->assertNotFound();
    $this->patchJson('/api/v2/system/backup-jobs/179200000000', ['name' => 'x'], $headers)->assertNotFound();
    $this->postJson('/api/v2/system/backup-jobs/179200000000/run', [], $headers)->assertNotFound();
    $this->getJson('/api/v2/system/backup-job-runs/17920000000000', $headers)->assertNotFound();
    foreach (BACKUP_API_COMMANDS as $command) {
        $this->fake->assertNotRan($command);
    }
});

// Destinations

it('adds a destination with a key holding only v2-server, its secrets write-only', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----";

    $this->postJson('/api/v2/system/backup-destinations', ['name' => 'box', 'driver' => 'sftp',
        'fields' => ['HOST' => 'box.example.net', 'USERNAME' => 'u123', 'PORT' => '23', 'PATH' => 'arc', 'ENCRYPT' => 'yes'],
        'secrets' => ['PRIVATE_KEY' => $key, 'PASSPHRASE' => 'Key-pass-1']], $headers)
        ->assertCreated()
        ->assertHeader('Location', route('api.v2.system.backup-destinations.show', ['destination' => 'box']))
        ->assertJsonPath('data.name', 'box')->assertJsonPath('data.state', 'unchecked')->assertJsonPath('data.encrypt', true)
        ->assertJsonPath('data.secrets', ['PRIVATE_KEY', 'PASSPHRASE'])
        ->assertDontSee('Key-pass-1')->assertDontSee('b3BlbnNzaC1rZXktdjEAAAAA');
    $this->fake->assertRan('v-add-backup-destination', fn ($a) => $a[0] === 'box' && $a[1] === 'sftp'
        && str_contains($a[2], "HOST='box.example.net'\n") && str_contains($a[2], "ENCRYPT='yes'\n")
        && json_decode($a[3], true) === ['PRIVATE_KEY' => $key."\n", 'PASSPHRASE' => 'Key-pass-1']);
});

it('refuses a name in use, bad fields, missing credentials and line breaks', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/backup-destinations', ['name' => 'offsite', 'driver' => 'local', 'fields' => ['PATH' => '/backup/x']], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS')->assertJsonPath('error.existing', '/api/v2/system/backup-destinations/offsite');
    $this->postJson('/api/v2/system/backup-destinations', ['name' => 'Box!', 'driver' => 'sftp', 'fields' => ['HOST' => 'bad host', 'PORT' => '99999']], $headers)
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'fields.HOST', 'fields.PORT', 'fields.USERNAME', 'secrets.PASSWORD'], 'error.fields');
    $this->postJson('/api/v2/system/backup-destinations', ['name' => 'loc', 'driver' => 'local', 'fields' => ['PATH' => '/etc/backup']], $headers)
        ->assertStatus(422)->assertJsonValidationErrors(['fields.PATH'], 'error.fields');
    $this->postJson('/api/v2/system/backup-destinations', ['name' => 'ftp1', 'driver' => 'ftp', 'fields' => ['HOST' => 'ftp.example.net', 'USERNAME' => 'u1'],
        'secrets' => ['PASSWORD' => "pass\nword"]], $headers)->assertStatus(422)->assertJsonValidationErrors(['secrets.PASSWORD'], 'error.fields');
    $this->postJson('/api/v2/system/backup-destinations', ['name' => 'nope', 'driver' => 'gopher'], $headers)
        ->assertStatus(422)->assertJsonValidationErrors(['driver'], 'error.fields');
    $this->fake->assertNotRan('v-add-backup-destination');
});

it('updates a destination: fields left out keep their value, secrets left out stay stored', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson('/api/v2/system/backup-destinations/offsite', ['fields' => ['DESCRIPTION' => 'Second box', 'HOST' => 'box2.example.net']], $headers)
        ->assertOk()->assertJsonPath('data.name', 'offsite');
    $this->fake->assertRan('v-change-backup-destination', fn ($a) => count($a) === 2 && $a[0] === 'offsite'
        && str_contains($a[1], "HOST='box2.example.net'\n") && str_contains($a[1], "USERNAME='u123'\n") && str_contains($a[1], "PORT='23'\n")
        && str_contains($a[1], "DESCRIPTION='Second box'\n") && str_contains($a[1], "ENCRYPT='yes'\n") && str_contains($a[1], "BWLIMIT='10M'\n"));

    $this->patchJson('/api/v2/system/backup-destinations/offsite', ['secrets' => ['PASSWORD' => 'New-pass-1']], $headers)->assertOk();
    $this->fake->assertRan('v-change-backup-destination', fn ($a) => count($a) === 3 && json_decode($a[2], true) === ['PASSWORD' => 'New-pass-1']);

    // Nothing changes: nothing runs; a required field cannot be emptied
    $this->patchJson('/api/v2/system/backup-destinations/offsite?dry_run=1', ['fields' => ['HOST' => 'box.example.net']], $headers)
        ->assertOk()->assertJsonPath('data.steps', []);
    $this->patchJson('/api/v2/system/backup-destinations/offsite', ['fields' => ['HOST' => '']], $headers)
        ->assertStatus(422)->assertJsonValidationErrors(['fields.HOST'], 'error.fields');
    $this->fake->assertRan('v-change-backup-destination', null, 2);
});

it('deletes a destination unless a job still uses it', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->deleteJson('/api/v2/system/backup-destinations/offsite', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-backup-destination', fn ($a) => $a === ['offsite']);

    $this->fake->on('v-delete-backup-destination')->returnsExit(ExitCode::InUse, 'Error: backup destination local is used by the jobs 179267621466');
    $this->deleteJson('/api/v2/system/backup-destinations/local', [], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
});

it('tests a destination and pins the host key the administrator confirms', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/backup-destinations/offsite/check', [], $headers)->assertOk()
        ->assertJsonPath('data', ['ok' => false, 'error' => null, 'host_key' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'latency_ms' => null]);
    $this->fake->assertRan('v-check-backup-destination', fn ($a) => $a === ['offsite', '', 'json']);

    $this->fake->on('v-check-backup-destination')->returnsJson(['offsite' => ['OK' => 'yes', 'ERROR' => '', 'HOST_KEY' => '', 'LATENCY_MS' => '40']]);
    $this->postJson('/api/v2/system/backup-destinations/offsite/check', ['accept' => 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG'], $headers)->assertOk()
        ->assertJsonPath('data.ok', true)->assertJsonPath('data.latency_ms', 40);
    $this->fake->assertRan('v-check-backup-destination', fn ($a) => $a === ['offsite', 'SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'json']);

    $this->postJson('/api/v2/system/backup-destinations/offsite/check', ['accept' => 'yes please'], $headers)->assertStatus(422);
    $this->fake->assertRan('v-check-backup-destination', null, 2);
});

// Jobs

it('adds a job with a key holding only v2-server and answers its new ID', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/backup-jobs', ['name' => 'Weekdays', 'engine' => 'snapshot', 'schedule' => 'cron:30   2 * * 1-5', 'scope' => 'packages',
        'packages' => ['gold', 'gold'], 'exclude_users' => ['carol'], 'targets' => [['destination' => 'offsite', 'keep_last' => 24, 'daily' => 7]], 'enabled' => false], $headers)
        ->assertCreated()
        ->assertHeader('Location', route('api.v2.system.backup-jobs.show', ['job' => '179270000012']))
        ->assertJsonPath('data.id', '179270000012')->assertJsonPath('data.enabled', false)->assertJsonPath('data.scope', 'packages')
        ->assertJsonPath('data.packages', ['gold'])->assertJsonPath('data.schedule', 'cron:30 2 * * 1-5')
        ->assertJsonPath('data.targets.0', ['destination' => 'offsite', 'keep_last' => 24, 'daily' => 7, 'weekly' => null, 'monthly' => null, 'yearly' => null]);
    $this->fake->assertRan('v-add-backup-job', fn ($a) => $a === ['Weekdays', 'snapshot', 'packages:gold', 'cron:30 2 * * 1-5', 'offsite:24:7:-1:-1:-1', 'carol', 'paused']);
});

it('refuses a job without targets, with a bad schedule or a snapshot job that keeps nothing', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $job = ['name' => 'Night', 'engine' => 'archive', 'schedule' => 'daily:02:30', 'targets' => [['destination' => 'local']]];

    $this->postJson('/api/v2/system/backup-jobs', ['targets' => []] + $job, $headers)->assertStatus(422)->assertJsonValidationErrors(['targets'], 'error.fields');
    foreach (['daily:25:00', 'weekly:8:01:00', 'monthly:31:01:00', 'cron:rm -rf', 'yearly'] as $schedule) {
        $this->postJson('/api/v2/system/backup-jobs', ['schedule' => $schedule] + $job, $headers)->assertStatus(422)->assertJsonValidationErrors(['schedule'], 'error.fields');
    }
    $this->postJson('/api/v2/system/backup-jobs', ['engine' => 'snapshot'] + $job, $headers)->assertStatus(422)->assertJsonValidationErrors(['targets.0.keep_last'], 'error.fields');
    $this->postJson('/api/v2/system/backup-jobs', ['scope' => 'users'] + $job, $headers)->assertStatus(422)->assertJsonValidationErrors(['users'], 'error.fields');
    $this->fake->assertNotRan('v-add-backup-job');
});

it('updates a job keeping what is left out, and pauses or resumes it', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson('/api/v2/system/backup-jobs/179267621466', ['name' => 'Nights', 'scope' => 'users', 'users' => ['bob']], $headers)->assertOk();
    $this->fake->assertRan('v-change-backup-job', fn ($a) => $a === ['179267621466', 'Nights', 'archive', 'users:bob', 'daily:05:10', 'local:-1:-1:-1:-1:-1', '']);
    $this->fake->assertNotRan('v-suspend-backup-job');

    $this->patchJson('/api/v2/system/backup-jobs/179267621466', ['enabled' => false], $headers)->assertOk();
    $this->fake->assertRan('v-suspend-backup-job', fn ($a) => $a === ['179267621466']);
    $this->fake->assertRan('v-change-backup-job', null, 1);

    // Nothing changes: nothing runs
    $this->patchJson('/api/v2/system/backup-jobs/179267621466?dry_run=1', ['name' => 'Nightly archives', 'enabled' => true], $headers)
        ->assertOk()->assertJsonPath('data.steps', []);
});

it('deletes a job and refuses a running one', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->deleteJson('/api/v2/system/backup-jobs/179267621466', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-backup-job', fn ($a) => $a === ['179267621466']);

    $this->fake->on('v-delete-backup-job')->returnsExit(ExitCode::InUse, 'Error: backup job 179267621466 is running');
    $this->deleteJson('/api/v2/system/backup-jobs/179267621466', [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
});

it('runs a job now and refuses a job that runs already', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/backup-jobs/179267621466/run', [], $headers)->assertStatus(202)
        ->assertHeader('Content-Location', route('api.v2.system.backup-job-runs.index', ['job' => '179267621466']))
        ->assertJsonPath('data.id', '179267621466');
    $this->fake->assertRan('v-start-backup-job', fn ($a) => $a === ['179267621466']);

    $this->jobs['179267621466']['RUNNING'] = 'yes';
    $this->postJson('/api/v2/system/backup-jobs/179267621466/run', [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
    $this->fake->assertRan('v-start-backup-job', null, 1);
});

// Runs

it('filters the runs by job and state', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/backup-job-runs?job=179267621466&status=partial', $headers)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'partial')->assertJsonPath('data.0.skipped', 1);
    $this->fake->assertRan('v-list-backup-job-runs', fn ($a) => $a[0] === '179267621466');
    $this->getJson('/api/v2/system/backup-job-runs?status=nope', $headers)->assertStatus(422);
    $this->getJson('/api/v2/system/backup-job-runs?job=../x', $headers)->assertStatus(422);
});
