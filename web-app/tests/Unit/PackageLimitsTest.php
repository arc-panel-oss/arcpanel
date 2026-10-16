<?php

use App\Services\Packages\PackageLimits;

// Limits of reseller packages (E39 W4 spec §8.2): the table that
// test/arcpanel-tenancy.bats runs against func/tenancy.sh.

/** @return array<string, array{string, string, string, bool}> */
function packageLimitCases(): array
{
    $cases = [];
    foreach (file(__DIR__.'/../Fixtures/package-limits.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value, $limit, $expected] = explode("\t", rtrim($line, "\r"));
        $cases["{$key} {$value} against {$limit}"] = [$key, $value, $limit, $expected === 'above'];
    }

    return $cases;
}

it('compares a value with a limit after their units', function (string $key, string $value, string $limit, bool $above) {
    expect(PackageLimits::above($key, $value, $limit))->toBe($above);
})->with(packageLimitCases());

it('reads the shared table', function () {
    expect(count(packageLimitCases()))->toBeGreaterThan(40);
});

it('normalizes each unit to its base', function () {
    expect(PackageLimits::normalize('MEMORY_LIMIT', '1G'))->toBe(1073741824)
        ->and(PackageLimits::normalize('MEMORY_LIMIT', '512'))->toBe(512)
        ->and(PackageLimits::normalize('DOCKER_MEMORY', '512'))->toBe(536870912)
        ->and(PackageLimits::normalize('DOCKER_CPUS', '0.5'))->toBe(50)
        ->and(PackageLimits::normalize('CPU_QUOTA_PERIOD', '2s'))->toBe(2000)
        ->and(PackageLimits::normalize('CPU_QUOTA', '50%'))->toBe(50)
        ->and(PackageLimits::normalize('BACKUPS_INCREMENTAL', 'yes'))->toBe(1)
        ->and(PackageLimits::normalize('WEB_DOMAINS', 'unlimited'))->toBe('unlimited')
        ->and(PackageLimits::normalize('WEB_DOMAINS', "10\n"))->toBeNull()
        ->and(PackageLimits::normalize('MEMORY_LIMIT', '9999999999G'))->toBeNull();
});
