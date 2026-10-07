<?php

use App\Http\Resources\V2\SnapshotFileEntryResource;
use App\Http\Resources\V2\SnapshotResource;
use App\Services\Backups\BackupArchive;
use App\Services\Backups\ResticSnapshot;
use Illuminate\Http\Request;

it('maps a snapshot to the Snapshot schema', function () {
    $snapshot = ResticSnapshot::fromArray([
        'id' => '4f2a91c0123456789012345678901234567890123456789012345678901234',
        'short_id' => '4f2a91c0',
        'time' => '2026-10-06T01:00:00+00:00',
        'hostname' => 'arcpanel-test',
        'paths' => ['/home/bob'],
    ]);
    $content = BackupArchive::fromArray('bob', '4f2a91c0', [
        'TYPE' => 'restic', 'WEB' => 'example.com', 'DNS' => '', 'MAIL' => '', 'DB' => 'bob_wp',
        'CRON' => 'yes', 'UDIR' => '', 'SIZE' => '0', 'RUNTIME' => '0', 'DATE' => '2026-10-06', 'TIME' => '01:00:00',
    ]);

    $data = (new SnapshotResource($snapshot, $content))->resolve(Request::create('/api/v2/users/bob/snapshots/4f2a91c0'));

    expect($data)->toMatchArray([
        'id' => '4f2a91c0123456789012345678901234567890123456789012345678901234',
        'short_id' => '4f2a91c0',
        'created_at' => '2026-10-06T01:00:00',
        'hostname' => 'arcpanel-test',
        'paths' => ['/home/bob'],
        'content' => [
            'web' => ['example.com'],
            'dns' => [],
            'mail' => [],
            'db' => ['bob_wp'],
            'udir' => [],
            'cron' => true,
        ],
    ]);
});

it('maps snapshot folder entries to the FileEntry shape', function () {
    $entry = SnapshotFileEntryResource::from([
        'name' => 'note.txt',
        'type' => 'f',
        'dir' => false,
        'size' => 9,
        'mode' => '644',
        'mtime' => strtotime('2026-10-06 01:00:00'),
        'link' => '',
        'linkType' => '',
        'linkInside' => false,
        'validName' => true,
        'writable' => false,
    ], '/home/bob', '/home/bob/tmp');

    expect($entry)->toMatchArray([
        'name' => 'note.txt',
        'path' => 'tmp/note.txt',
        'type' => 'file',
        'writable' => false,
        'valid_name' => true,
    ]);
});
