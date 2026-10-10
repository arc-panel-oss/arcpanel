<?php

use App\Auth\ArrayLegacySession;
use App\Services\Terminal\TerminalTickets;

// Web terminal tickets (E39 W2 spec §8, D13): the panel signs a short,
// single-use ticket for the effective user; the terminal server checks it.

beforeEach(function () {
    $this->fake = fakeArc(['WEB_TERMINAL' => 'true']);
    fakeServerUsers($this->fake, ['bob' => ['SHELL' => 'bash'], 'arcadmin' => ['SHELL' => 'bash']]);
    $this->key = str_repeat('ab', 32);
    $this->keyFile = tempnam(sys_get_temp_dir(), 'arc-terminal-key');
    file_put_contents($this->keyFile, $this->key."\n");
    config(['arc.terminal_key' => $this->keyFile]);
});

afterEach(function () {
    @unlink($this->keyFile);
});

/** @return array{u: string, exp: int, n: string} the payload, after checking the signature */
function ticketPayload(string $ticket, string $key): array
{
    [$payload, $signature] = explode('.', $ticket);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, (string) hex2bin($key), true)), '+/', '-_'), '=');
    expect(hash_equals($expected, $signature))->toBeTrue();

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
}

it('signs a ticket for the user that lives 30 seconds', function () {
    signIn('bob', 'user');

    $ticket = $this->postJson(route('terminal.ticket'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('ticket');

    expect($ticket)->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/');
    $payload = ticketPayload($ticket, $this->key);
    expect($payload['u'])->toBe('bob')
        ->and($payload['exp'])->toBeGreaterThan(time() + TerminalTickets::LIFETIME - 5)
        ->and($payload['exp'])->toBeLessThanOrEqual(time() + TerminalTickets::LIFETIME)
        ->and($payload['n'])->toMatch('/^[0-9a-f]{32}$/');

    // Every ticket is new
    $other = $this->postJson(route('terminal.ticket'))->json('ticket');
    expect(ticketPayload($other, $this->key)['n'])->not->toBe($payload['n']);
});

it('signs the ticket for the account an administrator looks at', function () {
    signIn('arcadmin', 'admin', ['look' => 'bob']);

    $ticket = $this->postJson(route('terminal.ticket'))->assertOk()->json('ticket');

    expect(ticketPayload($ticket, $this->key)['u'])->toBe('bob');
});

it('gives no ticket when the policy refuses the terminal', function (Closure $setup) {
    $setup($this->fake);

    $this->postJson(route('terminal.ticket'))->assertForbidden();
})->with([
    'no shell' => [function ($fake) {
        fakeServerUsers($fake, ['bob' => ['SHELL' => 'nologin']]);
        signIn('bob', 'user');
    }],
    'read-only look at the protected administrator' => [function ($fake) {
        withSysConfig($fake, ['WEB_TERMINAL' => 'true', 'POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
        fakeServerUsers($fake, ['arcadmin' => ['SHELL' => 'bash'], 'carol' => ['ROLE' => 'admin', 'SHELL' => 'bash']]);
        signIn('carol', 'admin', ['look' => 'arcadmin']);
    }],
    'terminal turned off' => [function ($fake) {
        withSysConfig($fake, ['WEB_TERMINAL' => 'false']);
        fakeServerUsers($fake, ['bob' => ['SHELL' => 'bash']]);
        signIn('bob', 'user');
    }],
]);

it('gives no ticket to a reseller acting as a customer', function () {
    fakeResellers($this->fake, ['hostco' => ['SHELL' => 'bash'], 'bob' => ['SHELL' => 'bash']]);
    signIn('hostco', 'reseller', ['arc_look' => 'bob']);

    $this->postJson(route('terminal.ticket'))->assertForbidden();
});

it('answers 503 without a key, and the guest gets no ticket', function () {
    signIn('bob', 'user');
    config(['arc.terminal_key' => $this->keyFile.'.missing']);
    $this->postJson(route('terminal.ticket'))->assertStatus(503);

    ArrayLegacySession::install(null);
    $this->postJson(route('terminal.ticket'))->assertStatus(401);
});

it('gives the page the address of the ticket', function () {
    signIn('bob', 'user');

    $this->get('/terminal')->assertOk()->assertSee(route('terminal.ticket'));
});
