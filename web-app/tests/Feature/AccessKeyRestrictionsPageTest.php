<?php

use App\Auth\SudoMode;
use Illuminate\Support\Carbon;

// The Access keys page with the address list and expiry of a key (E43 M2, B6;
// docs/features/specs/2026-10-16-004-e43-api-v2-fleet-additions-design.md §9).

const PAGE_KEY_ACTIVE = 'AcTiVeKeYaCtIvEkEy01';
const PAGE_KEY_SOON = 'SoOnKeYsOoNkEySoOn02';
const PAGE_KEY_EXPIRED = 'ExPiReDkEyExPiReD003';

beforeEach(function () {
    Carbon::setTestNow('2026-10-17 12:00:00');
    $this->fake = fakeArc(['API_SYSTEM' => '2']);
    fakeServerUsers($this->fake);
    $key = fn (string $id, string $comment, string $expires, array $ips) => [
        'ACCESS_KEY_ID' => $id, 'USER' => 'bob', 'PERMISSIONS' => ['v2-observe'], 'COMMENT' => $comment,
        'TIME' => '10:00:00', 'DATE' => '2026-10-01', 'EXPIRES' => $expires, 'ALLOWED_IPS' => $ips,
    ];
    $this->rows = [
        PAGE_KEY_ACTIVE => $key(PAGE_KEY_ACTIVE, 'fleet read', (string) Carbon::parse('2027-04-01 00:00:00', 'UTC')->getTimestamp(), ['203.0.113.7', '2001:db8::/48']),
        PAGE_KEY_SOON => $key(PAGE_KEY_SOON, 'soon', (string) Carbon::parse('2026-10-24 00:00:00', 'UTC')->getTimestamp(), []),
        PAGE_KEY_EXPIRED => $key(PAGE_KEY_EXPIRED, 'old', (string) Carbon::parse('2026-10-10 00:00:00', 'UTC')->getTimestamp(), []),
    ];
    $this->fake->on('v-list-access-keys')->returnsJson($this->rows);
    $this->fake->on('v-list-access-key')->using(fn (array $args) => [0, (string) json_encode($this->rows[$args[0]] ?? [])]);
    $this->fake->on('v-list-apis')->returnsJson([
        'v2-observe' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
        'v2-read' => ['COMMANDS' => ['v-list-user'], 'ROLE' => 'user', 'API_VERSION' => '2'],
    ]);
    $this->fake->on('v-add-access-key')->using(fn (array $args) => [0, (string) json_encode([
        'ACCESS_KEY_ID' => 'NeWkEyNeWkEyNeWkEy09', 'SECRET_ACCESS_KEY' => 'fresh-secret-abcdef', 'USER' => $args[0],
        'PERMISSIONS' => explode(',', $args[1]), 'COMMENT' => $args[2], 'TIME' => '12:00:00', 'DATE' => '2026-10-17',
        'ALLOWED_IPS' => count($args) === 6 && $args[3] !== '' ? explode(',', $args[3]) : [], 'EXPIRES' => count($args) === 6 ? $args[4] : '',
    ])]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows the address list and the expiry of each key, and marks keys that expire soon or expired', function () {
    signIn('bob', 'user');

    $this->get('/access-keys/bob')->assertOk()
        ->assertSeeInOrder(['Addresses', 'Expires'])
        ->assertSeeInOrder(['fleet read', '203.0.113.7', '2001:db8::/48', '2027-04-01 00:00 UTC'])
        ->assertSeeInOrder(['soon', 'Any address', '2026-10-24 00:00 UTC', 'Expires soon'])
        ->assertSeeInOrder(['old', '2026-10-10 00:00 UTC', 'Expired']);
});

it('shows the restrictions on the page of one key', function () {
    signIn('bob', 'user');

    $this->get('/access-keys/bob/'.PAGE_KEY_EXPIRED)->assertOk()
        ->assertSee('Expired')->assertSee('2026-10-10 00:00 UTC')->assertSee('Any address');
});

it('offers the address list and the expiry in the create form', function () {
    signIn('bob', 'user');

    $this->get('/access-keys/bob/create')->assertOk()
        ->assertSeeInOrder(['Comment', 'Allowed addresses', 'One address or network per line', 'Expires (UTC)', 'Permissions']);
});

it('creates a key with an address list and an expiry', function () {
    signIn('bob', 'user', ['SUDO_UNTIL' => now()->getTimestamp() + SudoMode::SECONDS]);

    $this->post('/access-keys/bob', [
        'sets' => ['v2-observe'], 'comment' => 'fleet',
        'allowed_ips' => "203.0.113.7\n 2001:db8::/48 ,198.51.100.0/24\n", 'expires_at' => '2027-01-01T08:30',
    ])->assertOk()->assertSee('fresh-secret-abcdef');
    $this->fake->assertRan('v-add-access-key', fn ($a) => $a === [
        'bob', 'v2-observe', 'fleet', '203.0.113.7,2001:db8::/48,198.51.100.0/24',
        (string) Carbon::parse('2027-01-01 08:30:00', 'UTC')->getTimestamp(), 'json',
    ]);
});

it('refuses an address list or an expiry that v-add-access-key would refuse, next to the field', function (array $input, string $field) {
    signIn('bob', 'user', ['SUDO_UNTIL' => now()->getTimestamp() + SudoMode::SECONDS]);

    $this->post('/access-keys/bob', ['sets' => ['v2-read'], ...$input])->assertSessionHasErrors($field);
    $this->fake->assertNotRan('v-add-access-key');
})->with([
    'not an address' => [['allowed_ips' => 'example.com'], 'allowed_ips'],
    'a bad network' => [['allowed_ips' => '203.0.113.0/40'], 'allowed_ips'],
    'more than 20' => [['allowed_ips' => implode("\n", array_map(fn (int $i) => '198.51.100.'.$i, range(1, 21)))], 'allowed_ips'],
    'in less than an hour' => [['expires_at' => '2026-10-17T12:30'], 'expires_at'],
    'more than three years ahead' => [['expires_at' => '2029-10-18T12:00'], 'expires_at'],
    'not a time' => [['expires_at' => 'soon'], 'expires_at'],
]);
