<?php

use App\Services\Backups\BackupArchive;
use App\Services\Backups\BackupExclusions;
use App\Services\Backups\BackupService;
use App\Services\Backups\SnapshotListing;

it('reads the exclusions of v-list-user-backup-exclusions as one entry per line', function () {
    $exclusions = BackupExclusions::fromJson([
        'WEB' => ['example.com' => 'public_html/cache,tmp', '*' => ''],
        'DNS' => ['example.com' => ''],
        'MAIL' => ['example.com' => 'info,support'],
        'DB' => [],
        'CRON' => ['*' => ''],
        'USER' => ['.nvm' => '', 'apps/kuma' => ''],
    ]);

    expect($exclusions->lines('WEB'))->toBe("example.com:public_html/cache:tmp\n*")
        ->and($exclusions->get('MAIL'))->toBe(['example.com:info:support'])
        ->and($exclusions->excludesPath('.nvm'))->toBeTrue()
        ->and($exclusions->excludesPath('apps/other'))->toBeFalse();
});

it('keeps DNS and CRON when the textareas are saved', function () {
    $exclusions = BackupExclusions::fromJson(['DNS' => ['example.com' => ''], 'CRON' => ['*' => ''], 'WEB' => ['old.example' => '']]);

    $saved = $exclusions->with(['WEB' => ['new.example:logs', ' ', 'new.example:logs'], 'MAIL' => [], 'DB' => ['bob_wp'], 'USER' => []]);

    expect($saved->toFile())->toBe("WEB='new.example:logs'\nDNS='example.com'\nMAIL=''\nDB='bob_wp'\nCRON='*'\nUSER=''\n");
});

it('merges the toggles into the user directories', function () {
    $lines = BackupExclusions::mergeToggles(['tmp', '.nvm', 'apps/kuma'], ['.nvm' => false, 'apps/kuma' => true, 'apps/n8n' => true]);

    expect($lines)->toBe(['tmp', 'apps/kuma', 'apps/n8n']);
    expect(BackupExclusions::parseLines("a\r\n\n b \nc"))->toBe(['a', 'b', 'c']);
});

it('parses an archive with its objects per type and its content type', function () {
    $archive = BackupArchive::fromArray('bob', 'bob.2026-10-01_05-10-11.tar.zst', [
        'TYPE' => 'local', 'SIZE' => '20', 'WEB' => 'example.com,shop.example', 'DNS' => '', 'MAIL' => 'example.com', 'DB' => 'bob_wp', 'CRON' => 'yes', 'UDIR' => 'tmp',
    ]);

    expect($archive->items['web'])->toBe(['example.com', 'shop.example'])
        ->and($archive->items['dns'])->toBe([])
        ->and($archive->items['cron'])->toBe(['yes'])
        ->and($archive->contentType())->toBe('application/zstd');
});

it('accepts only archive names of the owner', function () {
    expect(BackupService::ownsName('bob', 'bob.2026-10-01_05-10-11.tar'))->toBeTrue()
        ->and(BackupService::ownsName('bob', 'bobby.2026-10-01_05-10-11.tar'))->toBeFalse()
        ->and(BackupService::ownsName('bob', 'bob.../../etc/passwd'))->toBeFalse()
        ->and(BackupService::ownsName('bob', 'carol.2026-10-01.tar'))->toBeFalse();
});

it('matches the whole archive name: the user, a dot and the date of the archive (E39 W5 L22)', function () {
    expect(BackupService::ownsName('bob', 'bob.2026-10-01.tar'))->toBeTrue()
        ->and(BackupService::ownsName('bob', 'bob.2026-10-01_05-10-11.tar.zst'))->toBeTrue()
        ->and(BackupService::ownsName('bob', 'bob.2026-10-01.tar.gz'))->toBeTrue()
        ->and(BackupService::ownsName('bob', 'bob.x.2026-10-01_05-10-11.tar'))->toBeFalse()
        ->and(BackupService::ownsName('bob', 'bob.shop.2026-10-01.tar'))->toBeFalse()
        ->and(BackupService::ownsName('bob', 'bob.2026-10-01_05-10-11.tar.bak'))->toBeFalse()
        ->and(BackupService::ownsName('bob', 'bob.notes.txt'))->toBeFalse();
});

it('keeps the direct children of a snapshot folder, folders first', function () {
    $output = implode("\n", [
        json_encode(['time' => '2026-10-06T01:00:00Z', 'struct_type' => 'snapshot', 'short_id' => '4f2a91c0']),
        json_encode(['name' => 'web', 'type' => 'dir', 'path' => '/home/bob/web', 'mode' => 2147484141, 'mtime' => '2026-10-05T10:00:00Z', 'struct_type' => 'node']),
        json_encode(['name' => 'a.txt', 'type' => 'file', 'path' => '/home/bob/a.txt', 'size' => 12, 'mode' => 420, 'mtime' => '2026-10-05T10:00:00Z', 'struct_type' => 'node']),
        json_encode(['name' => 'deep.txt', 'type' => 'file', 'path' => '/home/bob/web/deep.txt', 'size' => 1, 'struct_type' => 'node']),
        json_encode(['name' => 'link', 'type' => 'symlink', 'path' => '/home/bob/link', 'linktarget' => '/etc', 'struct_type' => 'node']),
        'not json',
    ]);

    $listing = SnapshotListing::parse($output, '/home/bob/');

    expect(array_column($listing->entries, 'name'))->toBe(['web', 'a.txt', 'link'])
        ->and($listing->entries[0]['dir'])->toBeTrue()
        ->and($listing->entries[0]['mode'])->toBe('755')
        ->and($listing->entries[1])->toMatchArray(['type' => 'f', 'size' => 12, 'mode' => '644', 'writable' => false])
        ->and($listing->entries[2]['type'])->toBe('l');
});
