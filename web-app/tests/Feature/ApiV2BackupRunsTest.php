<?php

use App\Arc\ArcCommandFake;

// REST API v2, backup runs (Fleet PD2;
// docs/features/specs/2026-10-19-001-fleet-pd-api-v2-follow-up-design.md §6).

/** Rows of `v-list-user-backup-runs USER json`: newest first, as the command prints them. */
function backupRunRows(string $user): array
{
    $run = fn (string $engine, string $trigger, string $task, string $status, string $start, string $end, string $exit, string $error, string $archive, string $size) => [
        'ENGINE' => $engine, 'TRIGGER' => $trigger, 'TASK' => $task, 'STATUS' => $status, 'START' => $start, 'END' => $end,
        'EXIT' => $exit, 'ERROR' => $error, 'ARCHIVE' => $archive, 'SIZE' => $size,
    ];

    return match ($user) {
        'bob' => [
            '17923000004321' => $run('tar', 'request', '17923000001111', 'running', '2026-10-18 09:00:00', '', '', '', '', ''),
            '17922000001234' => $run('restic', 'schedule', '', 'succeeded', '2026-10-17 05:10:00', '2026-10-17 05:12:00', '0', '', '4f2a91c0', ''),
            '17921000005678' => $run('tar', 'schedule', '', 'failed', '2026-10-16 05:10:00', '2026-10-16 05:10:05', '3', 'cant create tmp dir', '', ''),
            '17920000000001' => $run('tar', 'cli', '', 'succeeded', '2026-10-15 05:10:00', '2026-10-15 05:11:00', '0', '', 'bob.2026-10-15_05-11-00.tar', '12'),
        ],
        'carol' => [
            '17921500000002' => $run('tar', 'schedule', '', 'succeeded', '2026-10-16 05:20:00', '2026-10-16 05:21:00', '0', '', 'carol.2026-10-16_05-21-00.tar', '3'),
        ],
        default => [],
    };
}

function fakeBackupRuns(ArcCommandFake $fake): void
{
    $fake->on('v-list-user-backup-runs')->using(fn (array $a) => [0, (string) json_encode((object) backupRunRows($a[0]))]);
    $fake->on('v-list-sys-backup-runs')->using(function (array $args) {
        $scopes = ['' => ['arcadmin', 'bob', 'carol', 'dave', 'hostco', 'resb'], 'hostco' => ['bob', 'hostco'], 'resb' => ['carol', 'dave', 'resb']];
        $users = $scopes[$args[0]] ?? [$args[0]];
        $changed = $args[1] === '' ? $users : array_values(array_intersect($users, ['bob']));
        $map = [];
        foreach ($changed as $user) {
            $map[$user] = (object) backupRunRows($user);
        }

        return [0, (string) json_encode(['AS_OF' => '1792231200', 'USERS' => $users, 'CHANGED_USERS' => $changed, 'OBJECTS' => (object) $map])];
    });
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeBackupRuns($this->fake);
});

it('lists the runs of a user inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $this->getJson("/api/v2/users/{$owner}/backup-runs", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('answers every run newest first with its fields', function () {
    $data = $this->getJson('/api/v2/users/bob/backup-runs', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->json('data');

    expect(array_column($data, 'id'))->toBe(['17923000004321', '17922000001234', '17921000005678', '17920000000001']);
    expect($data[0])->toBe([
        'id' => '17923000004321', 'user' => 'bob', 'engine' => 'tar', 'trigger' => 'request', 'task' => 'queue:bob:17923000001111',
        'status' => 'running', 'started_at' => '2026-10-18T09:00:00', 'finished_at' => null, 'exit_code' => null, 'error' => null,
        'archive' => null, 'size_mb' => null,
    ]);
    expect($data[1])->toMatchArray(['engine' => 'restic', 'trigger' => 'schedule', 'task' => null, 'status' => 'succeeded', 'exit_code' => 0, 'archive' => '4f2a91c0', 'size_mb' => null]);
    expect($data[2])->toMatchArray(['status' => 'failed', 'exit_code' => 3, 'error' => 'cant create tmp dir', 'finished_at' => '2026-10-16T05:10:05', 'archive' => null]);
    expect($data[3])->toMatchArray(['trigger' => 'cli', 'archive' => 'bob.2026-10-15_05-11-00.tar', 'size_mb' => 12]);
    $this->fake->assertRan('v-list-user-backup-runs', fn (array $a) => $a === ['bob', 'json']);
});

it('pages the runs', function () {
    $this->getJson('/api/v2/users/bob/backup-runs?limit=2&offset=1', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', '17922000001234')
        ->assertJsonPath('meta.total', 4);
});

it('reads the runs with the backup, read and observe sets', function (array $sets) {
    $this->getJson('/api/v2/users/bob/backup-runs', apiKey($this->fake, 'bob', 'user', $sets))->assertOk();
    $this->getJson('/api/v2/backup-runs', apiKey($this->fake, 'hostco', 'reseller', $sets))->assertOk();
})->with([
    'v2-backup' => [['v2-backup']],
    'v2-read' => [['v2-read']],
    'v2-observe' => [['v2-observe']],
]);

it('refuses a key of another area', function () {
    $this->getJson('/api/v2/users/bob/backup-runs', apiAs($this->fake, 'bob', 'user', area: 'web'))->assertForbidden();
    $this->fake->assertNotRan('v-list-user-backup-runs');
});

it('lists the runs of every user for an administrator and of the tree for a reseller, newest first per user', function () {
    $all = $this->getJson('/api/v2/backup-runs', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.users', ['arcadmin', 'bob', 'carol', 'dave', 'hostco', 'resb'])
        ->json('data');
    expect(array_map(fn (array $run) => [$run['user'], $run['id']], $all))->toBe([
        ['bob', '17923000004321'], ['bob', '17922000001234'], ['bob', '17921000005678'], ['bob', '17920000000001'],
        ['carol', '17921500000002'],
    ]);
    $mine = $this->getJson('/api/v2/users/bob/backup-runs', apiAs($this->fake, 'arcadmin', 'admin'))->json('data');
    expect(array_values(array_filter($all, fn (array $run) => $run['user'] === 'bob')))->toBe($mine);
    $this->fake->assertRan('v-list-sys-backup-runs', fn (array $a) => $a === ['', '', 'json']);

    $this->getJson('/api/v2/backup-runs', apiAs($this->fake, 'resb', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.users', ['carol', 'dave', 'resb'])
        ->assertJsonPath('data.0.user', 'carol');
});

it('refuses a user key for the list of every user', function () {
    $this->getJson('/api/v2/backup-runs', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden();
    $this->fake->assertNotRan('v-list-sys-backup-runs');
});

it('passes updated_since to the command and answers the changed users', function () {
    $this->getJson('/api/v2/backup-runs?updated_since=2026-10-17T08:00:00Z', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.changed_users', ['bob'])
        ->assertJsonPath('meta.as_of', gmdate('Y-m-d\TH:i:s\Z', 1792231200));
    $this->fake->assertRan('v-list-sys-backup-runs', fn (array $a) => $a === ['hostco', (string) strtotime('2026-10-17T08:00:00Z'), 'json']);
    $this->getJson('/api/v2/backup-runs?updated_since=yesterday', apiAs($this->fake, 'arcadmin', 'admin'))->assertUnprocessable();
});
