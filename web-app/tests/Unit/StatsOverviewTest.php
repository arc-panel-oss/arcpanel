<?php

use App\Services\Stats\StatsMonth;
use App\View\StatsOverview;

// What the Statistics page shows of the months (E7 batch 2 spec §9.4).

function overviewMonth(string $date, array $values): StatsMonth
{
    return StatsMonth::fromArray($date, array_merge([
        'IP_OWNED' => '0', 'DISK_QUOTA' => 'unlimited', 'BANDWIDTH' => 'unlimited', 'U_DISK' => '0', 'U_BANDWIDTH' => '0',
        'U_DISK_WEB' => '0', 'U_DISK_DB' => '0', 'U_DISK_MAIL' => '0', 'U_DISK_DIRS' => '0',
    ], $values));
}

it('compares the quotas of the newest month with the month before', function () {
    $overview = new StatsOverview([
        overviewMonth('2026-10-01', ['U_DISK' => '3072', 'DISK_QUOTA' => '4096', 'U_BANDWIDTH' => '100']),
        overviewMonth('2026-09-01', ['U_DISK' => '1024', 'U_BANDWIDTH' => '300']),
    ], 'user', false);

    [$bandwidth, $disk] = $overview->quotas();

    expect($bandwidth)->toMatchArray(['label' => 'Bandwidth', 'limit' => null, 'percent' => null, 'delta' => '−200 MB'])
        ->and($disk)->toMatchArray(['valueLabel' => '3 GB', 'limitLabel' => '4 GB', 'percent' => 75, 'delta' => '+2 GB']);
});

it('has no change lines and no month chart with a single month', function () {
    $overview = new StatsOverview([overviewMonth('2026-10-01', ['U_WEB_DOMAINS' => '2'])], 'user', false);

    expect($overview->quotas()[0]['delta'])->toBeNull()
        ->and($overview->tiles()[0])->toMatchArray(['key' => 'U_WEB_DOMAINS', 'value' => '2', 'delta' => null])
        ->and($overview->trend('U_DISK'))->toBe([]);
});

it('splits the disk usage into its parts, or nothing when no space is used', function () {
    $used = new StatsOverview([overviewMonth('2026-10-01', ['U_DISK_WEB' => '300', 'U_DISK_DB' => '100'])], 'user', false);
    $empty = new StatsOverview([overviewMonth('2026-10-01', [])], 'user', false);

    expect(array_column($used->diskParts(), 'percent', 'label'))
        ->toBe(['Web' => 75.0, 'Databases' => 25.0, 'Mail' => 0.0, 'User Directory' => 0.0])
        ->and($empty->diskParts())->toBe([]);
});

it('counts the change of each resource and shows users and IP addresses only where they apply', function () {
    $months = [
        overviewMonth('2026-10-01', ['U_WEB_DOMAINS' => '5', 'U_DATABASES' => '1', 'U_USERS' => '3', 'IP_OWNED' => '0']),
        overviewMonth('2026-09-01', ['U_WEB_DOMAINS' => '3', 'U_DATABASES' => '2', 'U_USERS' => '3']),
    ];

    $tiles = array_column((new StatsOverview($months, 'server', true))->tiles(), 'delta', 'key');
    expect($tiles)->toMatchArray(['U_WEB_DOMAINS' => 2, 'U_DATABASES' => -1, 'U_USERS' => 0, 'IP_OWNED' => 0]);

    $userTiles = array_column((new StatsOverview($months, 'user', false))->tiles(), 'key');
    expect($userTiles)->not->toContain('U_USERS')->not->toContain('IP_OWNED');
});

it('scales the month chart to the largest month, oldest first', function () {
    $overview = new StatsOverview([
        overviewMonth('2026-10-01', ['U_BANDWIDTH' => '50']),
        overviewMonth('2026-09-01', ['U_BANDWIDTH' => '200']),
        overviewMonth('2026-08-01', ['U_BANDWIDTH' => '0']),
    ], 'user', false);

    expect(array_map(fn ($p) => [$p['title'], $p['percent'], $p['latest']], $overview->trend('U_BANDWIDTH')))->toBe([
        ['Aug 2026', 0.0, false],
        ['Sep 2026', 100.0, false],
        ['Oct 2026', 25.0, true],
    ]);
});
