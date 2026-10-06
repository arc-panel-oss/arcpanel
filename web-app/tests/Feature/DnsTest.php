<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use App\Services\Dns\DnsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake, ['bob' => ['DNS_TEMPLATE' => 'default'], 'carol' => ['DNS_TEMPLATE' => 'default']]);
    fakeDnsZones($this->fake);
    $this->fake->on('v-restart-dns')->returns();
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));

    // The DNS commands of a change, in the order they ran
    $this->dnsCalls = fn () => array_values(array_filter(
        $this->fake->calls(),
        fn (array $call) => preg_match('/^v-(add|change)-dns|^v-restart-dns$/', $call['command']) === 1,
    ));
});

/** The fields of the add form as web/add/dns posts them. */
function dnsZoneForm(array $overrides = []): array
{
    return array_merge([
        'domain' => 'new.example', 'ip' => '203.0.113.10', 'template' => 'default',
        'exp' => '2027-10-06', 'ttl' => '14400', 'ns' => ['ns1.example.net', 'ns2.example.net'],
    ], $overrides);
}

/** The fields of web/edit/dns for bob's example.com, unchanged. */
function dnsZoneEditForm(array $overrides = []): array
{
    return array_merge([
        'ip' => '203.0.113.10', 'template' => 'default', 'soa' => 'ns1.example.net', 'exp' => '2027-10-06', 'ttl' => '14400',
    ], $overrides);
}

// Zones list (spec §8.2)

it('lists only the DNS zones of a user', function () {
    signIn('bob', 'user');

    $this->get('/dns')->assertOk()
        ->assertSee('example.com')->assertSee('shop.example')->assertSee('⇢ ns0.cluster.example')
        ->assertDontSee('carol.example')->assertDontSee('All owners')
        ->assertSee('/dns/bob/example.com/records')->assertSee('/dns/bob/example.com/edit')
        ->assertSee('/dns/bob/shop.example/dnssec')->assertDontSee('/dns/bob/example.com/dnssec')
        ->assertSee('/dns/bob/example.com/records?add=1', false)
        ->assertSee('Are you sure you want to suspend domain example.com?')
        ->assertSee('Are you sure you want to unsuspend domain shop.example?')
        ->assertSee('Are you sure you want to delete domain example.com?')
        ->assertSee('Enabled')->assertSee('Disabled')->assertSee('2027-10-06')
        ->assertDontSee('value="rebuild"', false);
});

it('lists the DNS zones of every user for an admin, with an owner filter and rebuild', function () {
    signIn('arcadmin', 'admin');

    $this->get('/dns')->assertOk()
        ->assertSee('All owners')->assertSee('value="rebuild"', false)
        ->assertSeeInOrder(['carol.example', 'example.com', 'shop.example']);
    $this->get('/dns?owner=carol')->assertOk()->assertSee('carol.example')->assertDontSee('shop.example');
    $this->get('/dns?owner=ghost')->assertNotFound();
});

it('scopes the list of a reseller to its tree', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/dns')->assertOk()->assertSee('example.com')->assertDontSee('carol.example');
    $this->get('/dns?owner=carol')->assertNotFound();
    $this->get('/dns/carol/carol.example/records')->assertNotFound();
    $this->get('/dns/carol/carol.example/edit')->assertNotFound();
    $this->get('/dns/bob/example.com/records')->assertOk();
});

it('shows an empty state without DNS zones', function () {
    signIn('arcadmin', 'admin');

    $this->get('/dns?owner=arcadmin')->assertOk()->assertSee('No DNS zones for this owner.');
});

it('links DNS zones and records from the command palette to the records page', function () {
    $this->fake->on('v-search-object')->returnsJson([]);
    $this->fake->on('v-search-object', ['shop', 'json'])->returnsJson(['1' => [
        'ID' => '1', 'USER' => 'bob', 'TYPE' => 'dns', 'KEY' => 'DOMAIN', 'RESULT' => 'shop.example', 'ALIAS' => '',
        'LINK' => '', 'PARENT' => '', 'SUSPENDED' => 'yes', 'TIME' => '10:05:00', 'DATE' => '2026-10-06',
    ]]);
    signIn('arcadmin', 'admin');

    $groups = collect($this->getJson('/_palette?q=shop')->assertOk()->json('groups'))->keyBy('label');
    expect($groups['DNS']['items'][0]['url'])->toBe(route('dns.records.index', ['user' => 'bob', 'domain' => 'shop.example']));

    $pages = collect($this->getJson('/_palette?q=dns')->json('groups'))->keyBy('label');
    expect(collect($pages['Pages and actions']['items'])->pluck('url'))->toContain(route('dns.create'), route('dns.index'));
});

// Add zone (spec §8.2)

it('shows the add form with the legacy defaults', function () {
    signIn('bob', 'user');

    $this->get('/dns/create')->assertOk()
        ->assertSee('value="203.0.113.10"', false)->assertSee('<option value="10.0.0.20">', false)
        ->assertSee('value="2027-10-06"', false)->assertSee('value="14400"', false)
        ->assertSee('ns1.example.net')->assertSee('ns2.example.net')->assertSee('apNameServers', false)
        ->assertDontSee('name="template"', false)->assertDontSee('Enable DNSSEC')->assertDontSee('strongly advised');
});

it('adds a zone with exactly the legacy arguments and one restart', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    signIn('bob', 'user');

    $this->post('/dns', dnsZoneForm(['domain' => 'WWW.New.Example', 'ns' => ['ns1.example.net', '', 'ns3.example.net']]))
        ->assertRedirect('/dns/bob/new.example/records')
        ->assertSessionHas('ok', 'DNS zone new.example has been created successfully.');

    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a === [
        'bob', 'new.example', '203.0.113.10', 'ns1.example.net', 'ns3.example.net', '', '', '', '', '', '', 'no', 'no',
    ]);
    // Defaults: no template, expiration or TTL step
    expect(array_column(($this->dnsCalls)(), 'command'))->toBe(['v-add-dns-domain', 'v-restart-dns']);
});

it('changes template, expiration and TTL after adding when they differ from the defaults', function () {
    withSysConfig($this->fake, ['POLICY_USER_EDIT_DNS_TEMPLATES' => 'yes']);
    foreach (['v-add-dns-domain', 'v-change-dns-domain-tpl', 'v-change-dns-domain-exp', 'v-change-dns-domain-ttl'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->get('/dns/create')->assertOk()->assertSee('name="template"', false)->assertSee('<option value="child-ns"', false);
    $this->post('/dns', dnsZoneForm(['template' => 'child-ns', 'exp' => '2028-01-31', 'ttl' => '3600']))
        ->assertRedirect('/dns/bob/new.example/records');

    expect(($this->dnsCalls)())->toBe([
        ['command' => 'v-add-dns-domain', 'args' => ['bob', 'new.example', '203.0.113.10', 'ns1.example.net', 'ns2.example.net', '', '', '', '', '', '', 'no', 'no']],
        ['command' => 'v-change-dns-domain-tpl', 'args' => ['bob', 'new.example', 'child-ns', 'no']],
        ['command' => 'v-change-dns-domain-exp', 'args' => ['bob', 'new.example', '2028-01-31', 'no']],
        ['command' => 'v-change-dns-domain-ttl', 'args' => ['bob', 'new.example', '3600', 'no']],
        ['command' => 'v-restart-dns', 'args' => []],
    ]);
});

it('skips the template step when it is the owner\'s DNS_TEMPLATE', function () {
    withSysConfig($this->fake, ['POLICY_USER_EDIT_DNS_TEMPLATES' => 'yes']);
    $this->fake->on('v-add-dns-domain')->returns();
    signIn('bob', 'user');

    $this->post('/dns', dnsZoneForm(['template' => 'default']))->assertRedirect('/dns/bob/new.example/records');
    $this->fake->assertNotRan('v-change-dns-domain-tpl');
});

it('checks the template policy on add (spec §11)', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    signIn('bob', 'user');

    // Without POLICY_USER_EDIT_DNS_TEMPLATES a user's template is ignored
    $this->post('/dns', dnsZoneForm(['template' => 'child-ns']))->assertRedirect('/dns/bob/new.example/records');
    $this->fake->assertNotRan('v-change-dns-domain-tpl');
});

it('lets an admin choose the template and refuses unknown templates', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-change-dns-domain-tpl')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/dns/create?owner=bob')->assertOk()->assertSee('name="template"', false);
    $this->post('/dns', dnsZoneForm(['owner' => 'bob', 'template' => 'nope']))->assertSessionHasErrors(['template']);
    $this->fake->assertNotRan('v-add-dns-domain');

    $this->post('/dns', dnsZoneForm(['owner' => 'bob', 'template' => 'gmail']))->assertRedirect('/dns/bob/new.example/records');
    $this->fake->assertRan('v-change-dns-domain-tpl', fn ($a) => $a === ['bob', 'new.example', 'gmail', 'no']);
});

it('hides the DNSSEC box without the arcpanel-zone cluster or DNSSEC support', function (array $config) {
    withSysConfig($this->fake, $config);
    signIn('bob', 'user');

    $this->get('/dns/create')->assertOk()->assertDontSee('Enable DNSSEC');
    $this->get('/dns/bob/example.com/edit')->assertOk()->assertDontSee('Enable DNSSEC');
})->with([
    'other cluster system' => [['DNS_CLUSTER_SYSTEM' => 'arcpanel', 'SUPPORT_DNSSEC' => 'yes']],
    'no DNSSEC support' => [['DNS_CLUSTER_SYSTEM' => 'arcpanel-zone', 'SUPPORT_DNSSEC' => 'no']],
]);

it('shows the DNSSEC box with the arcpanel-zone cluster and DNSSEC support', function () {
    withSysConfig($this->fake, ['DNS_CLUSTER_SYSTEM' => 'arcpanel-zone', 'SUPPORT_DNSSEC' => 'yes']);
    $this->fake->on('v-add-dns-domain')->returns();
    signIn('bob', 'user');

    $this->get('/dns/create')->assertOk()->assertSee('Enable DNSSEC');
    $this->get('/dns/bob/example.com/edit')->assertOk()->assertSee('Enable DNSSEC');
    $this->post('/dns', dnsZoneForm(['dnssec' => 'yes']))->assertRedirect();
    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a[12] === 'yes');
});

it('ignores a posted DNSSEC flag when the server does not support it', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    signIn('bob', 'user');

    $this->post('/dns', dnsZoneForm(['dnssec' => 'yes']))->assertRedirect();
    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a[12] === 'no');
});

it('validates the add form with the legacy messages', function () {
    signIn('bob', 'user');

    $this->post('/dns', dnsZoneForm(['domain' => '', 'ip' => '']))->assertSessionHasErrors([
        'domain' => 'Field "Domain" can not be blank.',
        'ip' => 'Field "IP Address" can not be blank.',
    ]);
    $this->post('/dns', dnsZoneForm(['ns' => array_fill(0, 9, 'ns.example.net')]))->assertSessionHasErrors(['ns']);
    $this->post('/dns', dnsZoneForm(['domain' => 'a b.example']))->assertSessionHasErrors(['domain']);
    $this->fake->assertNotRan('v-add-dns-domain');
});

it('keeps the input and shows the command error when the zone cannot be added', function () {
    $this->fake->on('v-add-dns-domain')->returnsExit(ExitCode::Exists, 'Error: dns domain new.example exists');
    signIn('bob', 'user');

    $this->from('/dns/create')->post('/dns', dnsZoneForm())
        ->assertRedirect('/dns/create')->assertSessionHas('error', 'Error: dns domain new.example exists')
        ->assertSessionHasInput('domain', 'new.example');
    $this->fake->assertNotRan('v-restart-dns');
});

it('shows the zone with the failed step when a later step of the add fails', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    $this->fake->on('v-change-dns-domain-ttl')->returnsExit(ExitCode::Invalid, 'Error: invalid ttl format :: x');
    signIn('bob', 'user');

    $this->post('/dns', dnsZoneForm(['ttl' => 'x']))->assertRedirect('/dns/bob/new.example/records')
        ->assertSessionHas('error', 'Change the TTL failed')->assertSessionHas('error_detail', 'Error: invalid ttl format :: x');
    $this->fake->assertNotRan('v-restart-dns');
});

it('lets an admin add a zone for another user and warns about its own account', function () {
    $this->fake->on('v-add-dns-domain')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/dns/create')->assertOk()->assertSee('strongly advised')->assertSee('a dns domain')->assertSee('Use this owner');
    $this->get('/dns/create?owner=carol')->assertOk()->assertDontSee('strongly advised');
    $this->post('/dns', dnsZoneForm(['owner' => 'carol']))->assertRedirect('/dns/carol/new.example/records');
    $this->fake->assertRan('v-add-dns-domain', fn ($a) => $a[0] === 'carol');
});

it('does not let a user add a zone for someone else', function () {
    signIn('bob', 'user');

    $this->get('/dns/create?owner=carol')->assertNotFound();
    $this->post('/dns', dnsZoneForm(['owner' => 'carol']))->assertNotFound();
    $this->fake->assertNotRan('v-add-dns-domain');
});

// Edit zone (spec §8.2)

it('shows the edit form with the zone values and answers 404 for unknown zones', function () {
    signIn('bob', 'user');

    $this->get('/dns/bob/example.com/edit')->assertOk()
        ->assertSee('value="ns1.example.net"', false)->assertSee('value="2027-10-06"', false)
        ->assertSee('name="soa"', false)->assertDontSee('name="template"', false);
    $this->get('/dns/bob/missing.example/edit')->assertNotFound();
    $this->get('/dns/carol/carol.example/edit')->assertNotFound();
});

it('runs only the changed steps in the legacy order with one restart', function () {
    withSysConfig($this->fake, ['DNS_CLUSTER_SYSTEM' => 'arcpanel-zone', 'SUPPORT_DNSSEC' => 'yes']);
    foreach (['ip', 'soa', 'exp', 'ttl', 'dnssec'] as $step) {
        $this->fake->on('v-change-dns-domain-'.$step)->returns();
    }
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm(['ip' => '10.0.0.20', 'soa' => 'ns9.example.net', 'exp' => '2028-01-01', 'ttl' => '600', 'dnssec' => 'yes']))
        ->assertRedirect('/dns/bob/example.com/edit')->assertSessionHas('ok', 'Changes have been saved.');

    expect(($this->dnsCalls)())->toBe([
        ['command' => 'v-change-dns-domain-ip', 'args' => ['bob', 'example.com', '10.0.0.20', 'no']],
        ['command' => 'v-change-dns-domain-soa', 'args' => ['bob', 'example.com', 'ns9.example.net', 'no']],
        ['command' => 'v-change-dns-domain-exp', 'args' => ['bob', 'example.com', '2028-01-01', 'no']],
        ['command' => 'v-change-dns-domain-ttl', 'args' => ['bob', 'example.com', '600', 'no']],
        ['command' => 'v-change-dns-domain-dnssec', 'args' => ['bob', 'example.com', 'yes']],
        ['command' => 'v-restart-dns', 'args' => []],
    ]);
});

it('runs nothing for an unchanged zone', function () {
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm())->assertSessionHas('ok', 'Changes have been saved.');
    expect(($this->dnsCalls)())->toBe([]);
});

it('changes the template only when the policy allows it', function () {
    $this->fake->on('v-change-dns-domain-tpl')->returns();
    signIn('bob', 'user');

    // POLICY_USER_EDIT_DNS_TEMPLATES is off: the template is ignored
    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm(['template' => 'child-ns']))->assertSessionHas('ok');
    $this->fake->assertNotRan('v-change-dns-domain-tpl');

    signIn('arcadmin', 'admin');
    $this->get('/dns/bob/example.com/edit')->assertOk()->assertSee('name="template"', false);
    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm(['template' => 'child-ns']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-dns-domain-tpl', fn ($a) => $a === ['bob', 'example.com', 'child-ns', 'no']);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('lets users change the template with POLICY_USER_EDIT_DNS_TEMPLATES', function () {
    withSysConfig($this->fake, ['POLICY_USER_EDIT_DNS_TEMPLATES' => 'yes']);
    $this->fake->on('v-change-dns-domain-tpl')->returns();
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm(['template' => 'zoho']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-dns-domain-tpl', fn ($a) => $a === ['bob', 'example.com', 'zoho', 'no']);
});

it('names the failed step of a zone edit and does not restart', function () {
    $this->fake->on('v-change-dns-domain-ip')->returnsExit(ExitCode::Invalid, 'Error: invalid ip format :: x');
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm(['ip' => 'x', 'ttl' => '600']))
        ->assertSessionHas('error', 'Change the IP address failed')->assertSessionHas('error_detail', 'Error: invalid ip format :: x');
    $this->fake->assertNotRan('v-change-dns-domain-ttl');
    $this->fake->assertNotRan('v-restart-dns');
});

it('requires the zone fields on edit', function () {
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/edit', dnsZoneEditForm(['soa' => '', 'ttl' => '']))->assertSessionHasErrors([
        'soa' => 'Field "SOA" can not be blank.',
        'ttl' => 'Field "TTL" can not be blank.',
    ]);
});

// DNSSEC (spec §8.2)

it('shows the public DNSSEC key with the legacy labels', function () {
    signIn('bob', 'user');

    $this->get('/dns/bob/shop.example/dnssec')->assertOk()
        ->assertSee('DNSKEY Record')->assertSee('shop.example. IN DS 2371 13 2')
        ->assertSee('value="KSK (257)"', false)->assertSee('value="2371"', false)
        ->assertSee('value="13 - ECDSAP256/SHA256"', false);
    $this->get('/dns/bob/example.com/dnssec')->assertOk()->assertSee('DNSSEC is not enabled for this zone.');
    $this->get('/dns/carol/carol.example/dnssec')->assertNotFound();
});

it('labels key types and algorithms as web/list/dns', function () {
    expect(DnsService::keyType('256'))->toBe('ZSK (256)')
        ->and(DnsService::keyType('1'))->toBe('')
        ->and(DnsService::algorithm('8'))->toBe('8 - RSA/SHA256')
        ->and(DnsService::algorithm('16'))->toBe('16 - ED448/SHA912')
        ->and(DnsService::algorithm('99'))->toBe('Unknown');
});

// Records (spec §8.2)

it('lists the records of a zone', function () {
    signIn('bob', 'user');

    $this->get('/dns/bob/example.com/records')->assertOk()
        ->assertSeeInOrder(['ns1.example.net.', '203.0.113.10', 'example.com.', 'mail.example.com.', 'v=spf1 a mx ~all', '198.51.100.7'])
        ->assertSee('Default')->assertSee('Add Record')->assertSee('Edit DNS Domain')
        ->assertSee('/dns/bob/example.com/records?edit=3', false)->assertDontSee('/dns/bob/example.com/records?edit=12', false)
        ->assertSee('Are you sure you want to delete record 5?')
        ->assertSee('name="record"', false)->assertSee('value="@"', false)
        ->assertSee('open: false', false);
    $this->get('/dns/bob/missing.example/records')->assertNotFound();
});

it('opens the record drawer for ?add=1 and ?edit=ID, and answers 404 for unknown records', function () {
    signIn('bob', 'user');

    $this->get('/dns/bob/example.com/records?add=1')->assertOk()->assertSee('open: true', false)->assertSee('Add DNS Record');
    $this->get('/dns/bob/example.com/records?edit=4')->assertOk()->assertSee('open: true', false)
        ->assertSee('Edit DNS Record')->assertSee('value="mail.example.com."', false)->assertSee('value="10"', false)
        ->assertSee('name="id"', false)->assertSee('/dns/bob/example.com/records/4', false);
    $this->get('/dns/bob/example.com/records?edit=99')->assertNotFound();
    $this->get('/dns/bob/example.com/records?edit=abc')->assertNotFound();
});

it('adds a record with the legacy arguments', function () {
    $this->fake->on('v-add-dns-record')->returns();
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records', ['record' => 'shop', 'type' => 'A', 'value' => '203.0.113.20', 'ttl' => ''])
        ->assertRedirect('/dns/bob/example.com/records')->assertSessionHas('ok', 'Record shop.example.com has been created successfully.');
    $this->post('/dns/bob/example.com/records', ['record' => '@', 'type' => 'MX', 'value' => 'mx.example.net', 'priority' => '20', 'ttl' => '600']);

    $this->fake->assertRan('v-add-dns-record', fn ($a) => $a === ['bob', 'example.com', 'shop', 'A', '203.0.113.20', '', '', 'yes', '']);
    $this->fake->assertRan('v-add-dns-record', fn ($a) => $a === ['bob', 'example.com', '@', 'MX', 'mx.example.net', '20', '', 'yes', '600']);
    // v-add-dns-record restarts DNS itself
    $this->fake->assertNotRan('v-restart-dns');
});

it('validates records and shows the command error in the drawer', function () {
    $this->fake->on('v-add-dns-record')->returnsExit(ExitCode::Invalid, 'Error: invalid value format :: x');
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records', ['record' => '', 'type' => 'BOGUS', 'value' => ''])->assertSessionHasErrors([
        'record' => 'Field "Record" can not be blank.',
        'value' => 'Field "IP or Value" can not be blank.',
        'type',
    ]);
    $this->from('/dns/bob/example.com/records')
        ->post('/dns/bob/example.com/records', ['record' => 'x', 'type' => 'A', 'value' => 'x'])
        ->assertRedirect('/dns/bob/example.com/records')->assertSessionHas('record_error', 'Error: invalid value format :: x')
        ->assertSessionHasInput('value', 'x');

    $this->withSession(['record_error' => 'Error: invalid value format :: x', '_old_input' => ['record' => 'x', 'type' => 'A', 'value' => 'x']])
        ->get('/dns/bob/example.com/records')->assertOk()->assertSee('open: true', false)->assertSee('Error: invalid value format :: x');
});

it('changes a record only when a field changed', function () {
    $this->fake->on('v-change-dns-record')->returns();
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records/4', ['record' => '@', 'type' => 'MX', 'value' => 'mail.example.com.', 'priority' => '10', 'ttl' => '14400', 'id' => '4'])
        ->assertRedirect('/dns/bob/example.com/records?edit=4')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertNotRan('v-change-dns-record');
    $this->fake->assertNotRan('v-restart-dns');

    $this->post('/dns/bob/example.com/records/4', ['record' => '@', 'type' => 'MX', 'value' => 'mail.example.com.', 'priority' => '5', 'ttl' => '14400', 'id' => '4'])
        ->assertRedirect('/dns/bob/example.com/records?edit=4');
    $this->fake->assertRan('v-change-dns-record', fn ($a) => $a === ['bob', 'example.com', '4', '@', 'MX', 'mail.example.com.', '5', 'yes', '14400']);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('changes the record id and goes to the new id', function () {
    $this->fake->on('v-change-dns-record-id')->returns();
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records/3', ['record' => 'www', 'type' => 'CNAME', 'value' => 'example.com.', 'ttl' => '14400', 'id' => '30'])
        ->assertRedirect('/dns/bob/example.com/records?edit=30');
    $this->fake->assertNotRan('v-change-dns-record');
    $this->fake->assertRan('v-change-dns-record-id', fn ($a) => $a === ['bob', 'example.com', '3', '30']);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('shows the command error of a record change in the drawer', function () {
    $this->fake->on('v-change-dns-record')->returnsExit(ExitCode::Invalid, 'Error: invalid record format :: a b');
    signIn('bob', 'user');

    $this->from('/dns/bob/example.com/records?edit=3')
        ->post('/dns/bob/example.com/records/3', ['record' => 'a b', 'type' => 'CNAME', 'value' => 'example.com.', 'ttl' => '14400', 'id' => '3'])
        ->assertRedirect('/dns/bob/example.com/records?edit=3')->assertSessionHas('record_error', 'Error: invalid record format :: a b');
    $this->post('/dns/bob/example.com/records/99', ['record' => 'x', 'type' => 'A', 'value' => '1.2.3.4'])->assertNotFound();
    $this->post('/dns/bob/example.com/records/3', ['record' => 'x', 'type' => 'A', 'value' => '1.2.3.4', 'id' => '0'])->assertSessionHasErrors(['id']);
});

it('suspends, unsuspends and deletes a record', function () {
    foreach (['v-suspend-dns-record', 'v-unsuspend-dns-record', 'v-delete-dns-record'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records/3/suspend')->assertRedirect('/dns/bob/example.com/records')
        ->assertSessionHas('ok', 'Record www.example.com has been suspended.');
    $this->post('/dns/bob/example.com/records/12/unsuspend')->assertSessionHas('ok', 'Record old.example.com has been unsuspended.');
    $this->delete('/dns/bob/example.com/records/5')->assertSessionHas('ok', 'Record @.example.com has been deleted.');
    $this->delete('/dns/bob/example.com/records/99')->assertNotFound();

    $this->fake->assertRan('v-suspend-dns-record', fn ($a) => $a === ['bob', 'example.com', '3']);
    $this->fake->assertRan('v-unsuspend-dns-record', fn ($a) => $a === ['bob', 'example.com', '12']);
    $this->fake->assertRan('v-delete-dns-record', fn ($a) => $a === ['bob', 'example.com', '5']);
    $this->fake->assertNotRan('v-restart-dns');
});

it('runs a record bulk action with RESTART=no and one restart', function () {
    $this->fake->on('v-delete-dns-record')->returns();
    $this->fake->on('v-delete-dns-record', ['bob', 'example.com', '99', 'no'])->returnsExit(ExitCode::NotExist, "Error: dns record 99 doesn't exist");
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records/bulk', ['action' => 'delete', 'items' => ['3', '5', '99']])
        ->assertRedirect('/dns/bob/example.com/records')->assertSessionHas('error', '2 done, 1 failed')
        ->assertSessionHas('error_detail', "99: Error: dns record 99 doesn't exist");
    $this->fake->assertRan('v-delete-dns-record', fn ($a) => $a === ['bob', 'example.com', '3', 'no']);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('refuses record bulk actions outside the scope or with bad items', function () {
    signIn('bob', 'user');

    $this->post('/dns/carol/carol.example/records/bulk', ['action' => 'suspend', 'items' => ['1']])->assertNotFound();
    $this->post('/dns/bob/missing.example/records/bulk', ['action' => 'suspend', 'items' => ['1']])->assertNotFound();
    $this->post('/dns/bob/example.com/records/bulk', ['action' => 'rebuild', 'items' => ['1']])->assertSessionHasErrors(['action']);
    $this->post('/dns/bob/example.com/records/bulk', ['action' => 'delete', 'items' => ['1;x']])->assertSessionHasErrors(['items.0']);
    $this->fake->assertNotRan('v-restart-dns');
});

it('lets a user suspend records in bulk (D10)', function () {
    $this->fake->on('v-suspend-dns-record')->returns();
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/records/bulk', ['action' => 'suspend', 'items' => ['3']])->assertSessionHas('ok', '1 done, 0 failed');
    $this->fake->assertRan('v-suspend-dns-record', fn ($a) => $a === ['bob', 'example.com', '3', 'no']);
});

// Zone actions (spec §8.1)

it('suspends, unsuspends and deletes a zone', function () {
    foreach (['v-suspend-dns-domain', 'v-unsuspend-dns-domain', 'v-delete-dns-domain'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/dns/bob/example.com/suspend')->assertSessionHas('ok', 'DNS zone example.com has been suspended.');
    $this->post('/dns/bob/shop.example/unsuspend')->assertSessionHas('ok', 'DNS zone shop.example has been unsuspended.');
    $this->delete('/dns/bob/example.com')->assertRedirect('/dns')->assertSessionHas('ok', 'DNS zone example.com has been deleted.');
    $this->post('/dns/bob/missing.example/suspend')->assertNotFound();

    $this->fake->assertRan('v-suspend-dns-domain', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertRan('v-unsuspend-dns-domain', fn ($a) => $a === ['bob', 'shop.example']);
    $this->fake->assertRan('v-delete-dns-domain', fn ($a) => $a === ['bob', 'example.com']);
    $this->fake->assertNotRan('v-restart-dns');
});

it('runs a zone bulk action with RESTART=no and one restart, reporting done and failed items', function () {
    $this->fake->on('v-suspend-dns-domain')->returns();
    $this->fake->on('v-suspend-dns-domain', ['bob', 'shop.example', 'no'])->returnsExit(ExitCode::Suspended, 'Error: dns domain shop.example is suspended');
    signIn('bob', 'user');

    $this->post('/dns/bulk', ['action' => 'suspend', 'items' => ['bob/example.com', 'bob/shop.example']])
        ->assertRedirect('/dns')->assertSessionHas('error', '1 done, 1 failed')
        ->assertSessionHas('error_detail', 'shop.example: Error: dns domain shop.example is suspended');
    $this->fake->assertRan('v-suspend-dns-domain', fn ($a) => $a === ['bob', 'example.com', 'no']);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('runs nothing when one zone bulk item belongs to another user, and refuses rebuild to users', function () {
    signIn('bob', 'user');

    $this->post('/dns/bulk', ['action' => 'delete', 'items' => ['bob/example.com', 'carol/carol.example']])->assertNotFound();
    $this->post('/dns/bulk', ['action' => 'rebuild', 'items' => ['bob/example.com']])->assertForbidden();
    $this->post('/dns/bulk', ['action' => 'delete', 'items' => ['bob']])->assertSessionHasErrors(['items.0']);
    $this->fake->assertNotRan('v-delete-dns-domain');
    $this->fake->assertNotRan('v-rebuild-dns-domain');
    $this->fake->assertNotRan('v-restart-dns');
});

it('lets an admin rebuild zones of several users', function () {
    $this->fake->on('v-rebuild-dns-domain')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/dns/bulk?owner=bob', ['action' => 'rebuild', 'items' => ['bob/example.com', 'carol/carol.example']])
        ->assertRedirect('/dns?owner=bob')->assertSessionHas('ok', '2 done, 0 failed');
    $this->fake->assertRan('v-rebuild-dns-domain', fn ($a) => $a === ['carol', 'carol.example', 'no']);
    $this->fake->assertRan('v-restart-dns', times: 1);
});

it('lets a reseller act on its customers\' zones only', function () {
    fakeResellers($this->fake);
    $this->fake->on('v-suspend-dns-domain')->returns();
    signIn('hostco', 'reseller');

    $this->post('/dns/bulk', ['action' => 'suspend', 'items' => ['bob/example.com']])->assertSessionHas('ok', '1 done, 0 failed');
    $this->post('/dns/bulk', ['action' => 'suspend', 'items' => ['carol/carol.example']])->assertNotFound();
    $this->post('/dns/carol/carol.example/records', ['record' => 'x', 'type' => 'A', 'value' => '1.2.3.4'])->assertNotFound();
    $this->fake->assertRan('v-suspend-dns-domain', times: 1);
});

// Read-only mode and capabilities

it('refuses every change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/dns')->assertOk()->assertDontSee('Add DNS Domain');
    $this->get('/dns/bob/example.com/records')->assertOk()->assertDontSee('Add Record')->assertDontSee('name="record"', false);
    $this->get('/dns/bob/example.com/edit')->assertOk()->assertSee('disabled', false);
    foreach (['edit', 'suspend', 'unsuspend', 'records', 'records/3', 'records/3/suspend'] as $action) {
        $this->post("/dns/bob/example.com/{$action}")->assertForbidden();
    }
    $this->delete('/dns/bob/example.com')->assertForbidden();
    $this->delete('/dns/bob/example.com/records/3')->assertForbidden();
    $this->post('/dns/bulk', ['action' => 'delete', 'items' => ['bob/example.com']])->assertForbidden();
    $this->post('/dns/bob/example.com/records/bulk', ['action' => 'delete', 'items' => ['3']])->assertForbidden();
    $this->post('/dns', dnsZoneForm())->assertForbidden();
    $this->fake->assertNotRan('v-delete-dns-domain');
});

it('has a capability for every DNS route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'dns.'));

    expect($routes)->toContain('dns.index', 'dns.create', 'dns.store', 'dns.bulk', 'dns.edit', 'dns.update', 'dns.dnssec',
        'dns.suspend', 'dns.unsuspend', 'dns.destroy', 'dns.records.index', 'dns.records.store', 'dns.records.update',
        'dns.records.bulk', 'dns.records.suspend', 'dns.records.unsuspend', 'dns.records.destroy');
    foreach (['dns.view', 'dns.create', 'dns.update', 'dns.delete', 'dns.templates.edit'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});
