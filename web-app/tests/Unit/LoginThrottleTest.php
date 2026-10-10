<?php

use App\Auth\LoginThrottle;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    cache()->flush();
    $this->throttle = app(LoginThrottle::class);
});

it('clears the user name and 2FA counters, but not the address counters', function () {
    foreach (range(1, LoginThrottle::USER_FAILURES) as $attempt) {
        $this->throttle->failed('Bob', '198.51.100.'.$attempt);
    }
    foreach (range(1, LoginThrottle::SECOND_FACTOR_FAILURES) as $attempt) {
        expect($this->throttle->attemptSecondFactor('bob'))->toBeNull();
    }
    foreach (range(1, LoginThrottle::PAIR_ATTEMPTS) as $attempt) {
        $this->throttle->failed('carol', '203.0.113.1');
    }

    expect($this->throttle->attempt('bob', '203.0.113.2', fn () => false))->toBeGreaterThan(0)
        ->and($this->throttle->secondFactor('bob'))->toBeGreaterThan(0);

    $this->throttle->clear('bob');

    expect($this->throttle->attempt('bob', '203.0.113.2', fn () => false))->toBeNull()
        ->and($this->throttle->secondFactor('bob'))->toBeNull()
        ->and($this->throttle->attempt('carol', '203.0.113.1', fn () => false))->toBeGreaterThan(0);
});

it('asks for the allow list only when the user limit is full', function () {
    $asked = 0;
    $allowList = function () use (&$asked) {
        $asked++;

        return true;
    };

    $this->throttle->attempt('bob', '203.0.113.3', $allowList);
    expect($asked)->toBe(0);

    foreach (range(1, LoginThrottle::USER_FAILURES) as $attempt) {
        $this->throttle->failed('bob', '198.51.100.'.$attempt);
    }
    expect($this->throttle->attempt('bob', '203.0.113.3', $allowList))->toBeNull()
        ->and($asked)->toBe(1);
});

it('never counts an attempt that only passed the check', function () {
    foreach (range(1, LoginThrottle::PAIR_ATTEMPTS + 1) as $attempt) {
        expect($this->throttle->attempt('bob', '203.0.113.6', fn () => false))->toBeNull();
    }
});

it('refuses the attempt when the lock cannot be taken instead of skipping the check', function () {
    $lock = Cache::lock('login-throttle:ip:203.0.113.4', 10);
    expect($lock->get())->toBeTrue();

    expect($this->throttle->attempt('bob', '203.0.113.4', fn () => false))->toBe(60);

    $lock->release();
});
