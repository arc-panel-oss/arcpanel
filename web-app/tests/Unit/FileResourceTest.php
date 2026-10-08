<?php

use App\Http\Resources\V2\FileContentResource;
use App\Http\Resources\V2\FileEntryResource;
use App\Http\Resources\V2\FileSearchResultResource;
use App\Http\Resources\V2\LocatedFileEntry;
use App\Services\Files\FileEntry;
use App\Services\Files\FilePath;
use App\Services\Files\InvalidFilePath;
use Illuminate\Http\Request;

// REST API v2 files: paths (D48) and the Resources of E34 batch 5 spec §4.1.

it('keeps the path rules of the page for API paths (D48)', function () {
    expect(FilePath::from('public_html/index.php')->absolute('/home/bob'))->toBe('/home/bob/public_html/index.php')
        ->and(FilePath::from('a/../b/./c/')->relative())->toBe('b/c')
        ->and(FilePath::from(str_repeat('a', 255))->name())->toHaveLength(255)
        ->and(FilePath::from(str_repeat('a/', 2048))->relative())->toHaveLength(4095);

    foreach (['../../etc/passwd', '/etc/passwd', 'web/../../carol', "web/\x01", "a\x7fb", str_repeat('a', 256), str_repeat('a/', 2049)] as $bad) {
        expect(fn () => FilePath::from($bad))->toThrow(InvalidFilePath::class);
    }
});

it('shapes a file entry in snake case with nulls where a value is not known', function () {
    $request = Request::create('/');
    $row = ['NAME' => 'docs', 'TYPE' => 'l', 'SIZE' => '7', 'MODE' => '0777', 'OWNER' => 'bob', 'GROUP' => 'bob', 'MTIME' => '1759658400',
        'LINK' => '/home/bob/web/docs', 'LINK_TYPE' => 'd', 'LINK_INSIDE' => 'yes', 'VALID_NAME' => 'yes'];

    $link = (new FileEntryResource(LocatedFileEntry::inFolder(FilePath::from('web'), [FileEntry::from($row)])[0]))->resolve($request);
    expect($link)->toBe([
        'name' => 'docs', 'path' => 'web/docs', 'type' => 'link', 'size' => 7, 'mode' => '0777', 'owner' => 'bob', 'group' => 'bob',
        'modified_at' => date('Y-m-d\TH:i:s', 1759658400), 'link_target' => '/home/bob/web/docs', 'link_type' => 'd', 'link_inside' => true,
        'mime' => null, 'writable' => null, 'valid_name' => true,
    ]);

    $file = (new FileEntryResource(LocatedFileEntry::at(FileEntry::from([
        'NAME' => 'a.txt', 'TYPE' => 'f', 'SIZE' => '3', 'MODE' => '0644', 'MTIME' => '0', 'LINK' => 'stale', 'LINK_TYPE' => 'f',
        'LINK_INSIDE' => 'yes', 'MIME' => 'text/plain', 'WRITABLE' => 'no',
    ]), FilePath::from('a.txt'))))->resolve($request);
    expect($file)->toMatchArray(['type' => 'file', 'modified_at' => null, 'link_target' => null, 'link_type' => null, 'link_inside' => false, 'mime' => 'text/plain', 'writable' => false]);

    $odd = (new FileEntryResource(LocatedFileEntry::inFolder(FilePath::root(), [FileEntry::from(['NAME' => "bad\nname", 'TYPE' => 'p', 'VALID_NAME' => 'yes'])])[0]))->resolve($request);
    expect($odd)->toMatchArray(['path' => "bad\nname", 'type' => 'other', 'valid_name' => false]);
    $broken = (new FileEntryResource(LocatedFileEntry::at(FileEntry::from(['NAME' => 'gone', 'TYPE' => 'l', 'LINK' => 'x', 'LINK_TYPE' => '']), FilePath::from('gone'))))->resolve($request);
    expect($broken['link_type'])->toBe('o');
});

it('answers content on read and the new version on save', function () {
    $request = Request::create('/');
    $read = (new FileContentResource(['path' => 'a.txt', 'content' => "x\r\n", 'hash' => str_repeat('a', 64), 'size' => 3, 'mtime' => 1759658400, 'mime' => 'text/plain', 'utf8' => true]))->resolve($request);
    $saved = (new FileContentResource(['path' => 'a.txt', 'hash' => str_repeat('b', 64), 'size' => 4, 'mtime' => 0, 'mime' => '']))->resolve($request);

    expect($read)->toBe(['path' => 'a.txt', 'content' => "x\r\n", 'hash' => str_repeat('a', 64), 'size' => 3, 'modified_at' => date('Y-m-d\TH:i:s', 1759658400), 'mime' => 'text/plain', 'utf8' => true])
        ->and($saved)->toBe(['path' => 'a.txt', 'hash' => str_repeat('b', 64), 'size' => 4, 'modified_at' => null, 'mime' => null]);
    expect((new FileSearchResultResource(['path' => 'web/site/index.php', 'type' => 'f', 'size' => 12]))->resolve($request))
        ->toBe(['name' => 'index.php', 'path' => 'web/site/index.php', 'type' => 'file', 'size' => 12])
        ->and((new FileSearchResultResource(['path' => 'top', 'type' => 'o', 'size' => 0]))->resolve($request)['name'])->toBe('top');
});
