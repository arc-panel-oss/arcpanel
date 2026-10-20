<?php

use App\View\UpdateCenterPage;

// The texts of the Updates page (E33 phase 1 spec §8): the line under each
// release, the policy summary and the history labels.

function updatePage(array $overrides = []): UpdateCenterPage
{
    return new UpdateCenterPage([
        'policy' => 'patch', 'windowDays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'windowHours' => '03-05',
        'delayDays' => 2, 'paused' => null, 'nextWindow' => '2026-11-03 03:00', 'version' => '2.5.1', 'line' => '2.5',
        ...$overrides,
    ]);
}

it('sums up the window and the delay', function () {
    expect(updatePage()->windowSummary())->toBe('Mon–Sun 03:00–05:00, after 2 days')
        ->and(updatePage(['windowDays' => ['sat', 'sun'], 'windowHours' => '23-02', 'delayDays' => 1])->windowSummary())->toBe('Sat, Sun 23:00–02:00, after 1 day')
        ->and(updatePage(['delayDays' => 0])->windowSummary())->toBe('Mon–Sun 03:00–05:00, no delay')
        ->and(updatePage(['windowHours' => '22-24'])->windowHours())->toBe([22, 24]);
});

it('explains why a release is or is not installed on its own', function (array $release, array $overrides, ?string $tone, string $text) {
    $line = updatePage($overrides)->reasonLine($release);

    expect($line['tone'])->toBe($tone)->and($line['text'])->toBe($text);
})->with([
    'eligible' => [['ELIGIBLE' => true, 'REASON' => ''], [], 'ok', 'Installs on its own in the next window, Nov 3, 03:00.'],
    'security' => [['ELIGIBLE' => true, 'SECURITY' => true], [], 'ok', 'Installs on its own in the next window, Nov 3, 03:00. Security release, so the delay does not apply.'],
    'delay' => [['REASON' => 'delay until 2026-11-05'], [], null, 'Waits out the 2-day delay; installs from Nov 5, 2026.'],
    'window' => [['REASON' => 'outside window'], [], null, 'Outside the update window; installs Nov 3, 03:00.'],
    'attention, minor' => [['REASON' => 'attention', 'KIND' => 'minor'], [], 'warn', 'A new line needs an administrator. Automatic updates stay on 2.5.'],
    'attention, patch' => [['REASON' => 'attention', 'KIND' => 'patch'], [], 'warn', 'Needs an administrator: read the items below, then install it by hand.'],
    'withdrawn' => [['REASON' => 'withdrawn', 'WITHDRAWN' => ['date' => '2026-10-31', 'reason' => 'breaks NGINX.']], [], 'danger', 'Withdrawn on Oct 31, 2026: breaks NGINX. It will not be installed.'],
    'min_from' => [['REASON' => 'min_from 2.5.1'], [], null, 'Needs 2.5.1 or newer first. Update to 2.5.1, then to this release.'],
    'paused' => [['REASON' => 'paused'], [], null, 'Automatic updates are paused. Resume them, or install it by hand.'],
    'not available' => [['REASON' => 'not available'], [], null, 'Not in the APT repository for this server yet.'],
    'notify' => [['REASON' => 'policy'], ['policy' => 'notify'], null, 'The policy is Notify only. Install it by hand.'],
    'off' => [['REASON' => 'policy'], ['policy' => 'off', 'nextWindow' => null], null, 'Automatic updates are off. Install it by hand.'],
]);

it('labels the runs of the history', function () {
    expect(UpdateCenterPage::result('rolled-back'))->toBe(['tone' => 'warn', 'label' => 'Rolled back'])
        ->and(UpdateCenterPage::result('skipped'))->toBe(['tone' => null, 'label' => 'Skipped'])
        ->and(UpdateCenterPage::trigger('api'))->toBe('API')
        ->and(UpdateCenterPage::took('2026-11-03 02:14:05', '2026-11-03 02:16:40'))->toBe('2 min 35 s')
        ->and(UpdateCenterPage::took('2026-11-03 02:14:05', '2026-11-03 02:14:09'))->toBe('4 s')
        ->and(UpdateCenterPage::took('2026-11-03 02:14:05', ''))->toBeNull()
        ->and(UpdateCenterPage::time('not a time'))->toBe('not a time');
});
