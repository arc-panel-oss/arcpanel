<?php

use App\Auth\MailPasswordChange;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

// Mail password change for the webmail clients (/reset/mail/, the 1.x
// web/reset/mail endpoint that E8 removed).

beforeEach(function () {
    $this->fake = fakeArc();
    $this->fake->on('v-list-sys-ips')->returnsJson(['10.0.0.5' => ['NAT' => '198.51.100.7']]);
    $this->fake->on('v-search-domain-owner')->using(fn (array $a) => $a[0] === 'example.com' ? [0, "bob\n"] : [3, '']);
    $this->fake->on('v-get-mail-account-value')->returns('{BLF-CRYPT}'.password_hash('Old-Pass1', PASSWORD_BCRYPT)."\n");
    $this->fake->on('v-change-mail-account-password')->returns();
    RateLimiter::clear('reset.mail:info@example.com');
});

function mailReset(array $data, array $server = []): TestResponse
{
    return test()->withServerVariables($server)->post('/reset/mail/', $data);
}

it('changes the password when the old one matches', function () {
    mailReset(['email' => 'info@example.com', 'password' => 'Old-Pass1', 'new' => 'N3w-Pass!'])
        ->assertOk()->assertContent('==ok==')
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    $this->fake->assertRan('v-search-domain-owner', fn (array $args) => $args === ['example.com', 'mail']);
    $this->fake->assertRan('v-get-mail-account-value', fn (array $args) => $args === ['bob', 'example.com', 'info', 'md5']);
    expect($this->fake->calls('v-change-mail-account-password')[0]['args'])->toBe(['bob', 'example.com', 'info', 'N3w-Pass!']);
});

it('answers with the 1.x error lines and changes nothing', function (array $data, string $answer) {
    mailReset($data)->assertOk()->assertContent($answer);
    $this->fake->assertNotRan('v-change-mail-account-password');
})->with([
    'no address' => [['password' => 'Old-Pass1', 'new' => 'x'], 'error email address not provided'],
    'no old password' => [['email' => 'info@example.com', 'new' => 'x'], 'error old password not provided'],
    'no new password' => [['email' => 'info@example.com', 'password' => 'Old-Pass1'], 'error new password not provided'],
    'not an address' => [['email' => 'info', 'password' => 'Old-Pass1', 'new' => 'x'], 'error email address not valid'],
    'unknown domain' => [['email' => 'info@other.example', 'password' => 'Old-Pass1', 'new' => 'x'], 'error domain owner not found'],
    'wrong old password' => [['email' => 'info@example.com', 'password' => 'wrong', 'new' => 'x'], 'error old password does not match'],
]);

it('reports a failing command', function () {
    $this->fake->on('v-change-mail-account-password')->returnsExit(2);

    mailReset(['email' => 'info@example.com', 'password' => 'Old-Pass1', 'new' => 'N3w-Pass!'])
        ->assertOk()->assertContent('error v-change-mail-account-password returned non-zero: 2');
});

it('accepts the server addresses and their NAT addresses only', function (string $remote, int $status) {
    mailReset(['email' => 'info@example.com', 'password' => 'Old-Pass1', 'new' => 'N3w-Pass!'], ['REMOTE_ADDR' => $remote, 'SERVER_ADDR' => '10.0.0.9'])
        ->assertStatus($status);
})->with([
    'loopback' => ['127.0.0.1', 200],
    'the address it came in on' => ['10.0.0.9', 200],
    'a server IP' => ['10.0.0.5', 200],
    'a NAT address' => ['198.51.100.7', 200],
    'another host' => ['203.0.113.9', 403],
]);

it('refuses requests through a proxy and from other hosts without running a command', function () {
    test()->withHeader('X-Forwarded-For', '127.0.0.1')
        ->post('/reset/mail/', ['email' => 'info@example.com', 'password' => 'Old-Pass1', 'new' => 'x'])
        ->assertForbidden()->assertContent('');
    mailReset(['email' => 'info@example.com', 'password' => 'Old-Pass1', 'new' => 'x'], ['REMOTE_ADDR' => '203.0.113.9'])
        ->assertForbidden()->assertContent('');

    $this->fake->assertNotRan('v-search-domain-owner');
    $this->fake->assertNotRan('v-change-mail-account-password');
});

it('stops after five wrong old passwords for an address', function () {
    for ($i = 0; $i < MailPasswordChange::ATTEMPTS; $i++) {
        mailReset(['email' => 'info@example.com', 'password' => 'wrong', 'new' => 'x'])->assertContent('error old password does not match');
    }

    mailReset(['email' => 'info@example.com', 'password' => 'Old-Pass1', 'new' => 'N3w-Pass!'])
        ->assertStatus(429)->assertContent('error too many attempts');
    $this->fake->assertNotRan('v-change-mail-account-password');
});

it('needs neither a session nor a CSRF token', function () {
    expect(app('router')->getRoutes()->getByName('reset.mail')->gatherMiddleware())
        ->not->toContain('auth.arc')->not->toContain('guest.arc');

    $middleware = new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        public function excepted(Request $request): bool
        {
            return $this->inExceptArray($request);
        }
    };
    expect($middleware->excepted(Request::create('/reset/mail/', 'POST')))->toBeTrue();
});
