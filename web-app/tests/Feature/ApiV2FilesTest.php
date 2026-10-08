<?php

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Services\Files\FileStaging;

// REST API v2 files (E34 batch 5 spec §4.1, §4.2, §6). hostco owns bob;
// resb owns carol and dave, who is suspended. A name starting with "new"
// does not exist until a command creates it; a name with a dot is a file,
// any other name a folder.

/** The change commands of the file endpoints. */
const API_FILE_CHANGES = [
    'v-save-fs-file', 'v-add-fs-directory', 'v-add-fs-file', 'v-move-fs-file', 'v-move-fs-directory', 'v-copy-fs-file',
    'v-copy-fs-directory', 'v-delete-fs-file', 'v-delete-fs-directory', 'v-change-fs-file-permission',
    'v-change-fs-directory-permission', 'v-add-fs-zip', 'v-extract-fs-archive',
];

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

/**
 * Every change endpoint: method, path below /files, a valid body, the change commands it plans.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: list<string>}>
 */
function apiFileWrites(): array
{
    return [
        'saveFile' => ['PUT', 'content', ['path' => 'web/index.php', 'content' => "<?php\n", 'hash' => str_repeat('a', 64)], ['v-save-fs-file']],
        'createDirectory' => ['POST', 'directories', ['path' => 'web', 'name' => 'newdir'], ['v-add-fs-directory']],
        'createFile' => ['POST', 'touch', ['path' => 'web', 'name' => 'newfile.txt'], ['v-add-fs-file']],
        'renameFile' => ['POST', 'rename', ['path' => 'web/index.php', 'name' => 'newname.php'], ['v-move-fs-file']],
        'copyFiles' => ['POST', 'copy', ['paths' => ['web/a.txt'], 'destination' => 'backup'], ['v-copy-fs-file']],
        'moveFiles' => ['POST', 'move', ['paths' => ['web/docs'], 'destination' => 'backup'], ['v-move-fs-directory']],
        'deleteFiles' => ['POST', 'delete', ['paths' => ['web/a.txt']], ['v-delete-fs-file']],
        'changeFileMode' => ['POST', 'chmod', ['paths' => ['web/a.txt'], 'mode' => '0640'], ['v-change-fs-file-permission']],
        'compressFiles' => ['POST', 'compress', ['paths' => ['web/a.txt'], 'name' => 'newpack.zip'], ['v-add-fs-zip']],
        'extractArchive' => ['POST', 'extract', ['path' => 'web/a.zip', 'destination' => 'web/newout'], ['v-add-fs-directory', 'v-extract-fs-archive']],
    ];
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake, ['dave' => ['SUSPENDED' => 'yes']]);
    $this->staging = sys_get_temp_dir().'/arc-api-files-'.bin2hex(random_bytes(4));
    mkdir($this->staging.'/uploads', 0700, true);
    mkdir($this->staging.'/downloads', 0700, true);
    config(['arc.files_staging' => $this->staging]);

    $this->fake->on('v-list-fs-directory-json')->using(fn (array $a) => [0, (string) json_encode(['TOTAL' => 3, 'OFFSET' => (int) $a[2], 'ENTRIES' => [
        apiFileRow('site', 'd', ['SIZE' => '4096', 'OWNER' => $a[0], 'GROUP' => $a[0]]),
        apiFileRow('index.php', 'f', ['OWNER' => $a[0], 'GROUP' => $a[0]]),
        apiFileRow('out', 'l', ['LINK' => '/etc', 'LINK_TYPE' => 'd', 'LINK_INSIDE' => 'no', 'OWNER' => $a[0], 'GROUP' => $a[0]]),
    ]])]);
    $made = new ArrayObject;
    $this->made = $made;
    $this->fake->on('v-get-fs-file-info')->using(function (array $a) use ($made) {
        $name = basename($a[1]);
        if (str_starts_with($name, 'new') && ! isset($made[$a[1]])) {
            return [ExitCode::NotExist->value, "Error: {$a[1]} doesn't exist"];
        }

        return [0, (string) json_encode(apiFileRow($name, str_contains($name, '.') ? 'f' : 'd', [
            'OWNER' => $a[0], 'GROUP' => $a[0], 'MIME' => str_contains($name, '.') ? 'text/plain' : 'inode/directory', 'WRITABLE' => 'yes',
        ]))];
    });
    $create = fn (int $index) => function (array $a) use ($made, $index) {
        $made[$a[$index]] = true;

        return [0, ''];
    };
    $this->fake->on('v-add-fs-directory')->using($create(1));
    $this->fake->on('v-add-fs-file')->using($create(1));
    $this->fake->on('v-add-fs-zip')->using($create(1));
    $this->fake->on('v-move-fs-file')->using($create(2));
    $this->fake->on('v-move-fs-directory')->using($create(2));
    $this->fake->on('v-extract-fs-archive')->using($create(2));
    foreach (['v-copy-fs-file', 'v-copy-fs-directory', 'v-delete-fs-file', 'v-delete-fs-directory', 'v-change-fs-file-permission', 'v-change-fs-directory-permission'] as $command) {
        $this->fake->on($command)->returns();
    }
    $this->fake->on('v-save-fs-file')->returns('{"SIZE":6,"MTIME":1759658400,"MIME":"text/x-php","HASH":"'.str_repeat('b', 64).'"}');
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
    $this->putJson('/api/v2/users/bob/files/content', ['path' => '../../etc/passwd', 'content' => 'x', 'hash' => null], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/delete', ['paths' => ['web/a.txt', '../carol/x']], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/copy', ['paths' => ['web/a.txt'], 'destination' => '../../tmp'], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/delete', ['paths' => ['.']], $headers)->assertNotFound();

    // A symbolic link that leaves the home directory: the command refuses the resolved path
    $this->fake->on('v-get-fs-file-info', fn ($a) => str_starts_with($a[1], '/home/bob/out'))->returnsExit(ExitCode::Forbidden, 'Error: invalid path /home/bob/out/passwd');
    $this->getJson('/api/v2/users/bob/files/info?path=out/passwd', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->postJson('/api/v2/users/bob/files/delete', ['paths' => ['out/passwd']], $headers)->assertNotFound();
    $this->fake->on('v-read-fs-file')->returnsExit(ExitCode::Forbidden, 'Error: invalid path /home/bob/out/passwd');
    $this->getJson('/api/v2/users/bob/files/content?path=out/passwd', $headers)->assertNotFound();

    foreach (API_FILE_CHANGES as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('answers 404 for every file path while FILE_MANAGER is off', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FILE_MANAGER' => 'false']);
    $admin = apiAs($this->fake, 'arcadmin', 'admin');

    foreach (['files', 'files/info', 'files/content?path=a', 'files/search?q=a', 'files/size'] as $uri) {
        $this->getJson('/api/v2/users/bob/'.$uri, $admin)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    }
    foreach (apiFileWrites() as [$method, $uri, $body]) {
        $this->json($method, '/api/v2/users/bob/files/'.$uri, $body, $admin)->assertNotFound();
    }
    $this->fake->assertNotRan('v-list-fs-directory-json');
    $this->fake->assertNotRan('v-get-fs-file-info');
    foreach (API_FILE_CHANGES as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('runs every endpoint with a key that holds only v2-files', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $this->fake->on('v-read-fs-file')->returns('{"SIZE":1,"MTIME":1,"MIME":"text/plain","HASH":"'.str_repeat('a', 64)."\"}\nx");
    $this->fake->on('v-list-fs-disk-usage')->returnsJson(['SIZE' => 1]);
    $this->fake->on('v-search-fs-object')->returns('');

    foreach (['files', 'files/info?path=web', 'files/content?path=web/a.txt', 'files/size?path=web', 'files/search?q=a'] as $uri) {
        $this->getJson('/api/v2/users/bob/'.$uri, $headers)->assertOk();
    }
    foreach (apiFileWrites() as $operation => [$method, $uri, $body, $commands]) {
        $status = in_array($operation, ['createDirectory', 'createFile', 'compressFiles', 'extractArchive'], true) ? 201
            : (in_array($operation, ['deleteFiles', 'changeFileMode'], true) ? 204 : 200);
        $this->json($method, '/api/v2/users/bob/files/'.$uri, $body, $headers)->assertStatus($status);
        foreach ($commands as $command) {
            $this->fake->assertRan($command);
        }
    }
});

it('refuses a v2-read key before any change command', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    foreach (apiFileWrites() as $operation => [$method, $uri, $body, $commands]) {
        $this->json($method, '/api/v2/users/bob/files/'.$uri, $body, $headers)->assertForbidden()
            ->assertJsonPath('error.code', 'E_FORBIDDEN')
            ->assertJsonPath('error.commands', $commands);
    }
    foreach (API_FILE_CHANGES as $command) {
        $this->fake->assertNotRan($command);
    }
    expect(glob($this->staging.'/uploads/*'))->toBe([]);
});

it('keeps changes on a suspended account to administrators and answers 404 outside the tree', function () {
    $this->getJson('/api/v2/users/dave/files', apiAs($this->fake, 'resb', 'reseller'))->assertOk();
    foreach (apiFileWrites() as [$method, $uri, $body]) {
        $this->json($method, '/api/v2/users/dave/files/'.$uri, $body, apiAs($this->fake, 'resb', 'reseller'))->assertForbidden();
        $this->json($method, '/api/v2/users/carol/files/'.$uri, $body, apiAs($this->fake, 'bob', 'user'))->assertNotFound();
        $this->json($method, '/api/v2/users/bob/files/'.$uri, $body, apiAs($this->fake, 'resb', 'reseller'))->assertNotFound();
    }
    foreach (API_FILE_CHANGES as $command) {
        $this->fake->assertNotRan($command);
    }

    $this->postJson('/api/v2/users/dave/files/directories', ['path' => 'web', 'name' => 'newdir'], apiAs($this->fake, 'arcadmin', 'admin'))->assertCreated();
    $this->fake->assertRan('v-add-fs-directory', fn ($a) => $a === ['dave', '/home/dave/web/newdir']);
});

it('saves the content as sent, guarded by the hash of the version read', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $hash = str_repeat('a', 64);
    $staged = null;
    $this->fake->on('v-save-fs-file')->using(function (array $a) use (&$staged) {
        $staged = $a[1];
        expect(file_get_contents($a[1]))->toBe("  <?php\r\necho 1;\n\n");

        return [0, '{"SIZE":19,"MTIME":1759658400,"MIME":"text/x-php","HASH":"'.str_repeat('b', 64).'"}'];
    });

    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/index.php', 'content' => "  <?php\r\necho 1;\n\n", 'hash' => $hash], $headers)->assertOk()
        ->assertExactJson(['data' => [
            'path' => 'web/index.php', 'hash' => str_repeat('b', 64), 'size' => 19,
            'modified_at' => date('Y-m-d\TH:i:s', 1759658400), 'mime' => 'text/x-php',
        ]]);
    $this->fake->assertRan('v-save-fs-file', fn ($a) => $a[0] === 'bob' && $a[2] === '/home/bob/web/index.php' && $a[3] === $hash);
    expect(str_starts_with((string) $staged, $this->staging.'/uploads/'))->toBeTrue()
        ->and(glob($this->staging.'/uploads/*'))->toBe([]);

    // A new file, an empty one
    $this->fake->on('v-save-fs-file')->returns('{"SIZE":0,"MTIME":1,"MIME":"inode/x-empty","HASH":"'.str_repeat('e', 64).'"}');
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/new.txt', 'content' => '', 'hash' => null], $headers)->assertOk();
    $this->fake->assertRan('v-save-fs-file', fn ($a) => $a[2] === '/home/bob/web/new.txt' && $a[3] === '');

    $this->fake->on('v-save-fs-file')->returnsExit(ExitCode::InUse, 'Error: /home/bob/web/index.php changed since it was read');
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/index.php', 'content' => 'x', 'hash' => $hash], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE')
        ->assertJsonPath('error.path', 'web/index.php')
        ->assertJsonPath('error.done', []);
    $this->fake->on('v-save-fs-file')->returnsExit(ExitCode::Exists, 'Error: /home/bob/web/index.php already exists');
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/index.php', 'content' => 'x', 'hash' => null], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->on('v-save-fs-file')->returnsExit(ExitCode::Disk, 'Error: disk quota exceeded');
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/index.php', 'content' => 'x', 'hash' => $hash], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.message', 'Disk quota exceeded.');
    expect(glob($this->staging.'/uploads/*'))->toBe([]);

    $saves = count($this->fake->calls('v-save-fs-file'));
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/big.txt', 'content' => str_repeat('é', 1048577), 'hash' => null], $headers)->assertStatus(413)
        ->assertJsonPath('error.code', 'E_LIMIT');
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/a.txt', 'content' => "a\0b", 'hash' => null], $headers)->assertUnprocessable()
        ->assertJsonPath('error.fields.content.0', 'NUL bytes are not allowed here.');
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/a.txt', 'content' => 'x'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['hash']]]);
    $this->putJson('/api/v2/users/bob/files/content', ['path' => 'web/a.txt', 'content' => 'x', 'hash' => 'nothex'], $headers)->assertUnprocessable();
    $this->putJson('/api/v2/users/bob/files/content', ['path' => "web/a\nb", 'content' => 'x', 'hash' => null], $headers)->assertUnprocessable();
    $this->putJson('/api/v2/users/bob/files/content', ['path' => '.', 'content' => 'x', 'hash' => null], $headers)->assertNotFound();
    expect($this->fake->calls('v-save-fs-file'))->toHaveCount($saves);
});

it('creates folders and files and refuses names that exist', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $response = $this->postJson('/api/v2/users/bob/files/directories', ['path' => 'web', 'name' => 'newdir'], $headers)->assertCreated()
        ->assertJsonPath('data.path', 'web/newdir')
        ->assertJsonPath('data.type', 'directory');
    expect($response->headers->get('Location'))->toEndWith('/api/v2/users/bob/files/info?path=web%2Fnewdir');
    $this->fake->assertRan('v-add-fs-directory', fn ($a) => $a === ['bob', '/home/bob/web/newdir']);
    $this->postJson('/api/v2/users/bob/files/touch', ['name' => 'newfile.txt'], $headers)->assertCreated()
        ->assertJsonPath('data.path', 'newfile.txt')
        ->assertJsonPath('data.type', 'file');
    $this->fake->assertRan('v-add-fs-file', fn ($a) => $a === ['bob', '/home/bob/newfile.txt']);

    $this->postJson('/api/v2/users/bob/files/touch', ['path' => 'web', 'name' => 'index.php'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.conflicts', ['index.php']);
    $this->postJson('/api/v2/users/bob/files/directories?dry_run=1', ['path' => 'web', 'name' => 'site'], $headers)->assertStatus(409)
        ->assertJsonPath('error.conflicts', ['site']);
    foreach (['a/b', '..', '.'] as $name) {
        $this->postJson('/api/v2/users/bob/files/directories', ['path' => 'web', 'name' => $name], $headers)->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['name']]]);
    }
    $this->fake->assertRan('v-add-fs-directory', times: 1);
    $this->fake->assertRan('v-add-fs-file', times: 1);
});

it('renames, copies and moves without overwriting unless asked', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->postJson('/api/v2/users/bob/files/rename', ['path' => 'web/a.txt', 'name' => 'newname.txt'], $headers)->assertOk()
        ->assertJsonPath('data.path', 'web/newname.txt');
    $this->fake->assertRan('v-move-fs-file', fn ($a) => $a === ['bob', '/home/bob/web/a.txt', '/home/bob/web/newname.txt']);
    $this->postJson('/api/v2/users/bob/files/rename', ['path' => 'web/a.txt', 'name' => 'index.php'], $headers)->assertStatus(409)
        ->assertJsonPath('error.conflicts', ['index.php']);

    // index.php and site exist in every folder of the fake
    $this->postJson('/api/v2/users/bob/files/copy', ['paths' => ['web/a.txt', 'app/index.php', 'app/site'], 'destination' => 'backup'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.conflicts', ['index.php', 'site']);
    $this->fake->assertNotRan('v-copy-fs-file');

    $this->postJson('/api/v2/users/bob/files/copy', ['paths' => ['web/a.txt', 'app/index.php'], 'destination' => 'backup', 'on_conflict' => 'skip'], $headers)->assertOk()
        ->assertExactJson(['data' => ['destination' => 'backup', 'names' => ['a.txt']]]);
    $this->fake->assertRan('v-copy-fs-file', fn ($a) => $a === ['bob', '/home/bob/web/a.txt', '/home/bob/backup/a.txt']);
    $this->postJson('/api/v2/users/bob/files/copy', ['paths' => ['app/index.php'], 'destination' => 'backup', 'on_conflict' => 'rename'], $headers)->assertOk()
        ->assertJsonPath('data.names', ['index (1).php']);
    $this->fake->assertRan('v-copy-fs-file', fn ($a) => $a === ['bob', '/home/bob/app/index.php', '/home/bob/backup/index (1).php']);
    $this->postJson('/api/v2/users/bob/files/copy', ['paths' => ['app/site'], 'destination' => 'backup', 'on_conflict' => 'overwrite'], $headers)->assertOk();
    $this->fake->assertRan('v-delete-fs-directory', fn ($a) => $a === ['bob', '/home/bob/backup/site']);
    $this->fake->assertRan('v-copy-fs-directory', fn ($a) => $a === ['bob', '/home/bob/app/site', '/home/bob/backup/site']);

    $this->postJson('/api/v2/users/bob/files/move', ['paths' => ['web/docs', 'web/b.txt'], 'destination' => ''], $headers)->assertOk()
        ->assertJsonPath('data', ['destination' => '', 'names' => ['docs', 'b.txt']]);
    $this->fake->assertRan('v-move-fs-directory', fn ($a) => $a === ['bob', '/home/bob/web/docs', '/home/bob/docs']);
    $this->fake->assertRan('v-move-fs-file', fn ($a) => $a === ['bob', '/home/bob/web/b.txt', '/home/bob/b.txt']);
    $this->postJson('/api/v2/users/bob/files/move', ['paths' => ['web'], 'destination' => 'web/site'], $headers)->assertNotFound();
    $this->postJson('/api/v2/users/bob/files/move', ['paths' => ['web/a.txt'], 'destination' => 'x', 'on_conflict' => 'merge'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/move', ['paths' => [], 'destination' => 'x'], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/move', ['paths' => ['web/a.txt']], $headers)->assertUnprocessable();
});

it('keeps white space in paths and names', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->getJson('/api/v2/users/bob/files/info?path='.rawurlencode('web/ a.txt '), $headers)->assertOk()
        ->assertJsonPath('data.path', 'web/ a.txt ');
    $this->fake->assertRan('v-get-fs-file-info', fn ($a) => $a === ['bob', '/home/bob/web/ a.txt ', 'json']);
    $this->postJson('/api/v2/users/bob/files/delete', ['paths' => ['web/a.txt ']], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-fs-file', fn ($a) => $a === ['bob', '/home/bob/web/a.txt ']);
    $this->fake->assertRan('v-delete-fs-file', fn ($a) => $a === ['bob', '/home/bob/web/a.txt'], times: 0);
    $this->postJson('/api/v2/users/bob/files/rename', ['path' => 'web/a.txt', 'name' => 'newname.txt '], $headers)->assertOk()
        ->assertJsonPath('data.path', 'web/newname.txt ');
});

it('stops a change of several paths at the first failure and names what was done', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');
    $this->fake->on('v-delete-fs-file', fn ($a) => $a[1] === '/home/bob/web/b.txt')->returnsExit(ExitCode::Forbidden, 'Error: /home/bob/web/b.txt is not writable');

    $this->postJson('/api/v2/users/bob/files/delete', ['paths' => ['web/a.txt', 'web/b.txt', 'web/c.txt']], $headers)->assertForbidden()
        ->assertExactJson(['error' => [
            'code' => 'E_FORBIDDEN',
            'message' => '/home/bob/web/b.txt is not writable',
            'step' => ['label' => 'Delete web/b.txt', 'command' => 'v-delete-fs-file'],
            'applied' => [['label' => 'Delete web/a.txt', 'command' => 'v-delete-fs-file']],
            'path' => 'web/b.txt',
            'done' => ['web/a.txt'],
        ]]);
    $this->fake->assertNotRan('v-delete-fs-directory');
    $this->fake->assertRan('v-delete-fs-file', fn ($a) => $a[1] === '/home/bob/web/c.txt', times: 0);

    // An overwrite deletes the target, then copies: a path is done after both
    $this->fake->on('v-copy-fs-file', fn ($a) => $a[1] === '/home/bob/app/index.php')->returnsExit(ExitCode::Disk, 'Error: disk quota exceeded');
    $this->postJson('/api/v2/users/bob/files/copy', ['paths' => ['web/a.txt', 'app/index.php'], 'destination' => 'backup', 'on_conflict' => 'overwrite'], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_LIMIT')
        ->assertJsonPath('error.path', 'app/index.php')
        ->assertJsonPath('error.done', ['web/a.txt'])
        ->assertJsonPath('error.applied.1.command', 'v-delete-fs-file');
});

it('changes modes of files and folders', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->postJson('/api/v2/users/bob/files/chmod', ['paths' => ['web/index.php', 'web/site'], 'mode' => '0640', 'files_mode' => '0600', 'recursive' => true], $headers)->assertNoContent();
    $this->fake->assertRan('v-change-fs-file-permission', fn ($a) => $a === ['bob', '/home/bob/web/index.php', '0640']);
    $this->fake->assertRan('v-change-fs-directory-permission', fn ($a) => $a === ['bob', '/home/bob/web/site', '0640', '0600', 'yes']);
    $this->postJson('/api/v2/users/bob/files/chmod', ['paths' => ['web/site'], 'mode' => '755'], $headers)->assertNoContent();
    $this->fake->assertRan('v-change-fs-directory-permission', fn ($a) => $a === ['bob', '/home/bob/web/site', '755', '755', 'no']);
    foreach ([['mode' => '999'], ['mode' => '0644; rm'], ['mode' => '0644', 'files_mode' => 'rw'], ['mode' => '0644', 'recursive' => 'deep']] as $body) {
        $this->postJson('/api/v2/users/bob/files/chmod', ['paths' => ['web/site'], ...$body], $headers)->assertUnprocessable();
    }
});

it('compresses into a new zip and extracts into a new folder only', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $response = $this->postJson('/api/v2/users/bob/files/compress', ['paths' => ['web/index.php', 'web/site'], 'name' => 'newpack.zip'], $headers)->assertCreated()
        ->assertJsonPath('data.path', 'web/newpack.zip')
        ->assertJsonPath('data.type', 'file');
    expect($response->headers->get('Location'))->toEndWith('/files/info?path=web%2Fnewpack.zip');
    $this->fake->assertRan('v-add-fs-zip', fn ($a) => $a === ['bob', '/home/bob/web/newpack.zip', '/home/bob/web/index.php', '/home/bob/web/site']);
    $this->postJson('/api/v2/users/bob/files/compress', ['paths' => ['web/index.php'], 'name' => 'pack.zip'], $headers)->assertStatus(409)
        ->assertJsonPath('error.conflicts', ['pack.zip']);
    $this->postJson('/api/v2/users/bob/files/compress', ['paths' => ['web/a.txt', 'app/b.txt'], 'name' => 'newx.zip'], $headers)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['paths']]]);
    $this->postJson('/api/v2/users/bob/files/compress', ['paths' => ['web/a.txt'], 'name' => 'newx.tar.gz'], $headers)->assertUnprocessable();

    $response = $this->postJson('/api/v2/users/bob/files/extract', ['path' => 'web/a.tar.gz', 'destination' => 'web/newout/newdeep'], $headers)->assertCreated()
        ->assertJsonPath('data.path', 'web/newout/newdeep')
        ->assertJsonPath('data.type', 'directory');
    expect($response->headers->get('Location'))->toEndWith('/files/info?path=web%2Fnewout%2Fnewdeep');
    expect(array_column($this->fake->calls(), 'command'))->toContain('v-add-fs-directory', 'v-extract-fs-archive');
    $calls = array_values(array_filter($this->fake->calls(), fn ($c) => in_array($c['command'], ['v-add-fs-directory', 'v-extract-fs-archive'], true)));
    expect($calls)->toBe([
        ['command' => 'v-add-fs-directory', 'args' => ['bob', '/home/bob/web/newout/newdeep']],
        ['command' => 'v-extract-fs-archive', 'args' => ['bob', '/home/bob/web/a.tar.gz', '/home/bob/web/newout/newdeep']],
    ]);

    // Never into an existing folder; single-file archives and other formats are refused
    $this->postJson('/api/v2/users/bob/files/extract', ['path' => 'web/a.zip', 'destination' => 'web/site'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.conflicts', ['site']);
    foreach (['web/a.gz', 'web/a.bz2', 'web/a.zst', 'web/a.rar', 'web/a.txt'] as $archive) {
        $this->postJson('/api/v2/users/bob/files/extract', ['path' => $archive, 'destination' => 'web/newx'], $headers)->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['path']]]);
    }
    $this->postJson('/api/v2/users/bob/files/extract', ['path' => 'web/a.zip', 'destination' => ''], $headers)->assertUnprocessable();
    $this->postJson('/api/v2/users/bob/files/extract', ['path' => 'web/a.zip', 'destination' => '.'], $headers)->assertUnprocessable();
    $this->fake->assertRan('v-extract-fs-archive', times: 1);
});

it('plans without running and reports conflicts in a dry run', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'files');

    $this->postJson('/api/v2/users/bob/files/extract?dry_run=1', ['path' => 'web/a.zip', 'destination' => 'web/newout'], $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0.command', 'v-add-fs-directory')
        ->assertJsonPath('data.steps.0.args', ['bob', '/home/bob/web/newout'])
        ->assertJsonPath('data.steps.1.command', 'v-extract-fs-archive')
        ->assertJsonPath('data.steps.1.args', ['bob', '/home/bob/web/a.zip', '/home/bob/web/newout']);
    $this->postJson('/api/v2/users/bob/files/copy?dry_run=1', ['paths' => ['app/site', 'web/a.txt'], 'destination' => 'backup', 'on_conflict' => 'overwrite'], $headers)->assertOk()
        ->assertJsonPath('data.steps.*.command', ['v-delete-fs-directory', 'v-copy-fs-directory', 'v-copy-fs-file']);
    $this->postJson('/api/v2/users/bob/files/copy?dry_run=1', ['paths' => ['app/site'], 'destination' => 'backup'], $headers)->assertStatus(409)
        ->assertJsonPath('error.conflicts', ['site']);
    $this->putJson('/api/v2/users/bob/files/content?dry_run=1', ['path' => 'web/index.php', 'content' => 'x', 'hash' => null], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-save-fs-file')
        ->assertJsonPath('data.steps.0.args.2', '/home/bob/web/index.php');
    $this->postJson('/api/v2/users/bob/files/delete?dry_run=1', ['paths' => ['web/site', 'web/a.txt']], $headers)->assertOk()
        ->assertJsonPath('data.steps.*.command', ['v-delete-fs-directory', 'v-delete-fs-file']);
    $this->postJson('/api/v2/users/bob/files/chmod?dry_run=1', ['paths' => ['web/a.txt'], 'mode' => '0600'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', '/home/bob/web/a.txt', '0600']);

    // The conflict-finding reads ran; no change did, and nothing was staged
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a[1] === '/home/bob/backup');
    foreach (API_FILE_CHANGES as $command) {
        $this->fake->assertNotRan($command);
    }
    expect(glob($this->staging.'/uploads/*'))->toBe([]);
});
