<?php

use App\Arc\ArcCommand;
use App\Arc\ExitCode;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\FilePolicy;
use App\Policies\Scope;
use App\Services\Files\DownloadTickets;
use App\Services\Files\FileConflict;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use App\Services\Files\FileStaging;
use App\Services\Files\InvalidFilePath;
use App\Services\Files\UploadRefused;
use App\Services\Files\UploadSessions;
use App\Tenancy\TenancyDirectory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// File manager units (E3 spec §6): paths, policy, service arguments, upload
// sessions and download tickets. hostco owns bob; resb owns carol and dave,
// who is suspended.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake, ['bob' => ['HOME' => '/home/bob', 'U_DISK' => '100', 'DISK_QUOTA' => '200'], 'dave' => ['SUSPENDED' => 'yes']]);
    $this->staging = sys_get_temp_dir().'/arc-files-'.bin2hex(random_bytes(4));
    mkdir($this->staging.'/uploads', 0700, true);
    mkdir($this->staging.'/downloads', 0700, true);
    config(['arc.files_staging' => $this->staging]);
});

afterEach(function () {
    FileStaging::removeTree($this->staging);
});

function filePolicy(Principal $principal): FilePolicy
{
    $tenancy = new TenancyDirectory(app(ArcCommand::class));

    return new FilePolicy(new OwnerScope($principal, $tenancy), $tenancy);
}

/** @return resource */
function bodyStream(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('normalises relative paths and refuses paths that leave the home', function () {
    expect(FilePath::from('web/./example.com//public_html/')->relative())->toBe('web/example.com/public_html')
        ->and(FilePath::from('web/a/../b')->relative())->toBe('web/b')
        ->and(FilePath::from('')->isRoot())->toBeTrue()
        ->and(FilePath::from('web/x')->absolute('/home/bob/'))->toBe('/home/bob/web/x')
        ->and(FilePath::root()->absolute('/home/bob'))->toBe('/home/bob')
        ->and(FilePath::from('ảnh/tệp tin.txt')->name())->toBe('tệp tin.txt')
        ->and(FilePath::from('a/b')->contains(FilePath::from('a/b/c')))->toBeTrue()
        ->and(FilePath::from('a/b')->contains(FilePath::from('a/bc')))->toBeFalse();

    foreach (['..', 'web/../../etc', '/etc/passwd', "web/\0x", "web/a\nb", "a\rb", str_repeat('a', 256)] as $bad) {
        expect(fn () => FilePath::from($bad))->toThrow(InvalidFilePath::class);
    }
    foreach (['', '.', '..', 'a/b', "a\nb"] as $name) {
        expect(FilePath::validName($name))->toBeFalse();
    }
    expect(fn () => FilePath::root()->child('..'))->toThrow(InvalidFilePath::class)
        ->and(fn () => FilePath::root()->join('a/../..'))->toThrow(InvalidFilePath::class);
});

it('gives every role the files of the accounts it can see', function () {
    foreach ([[Role::Admin, Scope::All], [Role::Reseller, Scope::Tree], [Role::User, Scope::Self]] as [$role, $scope]) {
        expect(Capabilities::scopeOf($role, 'files.view'))->toBe($scope)
            ->and(Capabilities::scopeOf($role, 'files.manage'))->toBe($scope);
    }
    $bob = principal('bob', Role::User);
    $hostco = principal('hostco', Role::Reseller);
    $resb = principal('resb', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);

    expect(filePolicy($bob)->manage($bob, 'bob'))->toBeTrue()
        ->and(filePolicy($bob)->view($bob, 'carol'))->toBeFalse()
        ->and(filePolicy($hostco)->manage($hostco, 'bob'))->toBeTrue()
        ->and(filePolicy($hostco)->view($hostco, 'carol'))->toBeFalse()
        ->and(filePolicy($resb)->view($resb, 'bob'))->toBeFalse()
        ->and(filePolicy($resb)->manage($resb, 'carol'))->toBeTrue()
        ->and(filePolicy($admin)->manage($admin, 'carol'))->toBeTrue();
});

it('makes a suspended account read-only except for administrators, and read-only sessions change nothing', function () {
    $resb = principal('resb', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);
    $readOnly = principal('bob', Role::User, readOnly: true);

    expect(filePolicy($resb)->view($resb, 'dave'))->toBeTrue()
        ->and(filePolicy($resb)->abilities($resb, 'dave'))->toBe(['manage' => false])
        ->and(filePolicy($admin)->manage($admin, 'dave'))->toBeTrue()
        ->and(filePolicy($readOnly)->view($readOnly, 'bob'))->toBeTrue()
        ->and(filePolicy($readOnly)->manage($readOnly, 'bob'))->toBeFalse();
});

it('passes absolute paths in the order of the commands', function () {
    $files = app(FileService::class);
    $this->fake->on('v-list-fs-directory-json')->returns(json_encode(['TOTAL' => 1, 'OFFSET' => 0, 'ENTRIES' => [
        ['NAME' => 'public_html', 'TYPE' => 'd', 'SIZE' => '4096', 'MODE' => '0755', 'OWNER' => 'bob', 'GROUP' => 'bob', 'MTIME' => 1, 'LINK' => '', 'LINK_TYPE' => '', 'LINK_INSIDE' => '', 'VALID_NAME' => 'yes'],
    ]]));
    $listing = $files->list('bob', FilePath::from('web/example.com'), 0, 100, 'size', 'desc', false);
    expect($listing['entries'][0]->isDirectory())->toBeTrue();
    $this->fake->assertRan('v-list-fs-directory-json', fn ($a) => $a === ['bob', '/home/bob/web/example.com', '0', '100', 'size', 'desc', 'no']);

    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => 'x', 'TYPE' => 'd']);
    $this->fake->on('v-change-fs-directory-permission')->returns();
    $files->chmod('bob', FilePath::from('web/x'), '0750', true, '0640');
    $this->fake->assertRan('v-change-fs-directory-permission', fn ($a) => $a === ['bob', '/home/bob/web/x', '0750', '0640', 'yes']);

    $this->fake->on('v-add-fs-zip')->returns();
    expect($files->compress('bob', [FilePath::from('web/a'), FilePath::from('web/b c')], 'pack.zip')->relative())->toBe('web/pack.zip');
    $this->fake->assertRan('v-add-fs-zip', fn ($a) => $a === ['bob', '/home/bob/web/pack.zip', '/home/bob/web/a', '/home/bob/web/b c']);

    $this->fake->on('v-search-fs-object')->returns("f|644|2026-10-05|10:00|bob|bob|12|sub/a.php\nd|755|2026-10-05|10:00|bob|bob|4096|sub\n");
    expect($files->search('bob', FilePath::from('web'), 'a.php')['results'][0])->toBe(['path' => 'web/sub/a.php', 'type' => 'f', 'size' => 12]);
    $this->fake->assertRan('v-search-fs-object', fn ($a) => $a === ['bob', '*a.php*', '/home/bob/web']);
});

it('reads content with its hash and saves through a staged file that is removed', function () {
    $files = app(FileService::class);
    $this->fake->on('v-read-fs-file')->returns('{"SIZE":5,"MTIME":7,"MIME":"text/plain","HASH":"'.str_repeat('a', 64)."\"}\nhe\nl");
    expect($files->read('bob', FilePath::from('a.txt')))->toMatchArray(['content' => "he\nl", 'hash' => str_repeat('a', 64), 'utf8' => true]);

    $this->fake->on('v-read-fs-file')->returns('{"SIZE":2,"MTIME":7,"MIME":"text/plain","HASH":"'.str_repeat('a', 64)."\"}\n\xff\xfe");
    expect($files->read('bob', FilePath::from('a.txt'))['utf8'])->toBeFalse();

    $staged = null;
    $this->fake->on('v-save-fs-file')->using(function (array $args) use (&$staged) {
        $staged = $args[1];
        expect(file_get_contents($staged))->toBe('new content');

        return [0, '{"SIZE":11,"MTIME":9,"MIME":"text/plain","HASH":"'.str_repeat('b', 64).'"}'];
    });
    $saved = $files->save('bob', FilePath::from('web/a.txt'), 'new content', str_repeat('a', 64));
    expect($saved['hash'])->toBe(str_repeat('b', 64))
        ->and(str_starts_with($staged, $this->staging.'/uploads/'))->toBeTrue()
        ->and(file_exists(dirname($staged)))->toBeFalse();
    $this->fake->assertRan('v-save-fs-file', fn ($a) => $a[0] === 'bob' && $a[2] === '/home/bob/web/a.txt' && $a[3] === str_repeat('a', 64));
});

it('stops a copy on existing names unless told how to resolve them', function () {
    $files = app(FileService::class);
    $this->fake->on('v-list-fs-directory-json')->returns(json_encode(['TOTAL' => 2, 'OFFSET' => 0, 'ENTRIES' => [['NAME' => 'a.txt', 'TYPE' => 'f'], ['NAME' => 'a (1).txt', 'TYPE' => 'f']]]));
    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => 'a.txt', 'TYPE' => 'f']);
    $this->fake->on('v-copy-fs-file')->returns();

    expect(fn () => $files->copy('bob', [FilePath::from('src/a.txt')], FilePath::from('dst'), null))->toThrow(FileConflict::class);
    $this->fake->assertNotRan('v-copy-fs-file');

    expect($files->copy('bob', [FilePath::from('src/a.txt')], FilePath::from('dst'), 'rename'))->toBe(['a (2).txt']);
    $this->fake->assertRan('v-copy-fs-file', fn ($a) => $a === ['bob', '/home/bob/src/a.txt', '/home/bob/dst/a (2).txt']);
    expect($files->copy('bob', [FilePath::from('src/a.txt')], FilePath::from('dst'), 'skip'))->toBe([]);

    // Within the same folder a copy gets a free name without asking
    expect($files->copy('bob', [FilePath::from('dst/a.txt')], FilePath::from('dst'), null))->toBe(['a (2).txt']);
    // Into itself is refused
    expect(fn () => $files->copy('bob', [FilePath::from('dst')], FilePath::from('dst/sub'), null))->toThrow(InvalidFilePath::class);
    expect(FileService::freeName('archive.tar.gz', ['archive.tar (1).gz']))->toBe('archive.tar (2).gz')
        ->and(FileService::freeName('.htaccess', []))->toBe('.htaccess (1)');
});

it('resumes upload sessions, checks chunks and hands the file off once', function () {
    $sessions = app(UploadSessions::class);
    $file = ['path' => FilePath::from('web/up'), 'name' => 'big.bin', 'relativePath' => '', 'size' => UploadSessions::CHUNK + 3, 'lastModified' => 42];

    $first = $sessions->init('bob', 'bob', $file, UploadSessions::maxBytes('4G'), false);
    expect($first['received'])->toBe([])->and($first['chunkSize'])->toBe(UploadSessions::CHUNK);

    expect(fn () => $sessions->putChunk('bob', 'bob', $first['token'], 1, bodyStream('toolong')))->toThrow(UploadRefused::class);
    expect($sessions->putChunk('bob', 'bob', $first['token'], 1, bodyStream('end')))->toBe([1]);
    expect(fn () => $sessions->putChunk('hostco', 'bob', $first['token'], 0, bodyStream('x')))->toThrow(UploadRefused::class);
    expect(fn () => $sessions->putChunk('bob', 'bob', $first['token'], 2, bodyStream('x')))->toThrow(UploadRefused::class);

    // Same file again: the same session with what it already has
    $again = $sessions->init('bob', 'bob', $file, UploadSessions::maxBytes('4G'), false);
    expect($again['token'])->toBe($first['token'])->and($again['received'])->toBe([1]);

    expect(fn () => $sessions->finish('bob', 'bob', $first['token'], null))->toThrow(UploadRefused::class);
    $sessions->putChunk('bob', 'bob', $first['token'], 0, bodyStream(str_repeat('a', UploadSessions::CHUNK)));

    $this->fake->on('v-get-fs-file-info')->returnsExit(ExitCode::NotExist);
    $this->fake->on('v-add-fs-directory')->returns();
    $this->fake->on('v-add-fs-upload')->using(function (array $args) {
        expect(filesize($args[1]))->toBe(UploadSessions::CHUNK + 3);

        return [0, "/home/bob/web/up/big.bin\n"];
    });
    expect($sessions->finish('bob', 'bob', $first['token'], null)['path']->relative())->toBe('web/up/big.bin');
    $this->fake->assertRan('v-add-fs-upload', fn ($a) => $a[0] === 'bob' && $a[2] === '/home/bob/web/up/big.bin' && $a[3] === '0644' && $a[4] === 'fail');
    expect(is_dir($this->staging.'/uploads/'.$first['token']))->toBeFalse();
});

it('refuses uploads above the limit, over the quota and into existing names', function () {
    $sessions = app(UploadSessions::class);
    $file = ['path' => FilePath::root(), 'name' => 'a.bin', 'relativePath' => '', 'size' => 200 * 1048576, 'lastModified' => 1];

    expect(UploadSessions::maxBytes(''))->toBe(4 * 1073741824)
        ->and(UploadSessions::maxBytes('512M'))->toBe(512 * 1048576)
        ->and(UploadSessions::maxBytes('junk'))->toBe(4 * 1073741824);
    expect(fn () => $sessions->init('bob', 'bob', $file, 1048576, false))->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(413));
    // bob uses 100 of 200 MB: 200 MB more is too much when quotas are enforced
    expect(fn () => $sessions->init('bob', 'bob', $file, UploadSessions::maxBytes('4G'), true))
        ->toThrow(fn (UploadRefused $e) => expect([$e->errorCode, $e->status])->toBe(['E_DISK', 422]));
    expect($sessions->init('bob', 'bob', $file, UploadSessions::maxBytes('4G'), false)['token'])->toMatch('/^[a-f0-9]{32}$/');

    $small = $sessions->init('bob', 'bob', ['path' => FilePath::root(), 'name' => 'x.txt', 'relativePath' => 'sub/x.txt', 'size' => 1, 'lastModified' => 1], 100, false);
    $sessions->putChunk('bob', 'bob', $small['token'], 0, bodyStream('x'));
    $this->fake->on('v-get-fs-file-info')->returnsJson(['NAME' => 'x.txt', 'TYPE' => 'f']);
    expect(fn () => $sessions->finish('bob', 'bob', $small['token'], null))->toThrow(FileConflict::class);

    $this->fake->on('v-add-fs-directory')->returns();
    $this->fake->on('v-add-fs-upload')->returnsExit(ExitCode::Disk, 'Error: disk quota exceeded');
    expect(fn () => $sessions->finish('bob', 'bob', $small['token'], 'overwrite'))
        ->toThrow(fn (UploadRefused $e) => expect([$e->errorCode, $e->status])->toBe(['E_DISK', 422]));
    $this->fake->assertRan('v-add-fs-directory', fn ($a) => $a === ['bob', '/home/bob/sub']);
    expect(is_dir($this->staging.'/uploads/'.$small['token']))->toBeFalse();
});

it('removes stale sessions and tickets', function () {
    $sessions = app(UploadSessions::class);
    $old = $sessions->init('bob', 'bob', ['path' => FilePath::root(), 'name' => 'a', 'relativePath' => '', 'size' => 1, 'lastModified' => 1], 10, false);
    $fresh = $sessions->init('bob', 'bob', ['path' => FilePath::root(), 'name' => 'b', 'relativePath' => '', 'size' => 1, 'lastModified' => 1], 10, false);
    $meta = $this->staging.'/uploads/'.$old['token'].'/meta.json';
    file_put_contents($meta, json_encode(['created' => time() - UploadSessions::TTL - 10] + json_decode(file_get_contents($meta), true)));

    expect($sessions->gc())->toBe(1)
        ->and(is_dir($this->staging.'/uploads/'.$old['token']))->toBeFalse()
        ->and(is_dir($this->staging.'/uploads/'.$fresh['token']))->toBeTrue();

    $ticket = str_repeat('c', 32);
    touch($this->staging.'/downloads/'.$ticket, time() - DownloadTickets::TTL - 10);
    touch($this->staging.'/downloads/'.$ticket.'.json', time() - DownloadTickets::TTL - 10);
    touch($this->staging.'/downloads/'.str_repeat('d', 32));
    expect(app(DownloadTickets::class)->gc())->toBe(1)
        ->and(file_exists($this->staging.'/downloads/'.$ticket.'.json'))->toBeFalse()
        ->and(file_exists($this->staging.'/downloads/'.str_repeat('d', 32)))->toBeTrue();
});

it('serves a ticket only to the login user that created it, for an hour', function () {
    $tickets = app(DownloadTickets::class);
    $this->fake->on('v-add-fs-download')->using(function (array $args) {
        file_put_contents($this->staging.'/downloads/'.$args[1], 'zip');

        return [0, '{"NAME":"site.zip","SIZE":3,"MIME":"application/zip"}'];
    });
    $ticket = $tickets->create('hostco', 'bob', [FilePath::from('web/site')]);
    $this->fake->assertRan('v-add-fs-download', fn ($a) => $a[0] === 'bob' && $a[2] === '/home/bob/web/site');

    expect($tickets->resolve('hostco', 'bob', $ticket['ticket']))->toBe(['name' => 'site.zip', 'size' => 3, 'mime' => 'application/zip']);
    expect(fn () => $tickets->resolve('bob', 'bob', $ticket['ticket']))->toThrow(NotFoundHttpException::class);
    expect(fn () => $tickets->resolve('hostco', 'bob', str_repeat('0', 32)))->toThrow(NotFoundHttpException::class);
});

it('names the type of files that `file` does not recognise after their extension', function () {
    $this->fake->on('v-add-fs-download')->using(function (array $args) {
        file_put_contents($this->staging.'/downloads/'.$args[1], 'x');

        return [0, '{"NAME":"clip.mp4","SIZE":1,"MIME":"application/octet-stream"}'];
    });
    expect(app(DownloadTickets::class)->create('bob', 'bob', [FilePath::from('clip.mp4')])['mime'])->toBe('video/mp4');

    $this->fake->on('v-add-fs-download')->returns('{"NAME":"blob.unknownext","SIZE":1,"MIME":"application/octet-stream"}');
    expect(app(DownloadTickets::class)->create('bob', 'bob', [FilePath::from('blob.unknownext')])['mime'])->toBe('application/octet-stream');
});
