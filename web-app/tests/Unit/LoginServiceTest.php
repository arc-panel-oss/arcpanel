<?php

use App\Arc\ArcCommandFake;
use App\Auth\LoginContext;
use App\Auth\LoginService;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->fake = fakeArc();
    $this->context = new LoginContext('203.0.113.7', 'Mozilla/5.0 test', str_repeat('ab', 16));
    $this->service = app(LoginService::class);
});

/** Answer v-get-user-salt for bob with this method and salt. */
function saltFor(ArcCommandFake $fake, string $method, string $salt = 'abcdefgh'): void
{
    $fake->on('v-get-user-salt', ['bob', '203.0.113.7', 'json'])
        ->returnsJson(['bob' => ['METHOD' => $method, 'SALT' => $salt]]);
}

/** Let v-check-user-hash accept exactly $expected (read from the secret file argument). */
function acceptHash(ArcCommandFake $fake, string $expected): void
{
    $fake->on('v-check-user-hash')->using(
        fn (array $args) => $args[1] === $expected ? [0, ''] : [9, 'Error: password missmatch'],
    );
}

it('logs in with a sha-512 password the way the legacy page hashes it', function () {
    saltFor($this->fake, 'sha-512');
    $hash = str_replace('$rounds=5000', '', crypt('pa55word', '$6$rounds=5000$abcdefgh$'));
    acceptHash($this->fake, $hash);
    fakeUsers($this->fake, ['bob' => userRecord()]);

    $result = $this->service->attempt('bob', 'pa55word', $this->context);

    expect($result->succeeded())->toBeTrue()
        ->and($result->user['ROLE'])->toBe('user');
    $this->fake->assertRan('v-check-user-hash', fn ($args) => $args[0] === 'bob' && $args[2] === '203.0.113.7');
});

it('hashes md5 and des passwords like the legacy page', function (string $method, string $expected) {
    saltFor($this->fake, $method, 'xy');
    acceptHash($this->fake, $expected);
    fakeUsers($this->fake, ['bob' => userRecord()]);

    expect($this->service->attempt('bob', 'pa55word', $this->context)->succeeded())->toBeTrue();
})->with([
    'md5' => ['md5', crypt('pa55word', '$1$xy$')],
    'des' => ['des', crypt('pa55word', 'xy')],
]);

it('asks v-check-user-password for a yescrypt hash', function () {
    saltFor($this->fake, 'yescrypt');
    $this->fake->on('v-check-user-password', fn ($args) => $args[0] === 'bob' && $args[1] === 'pa55word' && $args[3] === 'yes')
        ->returns("\$y\$hash-from-server\n");
    acceptHash($this->fake, '$y$hash-from-server');
    fakeUsers($this->fake, ['bob' => userRecord()]);

    expect($this->service->attempt('bob', 'pa55word', $this->context)->succeeded())->toBeTrue();
});

it('rejects a wrong password, logs it and slows down', function () {
    saltFor($this->fake, 'sha-512');
    acceptHash($this->fake, 'something else');
    $this->fake->on('v-log-user-login')->returns();

    $result = $this->service->attempt('bob', 'wrong', $this->context);

    expect($result->succeeded())->toBeFalse()
        ->and($result->error)->toBe(LoginService::INVALID_CREDENTIALS);
    $this->fake->assertRan('v-log-user-login', fn ($args) => $args === [
        'bob', '203.0.113.7', 'failed', str_repeat('ab', 16), 'Mozilla/5.0 test',
    ]);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == 2, 1);
});

it('maps v-get-user-salt failures to the legacy messages', function (int $exit, string $message) {
    $this->fake->on('v-get-user-salt')->returnsExit($exit, 'Error');

    expect($this->service->attempt('bob', 'pw', $this->context)->error)->toBe($message);
    $this->fake->assertNotRan('v-log-user-login');
})->with([
    'suspended' => [5, LoginService::SUSPENDED],
    'unsupported hash' => [1, LoginService::UNSUPPORTED_HASH],
    'unknown user' => [9, LoginService::INVALID_CREDENTIALS],
]);

it('refuses users with login disabled or outside their IP list', function (array $user, string $reason) {
    saltFor($this->fake, 'des', 'xy');
    acceptHash($this->fake, crypt('pw', 'xy'));
    fakeUsers($this->fake, ['bob' => userRecord($user)]);
    $this->fake->on('v-log-user-login')->returns();

    $result = $this->service->attempt('bob', 'pw', $this->context);

    expect($result->error)->toBe(LoginService::INVALID_CREDENTIALS);
    $this->fake->assertRan('v-log-user-login', fn ($args) => $args[2] === 'failed' && $args[5] === 'yes' && $args[6] === $reason);
})->with([
    'disabled' => [['LOGIN_DISABLED' => 'yes'], 'Login disabled for this user'],
    'ip list' => [['LOGIN_USE_IPLIST' => 'yes', 'LOGIN_ALLOW_IPS' => '198.51.100.1, 198.51.100.2'], 'IP address not in allowed list'],
]);

it('accepts a user whose IP is in the list', function () {
    saltFor($this->fake, 'des', 'xy');
    acceptHash($this->fake, crypt('pw', 'xy'));
    fakeUsers($this->fake, ['bob' => userRecord(['LOGIN_USE_IPLIST' => 'yes', 'LOGIN_ALLOW_IPS' => '198.51.100.1, 203.0.113.7'])]);

    expect($this->service->attempt('bob', 'pw', $this->context)->succeeded())->toBeTrue();
});

it('asks for a second factor when the user has 2FA', function () {
    saltFor($this->fake, 'des', 'xy');
    acceptHash($this->fake, crypt('pw', 'xy'));
    fakeUsers($this->fake, ['bob' => userRecord(['TWOFA' => 'ABCDEF'])]);

    expect($this->service->attempt('bob', 'pw', $this->context)->needsSecondFactor())->toBeTrue();
});

it('refuses malformed user names without running a command', function (string $name) {
    expect($this->service->attempt($name, 'pw', $this->context)->succeeded())->toBeFalse();
    expect($this->fake->calls())->toBe([]);
})->with(['', 'a', '-bob', "bob'; id", str_repeat('a', 31)]);

it('checks 2FA codes and logs failures only after three wrong codes', function () {
    $this->fake->on('v-check-user-2fa')->returnsExit(9, 'Error');
    $this->fake->on('v-log-user-login')->returns();

    foreach ([0, 1, 2] as $failures) {
        $this->service->verifySecondFactor('bob', '123456', $failures, $this->context);
    }
    $this->fake->assertNotRan('v-log-user-login');

    $result = $this->service->verifySecondFactor('bob', '123456', 3, $this->context);
    expect($result->error)->toBe(LoginService::INVALID_2FA);
    $this->fake->assertRan('v-log-user-login', fn ($args) => $args[6] === LoginService::INVALID_2FA, 1);
});

it('refuses 2FA codes of ten characters or more without running a command', function () {
    expect($this->service->verifySecondFactor('bob', '1234567890', 0, $this->context)->succeeded())->toBeFalse();
    $this->fake->assertNotRan('v-check-user-2fa');
});

it('builds the legacy session keys on completion', function () {
    $this->fake->on('v-log-user-login')->returns();
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de', 'vi']);

    $patch = $this->service->complete('bob', userRecord(['LANGUAGE' => 'vi', 'THEME' => 'dark', 'ROLE' => 'admin']), $this->context);

    expect($patch)->toMatchArray([
        'user' => 'bob',
        'look' => '',
        'userContext' => 'admin',
        'user_combined_ip' => '203.0.113.7',
        'token' => str_repeat('ab', 16),
        'language' => 'vi',
        'userTheme' => 'dark',
    ])->and($patch)->not->toHaveKeys(['userSortOrder', 'login', 'failed_twofa']);
    $this->fake->assertRan('v-log-user-login', fn ($args) => $args === [
        'bob', '203.0.113.7', 'success', str_repeat('ab', 16), 'Mozilla/5.0 test',
    ]);
});

it('falls back to English and drops the theme when the policy forbids it', function () {
    $this->fake = fakeArc(['POLICY_USER_CHANGE_THEME' => 'no']);
    $this->fake->on('v-log-user-login')->returns();
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en', 'de']);

    $patch = app(LoginService::class)->complete('bob', userRecord(['LANGUAGE' => 'xx', 'THEME' => 'dark']), $this->context);

    expect($patch['language'])->toBe('en')
        ->and($patch['userTheme'])->toBeNull();
});
