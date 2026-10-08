<?php

use App\Arc\ExitCode;
use Illuminate\Support\Carbon;

// REST API v2, DNS (E34 batch 2 spec §6.1): bob (customer of hostco) has
// example.com and the suspended shop.example, carol (customer of resb) has
// carol.example. The server supports DNSSEC.

beforeEach(function () {
    $this->fake = fakeApi(['DNS_CLUSTER_SYSTEM' => 'arcpanel-zone', 'SUPPORT_DNSSEC' => 'yes', 'POLICY_USER_EDIT_DNS_TEMPLATES' => 'no']);
    fakeResellers($this->fake);
    fakeDnsZones($this->fake);
    $this->fake->on('v-restart-dns')->returns();
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
});

it('lists the zones of a user inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/dns-zones", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', count($response->json('data')));
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('describes a zone with typed fields', function () {
    $this->getJson('/api/v2/users/bob/dns-zones/example.com', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.domain', 'example.com')
        ->assertJsonPath('data.user', 'bob')
        ->assertJsonPath('data.ip', '203.0.113.10')
        ->assertJsonPath('data.template', 'default')
        ->assertJsonPath('data.ttl', 14400)
        ->assertJsonPath('data.expiry', '2027-10-06')
        ->assertJsonPath('data.soa', 'ns1.example.net')
        ->assertJsonPath('data.serial', 2026100601)
        ->assertJsonPath('data.dnssec', false)
        ->assertJsonPath('data.records', 6)
        ->assertJsonPath('data.suspended', false)
        ->assertJsonPath('data.created_at', '2026-10-06T10:00:00');
});

it('answers null for DNSSEC when the server has none', function () {
    withSysConfig($this->fake, ['DNS_CLUSTER_SYSTEM' => 'arcpanel', 'SUPPORT_DNSSEC' => 'yes', 'API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all']);

    $this->getJson('/api/v2/users/bob/dns-zones/shop.example', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.dnssec', null);
});

it('answers 404 for an unknown zone and for the DNSSEC key of a zone without it', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/dns-zones/nope.example', $headers)->assertNotFound()
        ->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->getJson('/api/v2/users/bob/dns-zones/example.com/dnssec', $headers)->assertNotFound();
});

it('returns the DNSSEC public key', function () {
    $this->getJson('/api/v2/users/bob/dns-zones/shop.example/dnssec', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.domain', 'shop.example')
        ->assertJsonPath('data.key_tag', 2371)
        ->assertJsonPath('data.flag', 257)
        ->assertJsonPath('data.algorithm', 13)
        ->assertJsonPath('data.key', 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==');
});

it('filters records by name and type and pages them', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/users/bob/dns-zones/example.com/records?name=@&type=TXT', $headers)->assertOk()
        ->assertJsonPath('data.0.id', 5)
        ->assertJsonPath('data.0.priority', null)
        ->assertJsonPath('data.0.ttl', null)
        ->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v2/users/bob/dns-zones/example.com/records?limit=2&offset=1', $headers)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta', ['total' => 6, 'limit' => 2, 'offset' => 1]);
});

it('creates a zone with a key that holds only v2-dns', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-list-dns-domain', ['bob', 'new.example', 'json'])->returnsJson(['new.example' => [
        'IP' => '203.0.113.10', 'TPL' => 'default', 'TTL' => '14400', 'EXP' => '2027-10-06', 'SOA' => 'ns1.example.net',
        'SERIAL' => '1', 'DNSSEC' => 'no', 'RECORDS' => '2', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '12:00:00',
    ]]);

    $this->postJson('/api/v2/users/bob/dns-zones', ['domain' => 'WWW.New.Example'], apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/dns-zones/new.example')
        ->assertJsonPath('data.domain', 'new.example');

    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a === [
        'bob', 'new.example', '203.0.113.10', 'ns1.example.net', 'ns2.example.net', '', '', '', '', '', '', 'no', 'no',
    ]);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('answers a zone the user has with 409 before any check', function () {
    $this->postJson('/api/v2/users/bob/dns-zones', ['domain' => 'example.com'], apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->assertNotRan('v-add-dns-domain');
});

it('refuses DNSSEC and a template the account may not choose', function () {
    withSysConfig($this->fake, ['DNS_CLUSTER_SYSTEM' => '', 'SUPPORT_DNSSEC' => 'no', 'API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all']);
    $headers = apiAs($this->fake, 'bob', 'user', area: 'dns');

    $this->postJson('/api/v2/users/bob/dns-zones', ['domain' => 'new.example', 'dnssec' => true], $headers)->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')->assertJsonStructure(['error' => ['fields' => ['dnssec']]]);
    $this->postJson('/api/v2/users/bob/dns-zones', ['domain' => 'new.example', 'template' => 'gmail'], $headers)->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN');
    $this->fake->assertNotRan('v-add-dns-domain');
});

it('plans a create without running it, and refuses a v2-read key', function () {
    $this->postJson('/api/v2/users/bob/dns-zones?dry_run=1', ['domain' => 'new.example', 'ttl' => 3600], apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.steps.0.command', 'v-add-dns-domain')
        ->assertJsonPath('data.steps.1.command', 'v-change-dns-domain-ttl');
    $this->fake->assertNotRan('v-add-dns-domain');

    $this->postJson('/api/v2/users/bob/dns-zones', ['domain' => 'new.example'], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()
        ->assertJsonPath('error.commands.0', 'v-add-dns-domain');
    $this->fake->assertNotRan('v-add-dns-domain');
});

it('names the failed step', function () {
    $this->fake->on('v-add-dns-domain')->returnsExit(ExitCode::Invalid, 'Error: invalid ip format');

    $this->postJson('/api/v2/users/bob/dns-zones', ['domain' => 'new.example'], apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'E_INVALID')
        ->assertJsonPath('error.step.command', 'v-add-dns-domain')
        ->assertJsonPath('error.applied', []);
});

it('updates the SOA and refuses a rebuild to anyone but an administrator', function () {
    $this->fake->on('v-change-dns-domain-soa')->returns();

    $this->patchJson('/api/v2/users/bob/dns-zones/example.com', ['soa' => 'ns2.example.net'], apiAs($this->fake, 'bob', 'user', area: 'dns'))->assertOk();
    $this->fake->assertRan('v-change-dns-domain-soa', fn ($a) => $a === ['bob', 'example.com', 'ns2.example.net', 'no']);

    $this->postJson('/api/v2/users/bob/dns-zones/example.com/rebuild', [], apiAs($this->fake, 'bob', 'user', area: 'dns'))->assertForbidden();
    $this->fake->on('v-rebuild-dns-domain')->returns();
    $this->postJson('/api/v2/users/bob/dns-zones/example.com/rebuild', [], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-rebuild-dns-domain', fn ($a) => $a === ['bob', 'example.com']);
});

it('refuses a line break or a NUL byte in a field before any command runs', function (string $soa) {
    $this->fake->on('v-change-dns-domain-soa')->returns();

    $this->patchJson('/api/v2/users/bob/dns-zones/example.com', ['soa' => $soa], apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonStructure(['error' => ['fields' => ['soa']]]);
    $this->fake->assertNotRan('v-change-dns-domain-soa');
})->with([
    'a final line break' => ["ns2.example.com\n"],
    'a final carriage return' => ["ns2.example.com\r"],
    'a NUL byte' => ["ns2.example.com\0"],
]);

it('suspends a zone once and deletes it', function () {
    $this->fake->on('v-suspend-dns-domain')->returns();
    $this->fake->on('v-delete-dns-domain')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'dns');

    $this->postJson('/api/v2/users/bob/dns-zones/shop.example/suspend', [], $headers)->assertOk();
    $this->fake->assertNotRan('v-suspend-dns-domain');
    $this->postJson('/api/v2/users/bob/dns-zones/example.com/suspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-suspend-dns-domain', fn ($a) => $a === ['bob', 'example.com']);

    $this->deleteJson('/api/v2/users/bob/dns-zones/example.com?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-delete-dns-domain');
    $this->deleteJson('/api/v2/users/bob/dns-zones/example.com', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-dns-domain', fn ($a) => $a === ['bob', 'example.com']);
});

it('creates a record with the next id and points a duplicate at the existing one', function () {
    $created = false;
    $this->fake->on('v-add-dns-record')->using(function () use (&$created) {
        $created = true;

        return [0, ''];
    });
    $this->fake->on('v-list-dns-records', ['bob', 'example.com', 'json'])->using(function () use (&$created) {
        $rows = commandFixture('v-list-dns-records/bob-example.com.json');
        if ($created) {
            $rows['13'] = ['RECORD' => '_acme-challenge', 'TYPE' => 'TXT', 'PRIORITY' => '', 'VALUE' => 'token', 'ID' => '13', 'SUSPENDED' => 'no', 'TTL' => '600', 'DATE' => '2026-10-06', 'TIME' => '12:00:00'];
        }

        return [0, (string) json_encode($rows)];
    });
    $headers = apiAs($this->fake, 'bob', 'user', area: 'dns');

    $this->postJson('/api/v2/users/bob/dns-zones/example.com/records', [
        'name' => '_acme-challenge', 'type' => 'txt', 'value' => 'token', 'ttl' => 600,
    ], $headers)->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/dns-zones/example.com/records/13')
        ->assertJsonPath('data.id', 13)
        ->assertJsonPath('data.type', 'TXT')
        ->assertJsonPath('data.ttl', 600);
    $this->fake->assertRan('v-add-dns-record', fn ($a) => $a === ['bob', 'example.com', '_acme-challenge', 'TXT', 'token', '', '13', 'yes', '600']);

    $this->postJson('/api/v2/users/bob/dns-zones/example.com/records', ['name' => '@', 'type' => 'TXT', 'value' => '"v=spf1 a mx ~all"'], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing', '/api/v2/users/bob/dns-zones/example.com/records/5');
});

it('compares a new record with the stored ones in the form v-add-dns-record stores it', function (array $body, int $existing) {
    $this->postJson('/api/v2/users/bob/dns-zones/example.com/records', $body, apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing', "/api/v2/users/bob/dns-zones/example.com/records/{$existing}");
    $this->fake->assertNotRan('v-add-dns-record');
})->with([
    'TXT without quotes' => [['name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 a mx ~all'], 5],
    'MX without priority and trailing dot' => [['name' => '@', 'type' => 'MX', 'value' => 'mail.example.com'], 4],
    'MX with priority 10 in upper case' => [['name' => '@', 'type' => 'mx', 'value' => 'Mail.Example.com.', 'priority' => 10], 4],
    'CNAME without trailing dot' => [['name' => 'WWW', 'type' => 'CNAME', 'value' => 'example.com'], 3],
    'NS without trailing dot' => [['name' => '@', 'type' => 'NS', 'value' => 'ns1.example.net'], 1],
    'A with a priority the command drops' => [['name' => '@', 'type' => 'A', 'value' => '203.0.113.10', 'priority' => 5], 2],
]);

it('keeps a record that differs after normalisation new', function (array $body) {
    $this->postJson('/api/v2/users/bob/dns-zones/example.com/records?dry_run=1', $body, apiAs($this->fake, 'bob', 'user', area: 'dns'))
        ->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-dns-record');
})->with([
    'MX with another priority' => [['name' => '@', 'type' => 'MX', 'value' => 'mail.example.com', 'priority' => 20]],
    'TXT with another value' => [['name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 -all']],
]);

it('updates, suspends and deletes a record', function () {
    $this->fake->on('v-change-dns-record')->returns();
    $this->fake->on('v-unsuspend-dns-record')->returns();
    $this->fake->on('v-delete-dns-record')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'dns');

    $this->patchJson('/api/v2/users/bob/dns-zones/example.com/records/2', ['value' => '203.0.113.20'], $headers)->assertOk()
        ->assertJsonPath('data.id', 2);
    $this->fake->assertRan('v-change-dns-record', fn ($a) => $a[0] === 'bob' && $a[2] === '2' && $a[5] === '203.0.113.20' && $a[7] === 'yes');

    $this->postJson('/api/v2/users/bob/dns-zones/example.com/records/12/unsuspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-unsuspend-dns-record', fn ($a) => $a === ['bob', 'example.com', '12']);
    $this->postJson('/api/v2/users/bob/dns-zones/example.com/records/12/suspend', [], $headers)->assertOk();
    $this->fake->assertNotRan('v-suspend-dns-record');

    $this->deleteJson('/api/v2/users/bob/dns-zones/example.com/records/3', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-dns-record', fn ($a) => $a === ['bob', 'example.com', '3']);
    $this->getJson('/api/v2/users/bob/dns-zones/example.com/records/99', $headers)->assertNotFound();
});
