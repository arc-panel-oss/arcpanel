<?php

use App\Arc\ExitCode;

// REST API v2, IP addresses of the server (E34 batch 6 spec §5.2).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $ips = commandFixture('v-list-sys-ips/default.json');
    $ips['10.0.0.12'] = ['OWNER' => 'bob', 'STATUS' => 'dedicated', 'NAME' => 'example.com', 'U_SYS_USERS' => 'bob,hostco', 'U_WEB_DOMAINS' => '2',
        'INTERFACE' => 'eth0', 'NETMASK' => '255.255.255.0', 'NAT' => '', 'TIME' => '11:00:00', 'DATE' => '2026-09-02'];
    $ips['2001:db8::10'] = ['OWNER' => 'arcadmin', 'STATUS' => 'shared', 'NAME' => '', 'U_SYS_USERS' => '', 'U_WEB_DOMAINS' => '0',
        'INTERFACE' => 'eth0', 'NETMASK' => '64', 'NAT' => '', 'TIME' => '12:00:00', 'DATE' => '2026-09-03'];
    $this->ips = $ips;
    $this->fake->on('v-list-sys-ips')->using(fn () => [0, (string) json_encode($this->ips)]);
    $this->fake->on('v-list-sys-ip')->using(fn (array $args) => isset($this->ips[$args[0]])
        ? [0, (string) json_encode([$args[0] => $this->ips[$args[0]]])]
        : [ExitCode::NotExist->value, 'Error: IP not found']);
    $this->fake->on('v-list-sys-interfaces')->returnsJson(['eth0', 'docker0']);
    $this->fake->on('v-add-sys-ip')->using(function (array $args) {
        $this->ips[$args[0]] = ['OWNER' => $args[3], 'STATUS' => $args[4], 'NAME' => $args[5], 'U_SYS_USERS' => '', 'U_WEB_DOMAINS' => '0',
            'INTERFACE' => $args[2], 'NETMASK' => $args[1], 'NAT' => $args[6], 'TIME' => '13:00:00', 'DATE' => '2026-10-08'];

        return [0, ''];
    });
    foreach (['v-change-sys-ip-status', 'v-change-sys-ip-owner', 'v-change-sys-ip-name', 'v-change-sys-ip-nat', 'v-delete-sys-ip', 'v-update-sys-ip'] as $command) {
        $this->fake->on($command)->returns();
    }
});

it('lists the addresses for administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/ips', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.ip', '10.0.0.10')
            ->assertJsonPath('data.0.nat', '203.0.113.10')
            ->assertJsonPath('data.2', [
                'ip' => '10.0.0.12', 'netmask' => '255.255.255.0', 'interface' => 'eth0', 'shared' => false, 'owner' => 'bob',
                'name' => 'example.com', 'nat' => null, 'web_domains' => 2, 'users' => ['bob', 'hostco'], 'created_at' => '2026-09-02T11:00:00',
            ]);
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every IP endpoint to non-administrators before any change', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->getJson('/api/v2/system/ips/10.0.0.12', $headers)->assertForbidden();
    $this->getJson('/api/v2/system/interfaces', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/ips', ['ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'eth0'], $headers)->assertForbidden();
    $this->patchJson('/api/v2/system/ips/10.0.0.12', ['name' => 'x.example'], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/ips/10.0.0.12', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/ips/refresh', [], $headers)->assertForbidden();
    foreach (['v-add-sys-ip', 'v-change-sys-ip-name', 'v-delete-sys-ip', 'v-update-sys-ip', 'v-list-sys-interfaces'] as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('reads an address, IPv6 URL-encoded or not, and the interfaces', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/ips/10.0.0.10', $headers)->assertOk()->assertJsonPath('data.shared', true);
    $this->getJson('/api/v2/system/ips/2001%3Adb8%3A%3A10', $headers)->assertOk()->assertJsonPath('data.ip', '2001:db8::10');
    $this->getJson('/api/v2/system/ips/2001:db8::10', $headers)->assertOk()->assertJsonPath('data.netmask', '64');
    $this->getJson('/api/v2/system/ips/10.0.0.99', $headers)->assertNotFound();
    $this->getJson('/api/v2/system/interfaces', $headers)->assertOk()
        ->assertExactJson(['data' => [['name' => 'eth0'], ['name' => 'docker0']], 'meta' => ['total' => 2]]);
    $this->getJson('/api/v2/system/interfaces?limit=1&offset=1', $headers)->assertOk()
        ->assertJsonPath('data', [['name' => 'docker0']])->assertJsonPath('meta.limit', 1);
});

it('adds an address with the arguments of the page and a key holding only v2-server', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/ips', [
        'ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'eth0', 'owner' => 'hostco', 'shared' => false,
        'name' => 'shop.example.com', 'nat' => '203.0.113.20',
    ], $headers)->assertCreated()
        ->assertHeader('Location', route('api.v2.system.ips.show', ['ip' => '10.0.0.20']))
        ->assertJsonPath('data.owner', 'hostco')->assertJsonPath('data.shared', false)->assertJsonPath('data.nat', '203.0.113.20');
    $this->fake->assertRan('v-add-sys-ip', fn ($a) => $a === ['10.0.0.20', '255.255.255.0', 'eth0', 'hostco', 'dedicated', 'shop.example.com', '203.0.113.20']);

    // Shared, owned by the root administrator by default
    $this->postJson('/api/v2/system/ips', ['ip' => '10.0.0.21', 'netmask' => '255.255.255.0', 'interface' => 'docker0'], $headers)->assertCreated();
    $this->fake->assertRan('v-add-sys-ip', fn ($a) => $a === ['10.0.0.21', '255.255.255.0', 'docker0', 'arcadmin', 'shared', '', '']);
});

it('refuses an unknown interface or owner, a bad address and a duplicate', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $body = ['ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'eth0'];

    $this->postJson('/api/v2/system/ips', ['interface' => 'wlan9'] + $body, $headers)->assertStatus(422)->assertJsonPath('error.fields.interface.0', 'This network interface does not exist.');
    $this->postJson('/api/v2/system/ips', ['owner' => 'ghost'] + $body, $headers)->assertStatus(422)->assertJsonPath('error.fields.owner.0', 'This user does not exist.');
    $this->postJson('/api/v2/system/ips', ['ip' => '10.0.0.300'] + $body, $headers)->assertStatus(422)->assertJsonPath('error.fields.ip.0', fn ($m) => is_string($m));
    $this->postJson('/api/v2/system/ips', ['name' => "a\nb"] + $body, $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/ips', ['ip' => '10.0.0.12'] + $body, $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')->assertJsonPath('error.existing', '/api/v2/system/ips/10.0.0.12');
    $this->fake->assertNotRan('v-add-sys-ip');
});

it('changes only the fields sent, one command each in the order of the page', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson('/api/v2/system/ips/10.0.0.12?dry_run=1', ['shared' => true, 'owner' => 'arcadmin', 'name' => null, 'nat' => '203.0.113.12'], $headers)->assertOk()
        ->assertJsonPath('data.steps.*.command', ['v-change-sys-ip-status', 'v-change-sys-ip-owner', 'v-change-sys-ip-name', 'v-change-sys-ip-nat'])
        ->assertJsonPath('data.steps.0.args', ['10.0.0.12', 'shared'])
        ->assertJsonPath('data.steps.2.args', ['10.0.0.12', '']);
    $this->fake->assertNotRan('v-change-sys-ip-status');

    $this->patchJson('/api/v2/system/ips/10.0.0.12', ['name' => 'shop.example.com'], $headers)->assertOk()->assertJsonPath('data.ip', '10.0.0.12');
    $this->fake->assertRan('v-change-sys-ip-name', fn ($a) => $a === ['10.0.0.12', 'shop.example.com']);
    $this->fake->assertNotRan('v-change-sys-ip-status');
    $this->fake->assertNotRan('v-change-sys-ip-owner');
    $this->fake->assertNotRan('v-change-sys-ip-nat');

    // Unchanged values run nothing
    $this->patchJson('/api/v2/system/ips/10.0.0.12?dry_run=1', ['owner' => 'bob', 'shared' => false, 'name' => 'example.com'], $headers)->assertOk()
        ->assertJsonPath('data.steps', []);
    $this->patchJson('/api/v2/system/ips/10.0.0.12', ['owner' => 'ghost'], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/system/ips/10.0.0.99', ['name' => 'x.example'], $headers)->assertNotFound();
});

it('deletes an address and refreshes the list', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->deleteJson('/api/v2/system/ips/2001%3Adb8%3A%3A10', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-sys-ip', fn ($a) => $a === ['2001:db8::10']);
    $this->deleteJson('/api/v2/system/ips/10.0.0.99', [], $headers)->assertNotFound();

    $this->postJson('/api/v2/system/ips/refresh', [], $headers)->assertOk()->assertJsonPath('meta.total', 4);
    $this->fake->assertRan('v-update-sys-ip', fn ($a) => $a === []);
});

it('plans without running and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $this->postJson('/api/v2/system/ips?dry_run=1', ['ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'eth0'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Add IP address 10.0.0.20', 'command' => 'v-add-sys-ip', 'args' => ['10.0.0.20', '255.255.255.0', 'eth0', 'arcadmin', 'shared', '', '']]);
    $this->deleteJson('/api/v2/system/ips/10.0.0.12?dry_run=1', [], $headers)->assertOk()->assertJsonPath('data.steps.0.command', 'v-delete-sys-ip');
    $this->postJson('/api/v2/system/ips/refresh?dry_run=1', [], $headers)->assertOk()->assertJsonPath('data.steps.0.command', 'v-update-sys-ip');
    $this->fake->assertNotRan('v-add-sys-ip');
    $this->fake->assertNotRan('v-delete-sys-ip');
    $this->fake->assertNotRan('v-update-sys-ip');

    $read = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);
    $this->getJson('/api/v2/system/ips', $read)->assertOk();
    $this->postJson('/api/v2/system/ips', ['ip' => '10.0.0.20', 'netmask' => '255.255.255.0', 'interface' => 'eth0'], $read)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-add-sys-ip']);
    $this->patchJson('/api/v2/system/ips/10.0.0.12', ['name' => 'x.example'], $read)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-change-sys-ip-name']);
    $this->deleteJson('/api/v2/system/ips/10.0.0.12', [], $read)->assertForbidden()->assertJsonPath('error.commands', ['v-delete-sys-ip']);
    $this->postJson('/api/v2/system/ips/refresh', [], $read)->assertForbidden()->assertJsonPath('error.commands', ['v-update-sys-ip']);
    foreach (['v-add-sys-ip', 'v-change-sys-ip-name', 'v-delete-sys-ip', 'v-update-sys-ip'] as $command) {
        $this->fake->assertNotRan($command);
    }
});
