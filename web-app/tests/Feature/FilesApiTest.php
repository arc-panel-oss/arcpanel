<?php

use App\Arc\ExitCode;
use App\Arc\SysConfig;
use App\Policies\Capabilities;
use App\Services\Files\FileStaging;
use Illuminate\Support\Facades\Route;

// File manager: JSON routes of the island (E3 spec §6.4). hostco owns bob;
// resb owns carol and dave, who is suspended.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake, ['dave' => ['SUSPENDED' => 'yes']]);
    $this->staging = sys_get_temp_dir().'/arc-files-'.bin2hex(random_bytes(4));
    mkdir($this->staging.'/uploads', 0700, true);
    mkdir($this->staging.'/downloads', 0700, true);
    config(['arc.files_staging' => $this->staging]);

    $this->fake->on('v-list-fs-directory-json')->using(fn (array $args) => [0, (string) json_encode(['TOTAL' => 1, 'OFFSET' => 0, 'ENTRIES' => [
        ['NAME' => 'index.php', 'TYPE' => 'f', 'SIZE' => '12', 'MODE' => '0644', 'OWNER' => $args[0], 'GROUP' => $args[0], 'MTIME' => 1, 'LINK' => '', 'LINK_TYPE' => '', 'LINK_INSIDE' => '', 'VALID_NAME' => 'yes'],
    ]])]);
    $this->fake->on('v-get-fs-file-info')->using(fn (array $args) => [0, (string) json_encode(['NAME' => basename($args[1]), 'TYPE' => 'd', 'MODE' => '0755', 'WRITABLE' => 'yes', 'MIME' => 'inode/directory'])]);
});

afterEach(function () {
    FileStaging::removeTree($this->staging);
});

/** Every file manager route: method, URI below /files/{user}, needs write access */
function fileRoutes(): array
{
    $ticket = str_repeat('a', 32);

    return [
        ['GET', 'api/list', false], ['GET', 'api/info', false], ['GET', 'api/read?path=a', false],
        ['GET', 'api/size', false], ['GET', 'api/search?q=a', false], ['POST', 'api/download', false],
        ['GET', 'download/'.$ticket, false],
        ['PUT', 'api/save', true], ['POST', 'api/mkdir', true], ['POST', 'api/touch', true], ['POST', 'api/rename', true],
        ['POST', 'api/copy', true], ['POST', 'api/move', true], ['POST', 'api/delete', true], ['POST', 'api/chmod', true],
        ['POST', 'api/compress', true], ['POST', 'api/extract', true], ['POST', 'api/upload', true],
        ['PUT', 'api/upload/'.$ticket.'/0', true], ['POST', 'api/upload/'.$ticket.'/finish', true], ['DELETE', 'api/upload/'.$ticket, true],
    ];
}

it('has a capability for every file manager route', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter(fn ($n) => is_string($n) && str_starts_with($n, 'files.'));
    expect($names->count())->toBeGreaterThanOrEqual(count(fileRoutes()));
    foreach (['files.view', 'files.manage'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});

it('lists a folder of the own account with the folder info', function () {
    signIn('bob', 'user');

    $this->getJson('/files/bob/api/list?path=web/example.com/public_html&sort=size&order=desc&hidden=no')->assertOk()
        ->assertJsonPath('path', 'web/example.com/public_html')
        ->assertJsonPath('dir.writable', true)
        ->assertJsonPath('entries.0.name', 'index.php')
        ->assertJsonPath('entries.0.dir', false);
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a === ['bob', '/home/bob/web/example.com/public_html', '0', '2000', 'size', 'desc', 'no']);

    $this->getJson('/files/bob/api/list?path=../carol')->assertNotFound();
    $this->getJson('/files/bob/api/list?path=/etc')->assertNotFound();
    $this->getJson('/files/bob/api/list?sort=color')->assertUnprocessable();
});

it('answers 404 outside the tree for every route and 403 for writes without write access', function () {
    signIn('bob', 'user');
    foreach (fileRoutes() as [$method, $uri]) {
        $this->json($method, '/files/carol/'.$uri)->assertNotFound();
    }

    signIn('hostco', 'reseller');
    $this->getJson('/files/bob/api/list')->assertOk();
    $this->getJson('/files/carol/api/list')->assertNotFound();

    // resb on suspended dave: reads yes, writes no
    signIn('resb', 'reseller');
    $this->getJson('/files/dave/api/list')->assertOk();
    foreach (array_filter(fileRoutes(), fn ($r) => $r[2]) as [$method, $uri]) {
        $this->json($method, '/files/dave/'.$uri, ['path' => 'x', 'name' => 'y', 'paths' => ['x'], 'mode' => '0644', 'size' => 1, 'dest' => ''])->assertForbidden();
    }
    $this->fake->assertNotRan('v-add-fs-directory');

    signIn('arcadmin', 'admin');
    $this->fake->on('v-add-fs-directory')->returns();
    $this->fake->on('v-get-fs-file-info', fn ($a) => str_ends_with($a[1], '/new'))->returnsExit(ExitCode::NotExist);
    $this->postJson('/files/dave/api/mkdir', ['path' => 'tmp', 'name' => 'new'])->assertCreated()->assertJsonPath('path', 'tmp/new');
});

it('refuses every change in read-only mode', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    fakeResellers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->getJson('/files/bob/api/list')->assertOk();
    $this->postJson('/files/bob/api/mkdir', ['name' => 'x'])->assertForbidden();
    $this->putJson('/files/bob/api/save', ['path' => 'a', 'content' => 'x'])->assertForbidden();
});

it('answers 404 when the file manager is off', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['FILE_MANAGER' => 'false']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    signIn('bob', 'user');

    $this->getJson('/files/bob/api/list')->assertNotFound();
    $this->fake->assertNotRan('v-list-fs-directory-json');
});

it('reads and saves with the hash guard', function () {
    signIn('bob', 'user');
    $hash = str_repeat('a', 64);
    $this->fake->on('v-read-fs-file')->returns('{"SIZE":3,"MTIME":5,"MIME":"text/x-php","HASH":"'.$hash."\"}\n<?=");
    $this->getJson('/files/bob/api/read?path=web/a.php')->assertOk()
        ->assertJsonPath('content', '<?=')->assertJsonPath('hash', $hash)->assertJsonPath('mime', 'text/x-php');

    $this->fake->on('v-save-fs-file')->returns('{"SIZE":4,"MTIME":6,"MIME":"text/x-php","HASH":"'.str_repeat('b', 64).'"}');
    $this->putJson('/files/bob/api/save', ['path' => 'web/a.php', 'content' => '<?=1', 'hash' => $hash])->assertOk()
        ->assertJsonPath('hash', str_repeat('b', 64));

    $this->fake->on('v-save-fs-file')->returnsExit(ExitCode::InUse, 'Error: changed since it was read');
    $this->putJson('/files/bob/api/save', ['path' => 'web/a.php', 'content' => 'x', 'hash' => $hash])->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INUSE');

    $this->fake->on('v-save-fs-file')->returnsExit(ExitCode::Disk, 'Error: disk quota exceeded');
    $this->putJson('/files/bob/api/save', ['path' => 'web/a.php', 'content' => 'x', 'hash' => $hash])->assertStatus(422)
        ->assertJsonPath('error.code', 'E_DISK');

    $this->fake->on('v-read-fs-file')->returnsExit(ExitCode::Limit, 'Error: larger than 2097152 bytes');
    $this->getJson('/files/bob/api/read?path=big.log')->assertStatus(413)->assertJsonPath('error.code', 'E_LIMIT');

    $this->putJson('/files/bob/api/save', ['path' => '', 'content' => 'x'])->assertUnprocessable();
    $this->putJson('/files/bob/api/save', ['path' => 'a', 'content' => 'x', 'hash' => 'nothex'])->assertUnprocessable();
});

it('asks before overwriting on copy, move and rename', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-fs-directory-json')->returns(json_encode(['TOTAL' => 1, 'OFFSET' => 0, 'ENTRIES' => [['NAME' => 'a.txt', 'TYPE' => 'f']]]));
    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => 'a.txt', 'TYPE' => 'f']);
    $this->fake->on('v-move-fs-file')->returns();

    $this->postJson('/files/bob/api/move', ['paths' => ['src/a.txt'], 'dest' => 'dst'])->assertStatus(409)
        ->assertJsonPath('error.conflicts', ['a.txt']);
    $this->fake->assertNotRan('v-move-fs-file');
    $this->postJson('/files/bob/api/move', ['paths' => ['src/a.txt'], 'dest' => 'dst', 'onConflict' => 'rename'])->assertOk()
        ->assertJsonPath('names', ['a (1).txt']);
    $this->postJson('/files/bob/api/rename', ['path' => 'dst/b.txt', 'name' => 'a.txt'])->assertStatus(409);
    $this->postJson('/files/bob/api/rename', ['path' => 'dst/b.txt', 'name' => '../x'])->assertUnprocessable();
    $this->postJson('/files/bob/api/compress', ['paths' => ['a/x', 'b/y'], 'name' => 'p.zip'])->assertUnprocessable();
    $this->postJson('/files/bob/api/delete', ['paths' => ['.']])->assertNotFound();
});

it('names a read-only folder in one line instead of the command error', function () {
    signIn('bob', 'user');
    $this->fake->on('v-list-fs-directory-json')->returns(json_encode(['TOTAL' => 0, 'OFFSET' => 0, 'ENTRIES' => []]));
    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => 'a.txt', 'TYPE' => 'f']);
    $this->fake->on('v-get-fs-file-info', fn ($a) => $a[1] === '/home/bob/web/example.com/a.txt')->returnsExit(ExitCode::NotExist);
    $this->fake->on('v-move-fs-file')->returnsExit(ExitCode::Forbidden, 'Error: /home/bob/web/example.com is read-only');

    $this->postJson('/files/bob/api/move', ['paths' => ['web/example.com/public_html/a.txt'], 'dest' => 'web/example.com'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDEN')
        ->assertJsonPath('error.message', '~/web/example.com is read-only.');

    $this->fake->on('v-get-fs-file-info', fn ($a) => $a[1] === '/home/bob/new')->returnsExit(ExitCode::NotExist);
    $this->fake->on('v-add-fs-directory')->returnsExit(ExitCode::Forbidden, 'Error: /home/bob is read-only');
    $this->postJson('/files/bob/api/mkdir', ['path' => '', 'name' => 'new'])
        ->assertForbidden()
        ->assertJsonPath('error.message', '~ is read-only.');
});

it('uploads in chunks and hands the file off', function () {
    signIn('bob', 'user');
    $init = $this->postJson('/files/bob/api/upload', ['path' => 'web', 'name' => 'a.txt', 'size' => 5, 'lastModified' => 1])
        ->assertOk()->assertJsonPath('received', [])->json();

    $this->call('PUT', '/files/bob/api/upload/'.$init['token'].'/0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'hello')
        ->assertOk()->assertJsonPath('received', [0]);

    $this->fake->on('v-get-fs-file-info', fn ($a) => $a[1] === '/home/bob/web/a.txt')->returnsExit(ExitCode::NotExist);
    $this->fake->on('v-add-fs-upload')->returns("/home/bob/web/a.txt\n");
    $this->postJson('/files/bob/api/upload/'.$init['token'].'/finish')->assertCreated()->assertJsonPath('path', 'web/a.txt');

    // A session of someone else
    signIn('hostco', 'reseller');
    $other = $this->postJson('/files/bob/api/upload', ['path' => 'web', 'name' => 'b.txt', 'size' => 5])->json();
    signIn('bob', 'user');
    $this->postJson('/files/bob/api/upload/'.$other['token'].'/finish')->assertForbidden();

    $this->postJson('/files/bob/api/upload', ['path' => 'web', 'name' => '../a', 'size' => 1])->assertUnprocessable();
    $this->postJson('/files/bob/api/upload', ['path' => 'web', 'name' => 'a', 'relativePath' => 'sub/b', 'size' => 1])->assertUnprocessable();
    $this->postJson('/files/bob/api/upload', ['path' => 'web', 'name' => 'x', 'relativePath' => '../../x', 'size' => 1])->assertNotFound();
});

it('creates a download ticket and sends it through X-Accel-Redirect', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-fs-download')->using(function (array $args) {
        file_put_contents($this->staging.'/downloads/'.$args[1], 'hello');

        return [0, '{"NAME":"tệp a.txt","SIZE":5,"MIME":"text/plain"}'];
    });
    $ticket = $this->postJson('/files/bob/api/download', ['paths' => ['web/tệp a.txt']])->assertCreated()->json();
    expect($ticket['url'])->toMatch('#/files/bob/download/[a-f0-9]{32}$#');
    $path = parse_url($ticket['url'], PHP_URL_PATH);
    $path = preg_replace('#^/app#', '', (string) $path);

    $response = $this->get($path.'?inline=1')->assertOk();
    expect($response->headers->get('X-Accel-Redirect'))->toMatch('#^/_arc_files/[a-f0-9]{32}$#')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline;')->toContain("filename*=UTF-8''t%E1%BB%87p%20a.txt");

    signIn('hostco', 'reseller');
    $this->get($path)->assertNotFound();
    $this->postJson('/files/bob/api/download', ['paths' => ['a/x', 'b/y']])->assertUnprocessable();
});
