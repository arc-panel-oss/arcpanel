<?php

use App\View\JobFormat;

// A number of minutes in words for the alert rules (cooldown, FOR)

it('says a number of minutes in days, hours and minutes', function (int $minutes, string $text) {
    expect(JobFormat::minutes($minutes))->toBe($text);
})->with([
    [1, '1 min'],
    [30, '30 min'],
    [60, '1 h'],
    [90, '1 h 30 min'],
    [1440, '1 day'],
    [1500, '1 day 1 h'],
    [2880, '2 days'],
    [43200, '30 days'],
]);
