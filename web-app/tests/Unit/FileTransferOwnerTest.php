<?php

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Files\DownloadTickets;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use App\Services\Files\FileStaging;
use App\Services\Files\UploadRefused;
use App\Services\Files\UploadSessions;
use App\Services\WebDomains\Step;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Owners of upload sessions and download tickets (E34 batch 5 spec §5, D50):
// the login user on the page, the access key in REST API v2.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    $this->staging = sys_get_temp_dir().'/arc-files-owner-'.bin2hex(random_bytes(4));
    mkdir($this->staging.'/uploads', 0700, true);
    mkdir($this->staging.'/downloads', 0700, true);
    config(['arc.files_staging' => $this->staging]);
});

afterEach(function () {
    FileStaging::removeTree($this->staging);
});

it('names the login user on the page and the access key in the API', function () {
    $page = principal('hostco', Role::Reseller);
    $api = new Principal('bob', null, Role::User, Role::User, '', 'en', null, '', false, accessKey: 'APIKEY0123456789abcd');

    expect(FileStaging::owner($page))->toBe('hostco')
        ->and(FileStaging::owner($api))->toBe('api:APIKEY0123456789abcd');
});

it('keeps a session to its owner, also against the same user', function () {
    $sessions = app(UploadSessions::class);
    $file = ['path' => FilePath::from('web'), 'name' => 'a.txt', 'relativePath' => '', 'size' => 1, 'lastModified' => 1];
    $session = $sessions->init('api:KEY1', 'bob', $file, 100, false);
    $stream = fn () => fopen('data://text/plain,x', 'rb');

    foreach (['api:KEY2', 'bob', 'hostco'] as $other) {
        expect(fn () => $sessions->putChunk($other, 'bob', $session['token'], 0, $stream()))
            ->toThrow(fn (UploadRefused $e) => expect($e->status)->toBe(403));
    }
    // Another owner does not resume it
    expect($sessions->init('api:KEY2', 'bob', $file, 100, false)['token'])->not->toBe($session['token'])
        ->and($sessions->init('api:KEY1', 'bob', $file, 100, false)['token'])->toBe($session['token'])
        ->and(json_decode((string) file_get_contents($this->staging.'/uploads/'.$session['token'].'/meta.json'), true)['owner'])->toBe('api:KEY1');
    expect($sessions->putChunk('api:KEY1', 'bob', $session['token'], 0, $stream()))->toBe([0]);
    expect($sessions->target('api:KEY1', 'bob', $session['token']))->toMatchArray([
        'nested' => false,
        'staged' => $this->staging.'/uploads/'.$session['token'].'/file',
    ]);
    expect(fn () => $sessions->target('api:KEY2', 'bob', $session['token']))->toThrow(UploadRefused::class);
});

it('serves a ticket to its owner only', function () {
    $this->fake->on('v-add-fs-download')->using(function (array $args) {
        file_put_contents($this->staging.'/downloads/'.$args[1], 'x');

        return [0, '{"NAME":"a.txt","SIZE":1,"MIME":"text/plain"}'];
    });
    $tickets = app(DownloadTickets::class);
    $ticket = $tickets->create('api:KEY1', 'bob', [FilePath::from('web/a.txt')]);

    expect($tickets->resolve('api:KEY1', 'bob', $ticket['ticket']))->toBe(['name' => 'a.txt', 'size' => 1, 'mime' => 'text/plain']);
    foreach (['api:KEY2', 'bob'] as $other) {
        expect(fn () => $tickets->resolve($other, 'bob', $ticket['ticket']))->toThrow(NotFoundHttpException::class);
    }
});

it('plans the commands of an upload hand-off and a download', function () {
    $files = app(FileService::class);
    $commands = fn (array $steps) => array_map(fn (Step $s) => [$s->command, $s->args], $steps);

    expect($commands($files->planUpload('bob', '/staging/t/file', FilePath::from('web/css'), FilePath::from('web/css/a.css'), true, 'fail')->steps()))->toBe([
        ['v-add-fs-directory', ['bob', '/home/bob/web/css']],
        ['v-add-fs-upload', ['bob', '/staging/t/file', '/home/bob/web/css/a.css', '0644', 'fail']],
    ])->and($commands($files->planUpload('bob', '/staging/t/file', FilePath::root(), FilePath::from('a.css'), false, 'rename')->steps()))->toBe([
        ['v-add-fs-upload', ['bob', '/staging/t/file', '/home/bob/a.css', '0644', 'rename']],
    ]);

    $step = $files->planDownload('bob', str_repeat('a', 32), [FilePath::from('web/a'), FilePath::from('web/b')]);
    expect([$step->command, $step->args])->toBe(['v-add-fs-download', ['bob', str_repeat('a', 32), '/home/bob/web/a', '/home/bob/web/b']])
        ->and($step->label)->toBe('Prepare the download of 2 items of web');
});
