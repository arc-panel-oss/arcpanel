<?php

use App\Api\CronSchedule;
use App\Rules\CronField;
use Illuminate\Validation\ValidationException;

// The cron field grammar of E39 W5 (docs/features/specs/2026-10-21-002-e39-w5-resources-and-hardening-design.md §6.1),
// the same cases as test/checks.bats.

it('accepts the values of the cron builder, imports and zero padding', function (string $field, string $value) {
    expect(CronField::valid($field, $value))->toBeTrue();
})->with([
    ['min', '*'], ['min', '*/5'], ['min', '*/30'], ['min', '*/59'], ['min', '0'], ['min', '00'], ['min', '05'],
    ['min', '0-59/15'], ['min', '1,15,30,45'], ['hour', '*/12'], ['hour', '23'], ['hour', '0-23/2'],
    ['day', '1-31/2'], ['day', '*/15'], ['day', '31'], ['month', '1-11/2'], ['month', '*/6'], ['month', '12'],
    ['wday', '1,2,3,4,5'], ['wday', '0,6'], ['wday', '7'], ['wday', '1-5'],
]);

it('refuses out-of-range numbers, names, macros, quotes and control characters', function (string $field, string $value) {
    expect(CronField::valid($field, $value))->toBeFalse();
})->with([
    ['min', ''], ['min', '60'], ['min', '*/60'], ['min', '*/0'], ['min', '5/15'], ['min', '5 * * * * evil'],
    ['min', "5\nevil"], ['min', "5\rx"], ['min', "5\tx"], ['min', "5'"], ['min', '5"'], ['min', '1,'], ['min', ',1'],
    ['min', '1,,2'], ['min', '30-10'], ['min', '100'], ['min', '-1'], ['min', '*-5'], ['min', '1|2'],
    ['hour', '24'], ['day', '0'], ['day', '32'], ['month', '0'], ['month', '13'], ['month', 'jan'],
    ['wday', '8'], ['wday', 'mon'], ['min', '@daily'], ['min', str_repeat('1,', 64).'1'],
]);

it('parses an API v2 schedule only when every field passes', function () {
    expect(CronSchedule::parse('*/5 * * * 1-5'))->toBe(['min' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'wday' => '1-5']);
    expect(fn () => CronSchedule::parse("5 * * * 1\t"))->toThrow(ValidationException::class);
    expect(fn () => CronSchedule::parse("5' * * * *"))->toThrow(ValidationException::class);
    expect(fn () => CronSchedule::parse('60 * * * *'))->toThrow(ValidationException::class);
});
