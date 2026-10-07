<?php

use App\Http\Resources\V2\BackupResource;
use App\Services\Backups\BackupArchive;
use Illuminate\Http\Request;

it('maps an archive to the Backup schema', function () {
    $archive = BackupArchive::fromArray('bob', 'bob.2026-10-03_05-10-09.tar.gz', [
        'TYPE' => 'local', 'SIZE' => '42', 'WEB' => 'example.com', 'DNS' => '', 'MAIL' => '',
        'DB' => 'bob_wp', 'CRON' => 'yes', 'UDIR' => 'tmp', 'RUNTIME' => '120', 'DATE' => '2026-10-03', 'TIME' => '05:10:09',
    ]);

    $data = (new BackupResource($archive))->resolve(Request::create('/api/v2/users/bob/backups/bob.2026-10-03_05-10-09.tar.gz'));

    expect($data)->toMatchArray([
        'name' => 'bob.2026-10-03_05-10-09.tar.gz',
        'type' => 'tar.gz',
        'size_mb' => 42,
        'created_at' => '2026-10-03T05:10:09',
        'runtime_seconds' => 120,
        'content' => [
            'web' => ['example.com'],
            'dns' => [],
            'mail' => [],
            'db' => ['bob_wp'],
            'udir' => ['tmp'],
            'cron' => true,
        ],
    ]);
});

it('derives backup types from the file extension', function () {
    expect(BackupResource::typeFromName('bob.2026-10-01.tar'))->toBe('tar')
        ->and(BackupResource::typeFromName('bob.2026-10-01.tar.gz'))->toBe('tar.gz')
        ->and(BackupResource::typeFromName('bob.2026-10-01.tar.zst'))->toBe('tar.zst');
});
