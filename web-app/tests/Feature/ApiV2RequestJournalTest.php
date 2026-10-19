<?php

use App\Api\RequestJournal;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

// REST API v2 request journal and getRequest (E43 M4, B4):
// docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §7.
// bob has cron job 1; a new job gets id 3.

const JOURNAL_ID = 'fleet-op-0001';

/** @return list<array<string, mixed>> the decoded lines of the journal of $day, without broken ones */
function journalLines(string $day = '2026-10-17'): array
{
    $file = config('arc.request_journal').'/'.$day.'.jsonl';
    if (! is_file($file)) {
        return [];
    }

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), is_array(...)));
}

/** A start line without its end: a request that is still running or never finished. */
function journalStart(string $id, string $user, string $startedAt, string $day = '2026-10-17'): void
{
    File::ensureDirectoryExists(config('arc.request_journal'));
    file_put_contents(config('arc.request_journal').'/'.$day.'.jsonl', json_encode([
        'entry' => 'e-'.$id.'-'.$startedAt, 'id' => $id, 'key' => API_KEY_ID, 'user' => $user, 'operation' => 'createCronJob',
        'method' => 'POST', 'path' => '/api/v2/users/'.$user.'/cron-jobs', 'started_at' => $startedAt,
    ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

function journalCreateJob(array $headers, string $id = JOURNAL_ID): TestResponse
{
    return test()->postJson('/api/v2/users/bob/cron-jobs', ['schedule' => '*/5 * * * *', 'command' => 'echo hi'], $headers + ['X-Request-Id' => $id]);
}

beforeEach(function () {
    $this->journal = sys_get_temp_dir().'/arc-journal-'.bin2hex(random_bytes(6));
    config(['arc.request_journal' => $this->journal]);
    $this->travelTo(new DateTimeImmutable('2026-10-17 10:00:00', new DateTimeZone('UTC')));
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeCronJobs($this->fake);
    $this->fake->on('v-add-cron-job')->using(function () {
        // The start line is written before the controller runs the command
        $this->linesDuringCommand = count(journalLines());

        return [0, ''];
    });
    $this->fake->on('v-list-cron-job', ['bob', '3', 'json'])->returnsJson(['3' => [
        'JOB' => '3', 'MIN' => '*/5', 'HOUR' => '*', 'DAY' => '*', 'MONTH' => '*', 'WDAY' => '*',
        'CMD' => 'echo hi', 'SUSPENDED' => 'no', 'DATE' => '2026-10-17', 'TIME' => '10:00:00',
    ]]);
});

afterEach(function () {
    File::deleteDirectory($this->journal);
});

it('records a write in two lines, the start before the command and the end after the answer', function () {
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();

    expect($this->linesDuringCommand)->toBe(1);
    [$start, $end] = journalLines();
    expect(array_keys($start))->toBe(['entry', 'id', 'key', 'user', 'operation', 'method', 'path', 'started_at'])
        ->and($start)->toMatchArray([
            'id' => JOURNAL_ID, 'key' => API_KEY_ID, 'user' => 'bob', 'operation' => 'createCronJob',
            'method' => 'POST', 'path' => '/api/v2/users/bob/cron-jobs', 'started_at' => '2026-10-17T10:00:00Z',
        ])
        ->and(array_keys($end))->toBe(['entry', 'id', 'status', 'error_code', 'task_id', 'location', 'finished_at'])
        ->and($end)->toMatchArray([
            'entry' => $start['entry'], 'id' => JOURNAL_ID, 'status' => 201, 'error_code' => null, 'task_id' => null,
            'location' => 'http://localhost/api/v2/users/bob/cron-jobs/3', 'finished_at' => '2026-10-17T10:00:00Z',
        ]);
    // Metadata only: no request body
    expect((string) file_get_contents($this->journal.'/2026-10-17.jsonl'))->not->toContain('echo hi');
});

it('records the error code of a failed write', function () {
    $this->postJson('/api/v2/users/bob/cron-jobs', [
        'schedule' => '0 * * * *', 'command' => 'php /home/bob/web/example.com/public_html/cron.php',
    ], apiAs($this->fake, 'bob', 'user', area: 'cron') + ['X-Request-Id' => JOURNAL_ID])->assertStatus(409);
    $this->postJson('/api/v2/users/bob/cron-jobs', ['schedule' => '@daily', 'command' => 'echo hi'], apiAs($this->fake, 'bob', 'user', area: 'cron'))
        ->assertUnprocessable();

    $lines = journalLines();
    expect($lines)->toHaveCount(4)
        ->and($lines[1])->toMatchArray(['status' => 409, 'error_code' => 'E_EXISTS', 'location' => null])
        ->and($lines[3])->toMatchArray(['status' => 422, 'error_code' => 'E_VALIDATION']);
});

it('records the task of a 202 answer', function () {
    fakeBackupData($this->fake);
    $this->fake->on('v-schedule-user-backup', ['bob', 'json'])->returnsJson(['ID' => '17290000009001']);

    $response = $this->postJson('/api/v2/users/bob/backups', [], apiAs($this->fake, 'bob', 'user', area: 'backup'))->assertStatus(202)->assertDocumentedHeaders('createBackup');

    expect(journalLines()[1])->toMatchArray([
        'status' => 202, 'error_code' => null, 'task_id' => 'queue:bob:17290000009001', 'location' => $response->headers->get('Location'),
    ])->and(journalLines()[0]['operation'])->toBe('createBackup');
});

it('records no read, no dry run and no request without a valid key', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'cron');

    $this->getJson('/api/v2/users/bob/cron-jobs', $headers)->assertOk();
    $this->postJson('/api/v2/users/bob/cron-jobs?dry_run=1', ['schedule' => '*/5 * * * *', 'command' => 'echo hi'], $headers)->assertOk();
    $this->postJson('/api/v2/users/bob/cron-jobs', ['schedule' => '*/5 * * * *', 'command' => 'echo hi'], ['Authorization' => 'Bearer '.API_KEY_ID.':wrong', 'Accept' => 'application/json'])
        ->assertUnauthorized();

    expect(journalLines())->toBe([]);
});

it('keeps a write running when the client goes away', function () {
    ignore_user_abort(false);

    $this->getJson('/api/v2/users/bob/cron-jobs', apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertOk();
    expect((bool) ignore_user_abort())->toBeFalse();
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();
    expect((bool) ignore_user_abort())->toBeTrue();
    ignore_user_abort(false);
});

it('records the id of the X-Request-Id answer header, a new one for an invalid client id', function () {
    $response = journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'), 'not a valid id!')->assertCreated();

    $id = $response->headers->get('X-Request-Id');
    expect($id)->not->toBe('not a valid id!')
        ->and(journalLines()[0]['id'])->toBe($id)
        ->and(journalLines()[1]['id'])->toBe($id);
});

it('keeps the journal readable by the panel web user only', function () {
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();

    expect(fileperms($this->journal) & 0777)->toBe(0700)
        ->and(fileperms($this->journal.'/2026-10-17.jsonl') & 0777)->toBe(0600);
})->skipOnWindows();

it('answers a finished request with getRequest', function () {
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();
    $this->travel(2)->minutes();

    $this->getJson('/api/v2/requests/'.JOURNAL_ID, apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => JOURNAL_ID, 'operation' => 'createCronJob', 'method' => 'POST', 'path' => '/api/v2/users/bob/cron-jobs',
                'state' => 'finished', 'status' => 201, 'error_code' => null, 'task_id' => null,
                'location' => 'http://localhost/api/v2/users/bob/cron-jobs/3',
                'started_at' => '2026-10-17T10:00:00Z', 'finished_at' => '2026-10-17T10:00:00Z',
            ],
            'meta' => ['duplicates' => 1],
        ]);
    // getRequest is a read: it records nothing
    expect(journalLines())->toHaveCount(2);
});

it('answers running within 15 minutes of the start and unknown after', function () {
    journalStart(JOURNAL_ID, 'bob', '2026-10-17T09:50:00Z');
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/requests/'.JOURNAL_ID, $headers)->assertOk()
        ->assertJsonPath('data.state', 'running')
        ->assertJsonPath('data.status', null)
        ->assertJsonPath('data.finished_at', null);
    $this->travelTo(new DateTimeImmutable('2026-10-17 10:05:01', new DateTimeZone('UTC')));
    $this->getJson('/api/v2/requests/'.JOURNAL_ID, $headers)->assertOk()
        ->assertJsonPath('data.state', 'unknown')
        ->assertJsonPath('data.status', null);
});

it('shows a request to the keys of its user and to administrators only', function (string $principal, string $role, ?string $area, int $status) {
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();

    $headers = $area === null ? apiAs($this->fake, $principal, $role, readOnly: true) : apiKey($this->fake, $principal, $role, [$area]);
    $response = $this->getJson('/api/v2/requests/'.JOURNAL_ID, $headers)->assertStatus($status);
    if ($status === 404) {
        $response->assertJsonPath('error.code', 'E_NOTEXIST');
    }
})->with([
    'the same user' => ['bob', 'user', null, 200],
    'the same user, a key of another area' => ['bob', 'user', 'v2-dns', 200],
    'admin' => ['arcadmin', 'admin', null, 200],
    'admin, an observe key' => ['arcadmin', 'admin', 'v2-observe', 200],
    'reseller A, owner of bob' => ['hostco', 'reseller', null, 404],
    'reseller B' => ['resb', 'reseller', null, 404],
    'another user' => ['carol', 'user', null, 404],
]);

it('answers 404 for an unknown id and for a request older than the retention', function () {
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/requests/fleet-op-9999', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->travelTo(new DateTimeImmutable('2026-10-24 09:59:59', new DateTimeZone('UTC')));
    $this->getJson('/api/v2/requests/'.JOURNAL_ID, $headers)->assertOk();
    $this->travelTo(new DateTimeImmutable('2026-10-24 10:00:01', new DateTimeZone('UTC')));
    $this->getJson('/api/v2/requests/'.JOURNAL_ID, $headers)->assertNotFound();
});

it('returns the latest request of an id with the number of visible requests that share it', function () {
    journalStart(JOURNAL_ID, 'bob', '2026-10-16T08:00:00Z', '2026-10-16');
    journalStart(JOURNAL_ID, 'carol', '2026-10-17T09:00:00Z');
    $this->travel(1)->minutes();
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();

    $this->getJson('/api/v2/requests/'.JOURNAL_ID, apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.state', 'finished')
        ->assertJsonPath('data.started_at', '2026-10-17T10:01:00Z')
        ->assertJsonPath('meta.duplicates', 2);
    $this->getJson('/api/v2/requests/'.JOURNAL_ID, apiAs($this->fake, 'arcadmin', 'admin', readOnly: true))->assertOk()
        ->assertJsonPath('meta.duplicates', 3);
    $this->getJson('/api/v2/requests/'.JOURNAL_ID, apiAs($this->fake, 'carol', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.state', 'unknown')
        ->assertJsonPath('meta.duplicates', 1);
});

it('refuses an id that cannot be a request id', function () {
    $this->getJson('/api/v2/requests/'.str_repeat('a', 129), apiAs($this->fake, 'bob', 'user', readOnly: true))->assertNotFound();
});

it('ignores a broken line of the journal', function () {
    File::ensureDirectoryExists($this->journal);
    file_put_contents($this->journal.'/2026-10-17.jsonl', "{broken\n");
    journalCreateJob(apiAs($this->fake, 'bob', 'user', area: 'cron'))->assertCreated();

    $this->getJson('/api/v2/requests/'.JOURNAL_ID, apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.status', 201);
    expect(RequestJournal::RETENTION_DAYS)->toBe(7)->and(RequestJournal::RUNNING_MINUTES)->toBe(15);
});
