<?php

use App\Arc\ExitCode;

// Upload from URL in the file manager
// (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §6.1):
// hostco owns bob; resb owns carol and dave, who is suspended.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake, ['dave' => ['SUSPENDED' => 'yes']]);
    $this->fake->on('v-list-fs-fetches')->using(fn (array $args) => [0, (string) json_encode([
        '17611234560042' => [
            'ID' => '17611234560042', 'URL' => 'https://a.example/f.zip?…', 'DIR' => "/home/{$args[0]}/tmp", 'NAME' => 'f.zip',
            'ON_CONFLICT' => 'rename', 'STATUS' => 'running', 'BYTES' => '10', 'TOTAL' => '40', 'ERROR' => '', 'HTTP' => '200',
            'CREATED' => '2026-10-22 10:00:00', 'START' => '2026-10-22 10:00:01', 'END' => '',
        ],
    ])]);
});

/** Every fetch route: method, URI below /files/{user}/api, needs write access */
function fetchRoutes(): array
{
    return [['GET', 'fetches', false], ['POST', 'fetches', true], ['DELETE', 'fetches/17611234560042', true]];
}

it('lists the fetches of an account with the folder relative to the home', function () {
    signIn('bob', 'user');

    $this->getJson('/files/bob/api/fetches')->assertOk()
        ->assertJsonPath('active', 1)
        ->assertJsonPath('fetches.0.id', '17611234560042')
        ->assertJsonPath('fetches.0.path', 'tmp')
        ->assertJsonPath('fetches.0.percent', 25)
        ->assertJsonPath('fetches.0.url', 'https://a.example/f.zip?…');
});

it('starts a simple fetch with the request in a secret file', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-fs-fetch')->returns("17611234560099\n");

    $this->postJson('/files/bob/api/fetches', ['mode' => 'simple', 'path' => 'tmp', 'url' => 'https://a.example/f.zip?sig=s3cret'])
        ->assertCreated()->assertJsonPath('id', '17611234560099')->assertJsonPath('fetches.0.id', '17611234560042');
    $this->fake->assertRan('v-add-fs-fetch', fn ($a) => $a === ['bob', '/home/bob/tmp', "url https://a.example/f.zip?sig=s3cret\n", 'rename', 'no']);
});

it('starts a custom fetch with headers, authentication, a name and a conflict mode', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-fs-fetch')->returns("17611234560099\n");

    $this->postJson('/files/bob/api/fetches', [
        'mode' => 'custom', 'path' => '', 'url' => 'https://a.example/f', 'name' => 'g.bin', 'onConflict' => 'overwrite',
        'headers' => [['name' => 'X-Key', 'value' => 'k']], 'auth' => ['type' => 'bearer', 'token' => 'tok'],
    ])->assertCreated();
    $this->fake->assertRan('v-add-fs-fetch', fn ($a) => $a === ['bob', '/home/bob', "url https://a.example/f\nname g.bin\nheader X-Key: k\nbearer tok\n", 'overwrite', 'no']);
});

it('starts a fetch from a curl command and answers its refusals on the command field', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-fs-fetch')->returns("17611234560099\n");

    $this->postJson('/files/bob/api/fetches', ['mode' => 'curl', 'command' => "curl 'https://a.example/f' -H 'X-A: 1' -u al:pw"])->assertCreated();
    $this->fake->assertRan('v-add-fs-fetch', fn ($a) => $a[2] === "url https://a.example/f\nheader X-A: 1\nbasic al:pw\n");

    $this->postJson('/files/bob/api/fetches', ['mode' => 'curl', 'command' => 'curl -d x=1 https://a.example/'])
        ->assertUnprocessable()->assertJsonValidationErrors(['command']);
    $this->postJson('/files/bob/api/fetches', ['mode' => 'curl', 'command' => 'curl ftp://a.example/'])
        ->assertUnprocessable()->assertJsonValidationErrors(['command']);
    $this->postJson('/files/bob/api/fetches', ['mode' => 'simple', 'url' => 'file:///etc/passwd'])
        ->assertUnprocessable()->assertJsonValidationErrors(['url']);
    $this->postJson('/files/bob/api/fetches', ['mode' => 'ftp', 'url' => 'https://a.example/'])
        ->assertUnprocessable()->assertJsonValidationErrors(['mode']);
    $this->fake->assertRan('v-add-fs-fetch', times: 1);
});

it('lets only administrators allow private addresses', function () {
    $this->fake->on('v-add-fs-fetch')->returns("17611234560099\n");
    $body = ['mode' => 'simple', 'url' => 'http://10.0.0.5/f', 'allowPrivate' => true];

    signIn('bob', 'user');
    $this->postJson('/files/bob/api/fetches', $body)->assertUnprocessable()->assertJsonValidationErrors(['allowPrivate']);
    signIn('hostco', 'reseller');
    $this->postJson('/files/bob/api/fetches', $body)->assertUnprocessable();
    $this->fake->assertNotRan('v-add-fs-fetch');

    signIn('arcadmin', 'admin');
    $this->postJson('/files/bob/api/fetches', $body)->assertCreated();
    $this->fake->assertRan('v-add-fs-fetch', fn ($a) => $a[0] === 'bob' && $a[4] === 'yes');
});

it('answers the command refusals: the limit, a read-only folder', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-fs-fetch', fn ($a) => $a[1] === '/home/bob/tmp')->returnsExit(ExitCode::Limit, 'bob already has 20 downloads from URLs waiting or running');
    $this->fake->on('v-add-fs-fetch', fn ($a) => $a[1] === '/home/bob')->returnsExit(ExitCode::Forbidden, '/home/bob is read-only');

    $this->postJson('/files/bob/api/fetches', ['mode' => 'simple', 'path' => 'tmp', 'url' => 'https://a.example/'])
        ->assertStatus(429)->assertJsonPath('error.code', 'E_LIMIT');
    $this->postJson('/files/bob/api/fetches', ['mode' => 'simple', 'url' => 'https://a.example/'])->assertForbidden();
});

it('cancels or removes a fetch and answers 404 for an unknown one', function () {
    signIn('bob', 'user');
    $this->fake->on('v-delete-fs-fetch')->returns();

    $this->deleteJson('/files/bob/api/fetches/17611234560042')->assertOk()->assertJsonPath('fetches.0.id', '17611234560042');
    $this->fake->assertRan('v-delete-fs-fetch', fn ($a) => $a === ['bob', '17611234560042']);
    $this->deleteJson('/files/bob/api/fetches/17611234560000')->assertNotFound();
    $this->deleteJson('/files/bob/api/fetches/active')->assertNotFound();
    $this->fake->assertRan('v-delete-fs-fetch', times: 1);
});

it('answers 404 outside the tree, 403 on a suspended account for writes, admin may write', function () {
    signIn('bob', 'user');
    foreach (fetchRoutes() as [$method, $uri]) {
        $this->json($method, '/files/carol/api/'.$uri, ['mode' => 'simple', 'url' => 'https://a.example/'])->assertNotFound();
    }

    signIn('hostco', 'reseller');
    $this->getJson('/files/bob/api/fetches')->assertOk();
    $this->getJson('/files/carol/api/fetches')->assertNotFound();

    signIn('resb', 'reseller');
    $this->getJson('/files/dave/api/fetches')->assertOk();
    foreach (array_filter(fetchRoutes(), fn ($r) => $r[2]) as [$method, $uri]) {
        $this->json($method, '/files/dave/api/'.$uri, ['mode' => 'simple', 'url' => 'https://a.example/'])->assertForbidden();
    }
    $this->fake->assertNotRan('v-add-fs-fetch');

    signIn('arcadmin', 'admin');
    $this->fake->on('v-add-fs-fetch')->returns("17611234560099\n");
    $this->postJson('/files/dave/api/fetches', ['mode' => 'simple', 'url' => 'https://a.example/'])->assertCreated();
});

it('refuses every change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeResellers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->getJson('/files/bob/api/fetches')->assertOk();
    $this->postJson('/files/bob/api/fetches', ['mode' => 'simple', 'url' => 'https://a.example/'])->assertForbidden();
    $this->deleteJson('/files/bob/api/fetches/17611234560042')->assertForbidden();
});

it('answers 404 when FILE_FETCH is no or the file manager is off', function () {
    signIn('bob', 'user');
    withSysConfig($this->fake, ['FILE_FETCH' => 'no']);
    foreach (fetchRoutes() as [$method, $uri]) {
        $this->json($method, '/files/bob/api/'.$uri)->assertNotFound();
    }
    withSysConfig($this->fake, ['FILE_MANAGER' => 'false']);
    $this->getJson('/files/bob/api/fetches')->assertNotFound();
    $this->fake->assertNotRan('v-list-fs-fetches');
});

it('passes the fetch settings to the island', function () {
    $this->fake->on('v-list-fs-directory-json')->returnsJson(['TOTAL' => 0, 'OFFSET' => 0, 'ENTRIES' => []]);
    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => '', 'TYPE' => 'd', 'MODE' => '0755', 'WRITABLE' => 'yes', 'MIME' => 'inode/directory']);
    withSysConfig($this->fake, ['FILE_FETCH_MAX_SIZE' => '1G']);

    signIn('bob', 'user');
    $this->get('/files/bob')->assertOk()->assertViewHas('props', fn ($p) => $p['fetch'] === ['enabled' => true, 'maxBytes' => 1073741824, 'private' => false]);
    signIn('arcadmin', 'admin');
    $this->get('/files/bob')->assertOk()->assertViewHas('props', fn ($p) => $p['fetch']['private'] === true);
});

it('tells the island when FILE_FETCH is no', function () {
    $this->fake->on('v-list-fs-directory-json')->returnsJson(['TOTAL' => 0, 'OFFSET' => 0, 'ENTRIES' => []]);
    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => '', 'TYPE' => 'd', 'MODE' => '0755', 'WRITABLE' => 'yes', 'MIME' => 'inode/directory']);
    withSysConfig($this->fake, ['FILE_FETCH' => 'no']);
    signIn('bob', 'user');

    $this->get('/files/bob')->assertOk()->assertViewHas('props', fn ($p) => $p['fetch']['enabled'] === false);
});
