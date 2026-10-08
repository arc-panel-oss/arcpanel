<?php

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Services\Files\FileStaging;

// REST API v2 file reads (E34 batch 5 spec §4.1, §6). hostco owns bob;
// resb owns carol and dave. A name with a dot is a file, any other name a
// folder.

/**
 * One entry as `v-list-fs-directory-json` and `v-get-fs-file-info` print it.
 *
 * @param  array<string, string|int>  $overrides
 * @return array<string, string|int>
 */
function apiFileRow(string $name, string $type, array $overrides = []): array
{
    return array_merge([
        'NAME' => $name, 'TYPE' => $type, 'SIZE' => '12', 'MODE' => $type === 'd' ? '0755' : '0644', 'OWNER' => 'bob', 'GROUP' => 'bob',
        'MTIME' => 1759658400, 'LINK' => '', 'LINK_TYPE' => '', 'LINK_INSIDE' => '', 'VALID_NAME' => 'yes',
    ], $overrides);
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->staging = sys_get_temp_dir().'/arc-api-files-'.bin2hex(random_bytes(4));
    mkdir($this->staging.'/uploads', 0700, true);
    mkdir($this->staging.'/downloads', 0700, true);
    config(['arc.files_staging' => $this->staging]);

    $this->fake->on('v-list-fs-directory-json')->using(fn (array $a) => [0, (string) json_encode(['TOTAL' => 3, 'OFFSET' => (int) $a[2], 'ENTRIES' => [
        apiFileRow('site', 'd', ['SIZE' => '4096', 'OWNER' => $a[0], 'GROUP' => $a[0]]),
        apiFileRow('index.php', 'f', ['OWNER' => $a[0], 'GROUP' => $a[0]]),
        apiFileRow('out', 'l', ['LINK' => '/etc', 'LINK_TYPE' => 'd', 'LINK_INSIDE' => 'no', 'OWNER' => $a[0], 'GROUP' => $a[0]]),
    ]])]);
    $this->fake->on('v-get-fs-file-info')->using(function (array $a) {
        $name = basename($a[1]);

        return [0, (string) json_encode(apiFileRow($name, str_contains($name, '.') ? 'f' : 'd', [
            'OWNER' => $a[0], 'GROUP' => $a[0], 'MIME' => str_contains($name, '.') ? 'text/plain' : 'inode/directory', 'WRITABLE' => 'yes',
        ]))];
    });
});

afterEach(function () {
    FileStaging::removeTree($this->staging);
});

it('lists a folder inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/files?path=web", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status !== 200) {
        $response->assertJsonPath('error.code', 'E_NOTEXIST');

        return;
    }
    $response->assertJsonPath('meta', ['total' => 3])
        ->assertJsonPath('data.0', [
            'name' => 'site', 'path' => 'web/site', 'type' => 'directory', 'size' => 4096, 'mode' => '0755', 'owner' => $owner, 'group' => $owner,
            'modified_at' => date('Y-m-d\TH:i:s', 1759658400), 'link_target' => null, 'link_type' => null, 'link_inside' => false,
            'mime' => null, 'writable' => null, 'valid_name' => true,
        ])
        ->assertJsonPath('data.1.type', 'file')
        ->assertJsonPath('data.2.type', 'link')
        ->assertJsonPath('data.2.link_target', '/etc')
        ->assertJsonPath('data.2.link_type', 'd')
        ->assertJsonPath('data.2.link_inside', false);
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a === [$owner, "/home/{$owner}/web", '0', '5000', 'name', 'asc', 'yes']);
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

it('pages, sorts and hides through the command, and reads a large folder in pages of 5000', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/files?path=web&limit=2&offset=1&sort=size&order=desc&hidden=false', $headers)->assertOk()
        ->assertJsonPath('meta', ['total' => 3, 'limit' => 2, 'offset' => 1]);
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a === ['bob', '/home/bob/web', '1', '2', 'size', 'desc', 'no']);
    foreach (['limit=1001', 'limit=0', 'offset=-1', 'sort=color', 'order=up', 'hidden=maybe'] as $query) {
        $this->getJson('/api/v2/users/bob/files?'.$query, $headers)->assertUnprocessable()->assertJsonPath('error.code', 'E_VALIDATION');
    }

    $this->fake->on('v-list-fs-directory-json')->using(fn (array $a) => [0, (string) json_encode(['TOTAL' => 3, 'OFFSET' => (int) $a[2],
        'ENTRIES' => $a[2] === '0' ? [apiFileRow('a.txt', 'f'), apiFileRow('b.txt', 'f')] : [apiFileRow('c.txt', 'f')]])]);
    $this->getJson('/api/v2/users/bob/files', $headers)->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.*.path', ['a.txt', 'b.txt', 'c.txt']);
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a === ['bob', '/home/bob', '2', '5000', 'name', 'asc', 'yes']);
});

it('reads an entry, a text file, a folder size and a name search', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->getJson('/api/v2/users/bob/files/info?path=web/index.php', $headers)->assertOk()
        ->assertJsonPath('data.path', 'web/index.php')
        ->assertJsonPath('data.type', 'file')
        ->assertJsonPath('data.mime', 'text/plain')
        ->assertJsonPath('data.writable', true);
    $this->getJson('/api/v2/users/bob/files/info', $headers)->assertOk()
        ->assertJsonPath('data.path', '')
        ->assertJsonPath('data.type', 'directory');
    $this->fake->assertRan('v-get-fs-file-info', fn ($a) => $a === ['bob', '/home/bob', 'json']);

    $hash = str_repeat('c', 64);
    $this->fake->on('v-read-fs-file')->returns('{"SIZE":13,"MTIME":1759658400,"MIME":"text/x-php","HASH":"'.$hash."\"}\n<?php\r\necho 1;\n");
    $this->getJson('/api/v2/users/bob/files/content?path=web/index.php', $headers)->assertOk()
        ->assertExactJson(['data' => [
            'path' => 'web/index.php', 'content' => "<?php\r\necho 1;\n", 'hash' => $hash, 'size' => 13,
            'modified_at' => date('Y-m-d\TH:i:s', 1759658400), 'mime' => 'text/x-php', 'utf8' => true,
        ]]);
    $this->fake->assertRan('v-read-fs-file', fn ($a) => $a === ['bob', '/home/bob/web/index.php', '2097152']);
    $this->fake->on('v-read-fs-file')->returns('{"SIZE":2,"MTIME":1,"MIME":"application/octet-stream","HASH":"'.$hash."\"}\n\xff\xfe");
    $this->getJson('/api/v2/users/bob/files/content?path=web/bin.dat', $headers)->assertOk()
        ->assertJsonPath('data.utf8', false);
    $this->fake->on('v-read-fs-file')->returnsExit(ExitCode::Limit, 'Error: larger than 2097152 bytes');
    $this->getJson('/api/v2/users/bob/files/content?path=web/big.log', $headers)->assertStatus(413)
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'larger than 2097152 bytes');
    $this->getJson('/api/v2/users/bob/files/content', $headers)->assertUnprocessable();

    $this->fake->on('v-list-fs-disk-usage')->returnsJson(['SIZE' => 123456]);
    $this->getJson('/api/v2/users/bob/files/size?path=web', $headers)->assertOk()
        ->assertExactJson(['data' => ['path' => 'web', 'size' => 123456]]);
    $this->fake->on('v-list-fs-disk-usage')->returnsExit(ExitCode::Limit, 'Error: counting the size of /home/bob/web took too long');
    $this->getJson('/api/v2/users/bob/files/size?path=web', $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT');

    $this->fake->on('v-search-fs-object')->returns("f|644|2026-10-05|10:00|bob|bob|12|site/index.php\nd|755|2026-10-05|10:00|bob|bob|4096|old-index\n");
    $this->getJson('/api/v2/users/bob/files/search?path=web&q=index', $headers)->assertOk()
        ->assertJsonPath('data', [
            ['name' => 'index.php', 'path' => 'web/site/index.php', 'type' => 'file', 'size' => 12],
            ['name' => 'old-index', 'path' => 'web/old-index', 'type' => 'directory', 'size' => 4096],
        ])
        ->assertJsonPath('meta.truncated', false);
    $this->fake->assertRan('v-search-fs-object', fn ($a) => $a === ['bob', '*index*', '/home/bob/web']);
    $this->getJson('/api/v2/users/bob/files/search?q=*.php', $headers)->assertOk();
    $this->fake->assertRan('v-search-fs-object', fn ($a) => $a === ['bob', '*.php', '/home/bob']);
    $this->fake->on('v-search-fs-object')->returns(implode("\n", array_map(fn ($i) => "f|644|2026-10-05|10:00|bob|bob|1|f{$i}.txt", range(1, 501))));
    $this->getJson('/api/v2/users/bob/files/search?q=f', $headers)->assertOk()
        ->assertJsonCount(500, 'data')
        ->assertJsonPath('meta.truncated', true);
    $this->fake->on('v-search-fs-object')->using(fn () => throw ArcCommandException::refused('v-search-fs-object', 'timed out after 10 s'));
    $this->getJson('/api/v2/users/bob/files/search?q=x', $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT');
    foreach (['q=a/b', 'q=', 'q='.str_repeat('a', 101)] as $query) {
        $this->getJson('/api/v2/users/bob/files/search?'.$query, $headers)->assertUnprocessable()->assertJsonPath('error.code', 'E_VALIDATION');
    }
});

it('answers 404 for paths that leave the home directory', function () {
    $headers = apiAs($this->fake, 'bob', 'user');

    foreach (['files?path=../carol', 'files/info?path=../../etc/passwd', 'files/content?path=../../etc/passwd', 'files/content?path=/etc/passwd', 'files/size?path=..'] as $uri) {
        $this->getJson('/api/v2/users/bob/'.$uri, $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    }

    // A symbolic link that leaves the home directory: the command refuses the resolved path
    $this->fake->on('v-get-fs-file-info', fn ($a) => str_starts_with($a[1], '/home/bob/out'))->returnsExit(ExitCode::Forbidden, 'Error: invalid path /home/bob/out/passwd');
    $this->getJson('/api/v2/users/bob/files/info?path=out/passwd', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->fake->on('v-read-fs-file')->returnsExit(ExitCode::Forbidden, 'Error: invalid path /home/bob/out/passwd');
    $this->getJson('/api/v2/users/bob/files/content?path=out/passwd', $headers)->assertNotFound();
});

it('answers 404 for every file path while FILE_MANAGER is off', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_MANAGER' => 'false']);
    $admin = apiAs($this->fake, 'arcadmin', 'admin');

    foreach (['files', 'files/info', 'files/content?path=a', 'files/search?q=a', 'files/size'] as $uri) {
        $this->getJson('/api/v2/users/bob/'.$uri, $admin)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    }
    $this->fake->assertNotRan('v-list-fs-directory-json');
    $this->fake->assertNotRan('v-get-fs-file-info');
});

it('runs every read with a key that holds only v2-files', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $this->fake->on('v-read-fs-file')->returns('{"SIZE":1,"MTIME":1,"MIME":"text/plain","HASH":"'.str_repeat('a', 64)."\"}\nx");
    $this->fake->on('v-list-fs-disk-usage')->returnsJson(['SIZE' => 1]);
    $this->fake->on('v-search-fs-object')->returns('');

    foreach (['files', 'files/info?path=web', 'files/content?path=web/a.txt', 'files/size?path=web', 'files/search?q=a'] as $uri) {
        $this->getJson('/api/v2/users/bob/'.$uri, $headers)->assertOk();
    }
});

it('lets a reseller read the files of a suspended customer', function () {
    fakeResellers($this->fake, ['dave' => ['SUSPENDED' => 'yes']]);

    $this->getJson('/api/v2/users/dave/files', apiAs($this->fake, 'resb', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('meta.total', 3);
});
