<?php

use App\Arc\ExitCode;

// REST API v2 upload from URL
// (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §8).
// hostco owns bob; resb owns carol and dave, who is suspended.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['dave' => ['SUSPENDED' => 'yes']]);
    $this->records = new ArrayObject([
        '17611234560042' => [
            'ID' => '17611234560042', 'URL' => 'https://a.example/f.zip?…', 'DIR' => '/home/bob/web', 'NAME' => 'f.zip',
            'ON_CONFLICT' => 'rename', 'STATUS' => 'running', 'BYTES' => '10', 'TOTAL' => '40', 'ERROR' => '', 'HTTP' => '200',
            'CREATED' => '2026-10-22 10:00:00', 'START' => '2026-10-22 10:00:01', 'END' => '',
        ],
    ]);
    $this->fake->on('v-list-fs-fetches')->using(fn (array $a) => [0, (string) json_encode($this->records->getArrayCopy())]);
    $this->fake->on('v-add-fs-fetch')->using(function (array $a) {
        $this->records['17611234560099'] = [
            'ID' => '17611234560099', 'URL' => 'https://a.example/g', 'DIR' => $a[1], 'NAME' => '', 'STATUS' => 'queued',
            'BYTES' => '0', 'TOTAL' => '', 'ERROR' => '', 'HTTP' => '', 'CREATED' => '2026-10-22 11:00:00', 'START' => '', 'END' => '',
        ];

        return [0, "17611234560099\n"];
    });
    $this->fake->on('v-delete-fs-fetch')->using(function (array $a) {
        $this->records[$a[1]] = ['STATUS' => 'cancelled', 'END' => '2026-10-22 11:00:05'] + $this->records[$a[1]];

        return [0, ''];
    });
});

it('lists and shows downloads with a key holding only v2-files', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->getJson('/api/v2/users/bob/files/fetches', $headers)->assertOk()
        ->assertJsonPath('data.0.id', '17611234560042')
        ->assertJsonPath('data.0.path', 'web')
        ->assertJsonPath('data.0.status', 'running')
        ->assertJsonPath('data.0.percent', 25)
        ->assertJsonPath('data.0.http_status', 200)
        ->assertJsonPath('data.0.error', null)
        ->assertJsonPath('data.0.started_at', '2026-10-22T10:00:01')
        ->assertJsonPath('data.0.ended_at', null);
    $this->getJson('/api/v2/users/bob/files/fetches/17611234560042', $headers)->assertOk()->assertJsonPath('data.name', 'f.zip');
    $this->getJson('/api/v2/users/bob/files/fetches/17611234560000', $headers)->assertNotFound();
});

it('starts a download with headers and credentials passed only in the request file', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->postJson('/api/v2/users/bob/files/fetches', [
        'url' => 'https://a.example/g?sig=s3cret', 'path' => 'web', 'name' => 'g.bin', 'on_conflict' => 'skip',
        'headers' => [['name' => 'X-Key', 'value' => 'hk']], 'auth' => ['type' => 'basic', 'username' => 'al', 'password' => 'pw'],
    ], $headers)->assertCreated()
        ->assertHeader('Location', url('/api/v2/users/bob/files/fetches/17611234560099'))
        ->assertJsonPath('data.id', '17611234560099')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonMissingPath('data.headers');
    $this->fake->assertRan('v-add-fs-fetch', fn ($a) => $a === ['bob', '/home/bob/web', "url https://a.example/g?sig=s3cret\nname g.bin\nheader X-Key: hk\nbasic al:pw\n", 'skip', 'no']);
});

it('plans a start and a cancel in a dry run without secrets, running nothing', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    $response = $this->postJson('/api/v2/users/bob/files/fetches?dry_run=1', ['url' => 'https://a.example/g', 'auth' => ['type' => 'bearer', 'token' => 'T0KEN']], $headers)
        ->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.steps.0.command', 'v-add-fs-fetch');
    expect($response->getContent())->not->toContain('T0KEN');
    $this->deleteJson('/api/v2/users/bob/files/fetches/17611234560042?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-fs-fetch');
    $this->fake->assertNotRan('v-add-fs-fetch');
    $this->fake->assertNotRan('v-delete-fs-fetch');
});

it('refuses a v2-read key before any command, and lets it list', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/files/fetches', $headers)->assertOk();
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'https://a.example/g'], $headers)->assertForbidden()
        ->assertJsonPath('error.commands', ['v-add-fs-fetch']);
    $this->deleteJson('/api/v2/users/bob/files/fetches/17611234560042', [], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-add-fs-fetch');
    $this->fake->assertNotRan('v-delete-fs-fetch');
});

it('cancels a download and answers it as it ended', function () {
    $this->deleteJson('/api/v2/users/bob/files/fetches/17611234560042', [], apiAs($this->fake, 'bob', 'user'))->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.ended_at', '2026-10-22T11:00:05');
    $this->fake->assertRan('v-delete-fs-fetch', fn ($a) => $a === ['bob', '17611234560042']);
});

it('answers downloads inside the tree and 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->getJson("/api/v2/users/{$owner}/files/fetches", $headers)->assertStatus($status);
    $this->postJson("/api/v2/users/{$owner}/files/fetches", ['url' => 'https://a.example/g'], $headers)->assertStatus($status === 200 ? 201 : 404);
    $this->deleteJson("/api/v2/users/{$owner}/files/fetches/17611234560042", [], $headers)->assertStatus($status);
    if ($status === 404) {
        $this->fake->assertNotRan('v-add-fs-fetch');
        $this->fake->assertNotRan('v-delete-fs-fetch');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, unknown' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, other tree' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, carol' => ['resb', 'reseller', 'carol', 200],
    'reseller B, other tree' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, other user' => ['bob', 'user', 'carol', 404],
]);

it('keeps downloads into a suspended account to administrators', function () {
    $this->postJson('/api/v2/users/dave/files/fetches', ['url' => 'https://a.example/g'], apiAs($this->fake, 'resb', 'reseller'))->assertForbidden();
    $this->getJson('/api/v2/users/dave/files/fetches', apiAs($this->fake, 'resb', 'reseller'))->assertOk();
    $this->postJson('/api/v2/users/dave/files/fetches', ['url' => 'https://a.example/g'], apiAs($this->fake, 'arcadmin', 'admin'))->assertCreated();
});

it('lets only administrator keys allow private addresses', function () {
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'http://10.0.0.5/f', 'allow_private' => true], apiAs($this->fake, 'hostco', 'reseller'))
        ->assertUnprocessable();
    $this->fake->assertNotRan('v-add-fs-fetch');
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'http://10.0.0.5/f', 'allow_private' => true], apiAs($this->fake, 'arcadmin', 'admin'))
        ->assertCreated();
    $this->fake->assertRan('v-add-fs-fetch', fn ($a) => $a[4] === 'yes');
});

it('refuses bad requests and answers the limit of the command', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'ftp://a.example/'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'https://a.example/', 'headers' => [['name' => 'Host', 'value' => 'b']]], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'https://a.example/', 'auth' => ['type' => 'digest']], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'https://a.example/', 'on_conflict' => 'fail'], $headers)->assertUnprocessable();
    $this->fake->assertNotRan('v-add-fs-fetch');

    $this->fake->on('v-add-fs-fetch')->returnsExit(ExitCode::Limit, 'bob already has 20 downloads from URLs waiting or running');
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'https://a.example/'], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT');
});

it('answers 404 for every download route while FILE_FETCH is no', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_FETCH' => 'no']);
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->getJson('/api/v2/users/bob/files/fetches', $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/fetches', ['url' => 'https://a.example/'], $headers)->assertNotFound();
    $this->fake->assertNotRan('v-list-fs-fetches');
});
