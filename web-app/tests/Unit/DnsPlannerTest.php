<?php

use App\Services\Dns\DnsRecordRecord;
use App\Services\Dns\DnsService;
use App\Services\Dns\DnsZoneRecord;
use App\Services\WebDomains\Step;
use Illuminate\Support\Carbon;

beforeEach(function () {
    fakeArc();
    $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
    $this->dns = app(DnsService::class);
    $this->zone = DnsZoneRecord::fromArray('bob', 'example.com', [
        'IP' => '203.0.113.10', 'TPL' => 'default', 'TTL' => '14400', 'EXP' => '2027-10-06', 'SOA' => 'ns1.example.net',
        'SERIAL' => '1', 'DNSSEC' => 'no', 'SRC' => '', 'RECORDS' => '6', 'SUSPENDED' => 'no', 'TIME' => '', 'DATE' => '',
    ]);
    $this->record = DnsRecordRecord::fromArray('bob', 'example.com', '4', [
        'RECORD' => '@', 'TYPE' => 'MX', 'PRIORITY' => '10', 'VALUE' => 'mail.example.com.', 'ID' => '4', 'SUSPENDED' => 'no', 'TTL' => '14400',
    ]);
});

/** @param  list<Step>  $steps */
function dnsPlan(array $steps): array
{
    return array_map(fn (Step $step) => [$step->command, $step->args], $steps);
}

function dnsNewZone(array $overrides = []): array
{
    return array_merge([
        'domain' => 'new.example', 'ip' => '203.0.113.10', 'ns' => ['ns1.example.net', 'ns2.example.net'],
        'dnssec' => false, 'template' => 'default', 'exp' => '2027-10-06', 'ttl' => '14400',
    ], $overrides);
}

function dnsZoneInput(array $overrides = []): array
{
    return array_merge(['ip' => '203.0.113.10', 'template' => 'default', 'soa' => 'ns1.example.net', 'exp' => '2027-10-06', 'ttl' => '14400', 'dnssec' => null], $overrides);
}

// Add zone

it('adds a zone with eight name server arguments, RESTART=no and DNSSEC', function () {
    $steps = $this->dns->planCreate('bob', dnsNewZone(['ns' => ['a.example', 'b.example', 'c.example'], 'dnssec' => true]), true, 'default');

    expect(dnsPlan($steps))->toBe([
        ['v-add-dns-domain', ['bob', 'new.example', '203.0.113.10', 'a.example', 'b.example', 'c.example', '', '', '', '', '', 'no', 'yes']],
    ])->and($steps[0]->restart)->toBe(['dns']);
});

it('keeps at most eight name servers', function () {
    $steps = $this->dns->planCreate('bob', dnsNewZone(['ns' => array_map(fn ($i) => "ns{$i}.example", range(1, 10))]), false, '');

    expect($steps[0]->args)->toHaveCount(13)->and($steps[0]->args[10])->toBe('ns8.example');
});

it('changes the template only when allowed, given and not the owner\'s', function () {
    expect(dnsPlan($this->dns->planCreate('bob', dnsNewZone(['template' => 'gmail']), true, 'default'))[1])
        ->toBe(['v-change-dns-domain-tpl', ['bob', 'new.example', 'gmail', 'no']])
        ->and($this->dns->planCreate('bob', dnsNewZone(['template' => 'gmail']), false, 'default'))->toHaveCount(1)
        ->and($this->dns->planCreate('bob', dnsNewZone(['template' => 'default']), true, 'default'))->toHaveCount(1)
        ->and($this->dns->planCreate('bob', dnsNewZone(['template' => '']), true, 'default'))->toHaveCount(1);
});

it('changes the expiration date only when it is not today + 1 year', function () {
    expect(dnsPlan($this->dns->planCreate('bob', dnsNewZone(['exp' => '2030-01-01']), true, 'default'))[1])
        ->toBe(['v-change-dns-domain-exp', ['bob', 'new.example', '2030-01-01', 'no']])
        ->and($this->dns->planCreate('bob', dnsNewZone(['exp' => '2027-10-06']), true, 'default'))->toHaveCount(1)
        ->and($this->dns->planCreate('bob', dnsNewZone(['exp' => '']), true, 'default'))->toHaveCount(1)
        ->and(DnsService::defaultExpiry())->toBe('2027-10-06');
});

it('changes the TTL only when it is not 14400', function () {
    expect(dnsPlan($this->dns->planCreate('bob', dnsNewZone(['ttl' => '3600']), true, 'default'))[1])
        ->toBe(['v-change-dns-domain-ttl', ['bob', 'new.example', '3600', 'no']])
        ->and($this->dns->planCreate('bob', dnsNewZone(['ttl' => '14400']), true, 'default'))->toHaveCount(1)
        ->and($this->dns->planCreate('bob', dnsNewZone(['ttl' => '']), true, 'default'))->toHaveCount(1);
});

// Edit zone

it('plans nothing for an unchanged zone', function () {
    expect($this->dns->planUpdate($this->zone, dnsZoneInput(), true))->toBe([]);
});

it('plans one step per changed value in the legacy order', function () {
    $steps = $this->dns->planUpdate($this->zone, dnsZoneInput([
        'dnssec' => true, 'ttl' => '600', 'exp' => '2028-01-01', 'soa' => 'ns9.example.net', 'template' => 'gmail', 'ip' => '10.0.0.20',
    ]), true);

    expect(dnsPlan($steps))->toBe([
        ['v-change-dns-domain-ip', ['bob', 'example.com', '10.0.0.20', 'no']],
        ['v-change-dns-domain-tpl', ['bob', 'example.com', 'gmail', 'no']],
        ['v-change-dns-domain-soa', ['bob', 'example.com', 'ns9.example.net', 'no']],
        ['v-change-dns-domain-exp', ['bob', 'example.com', '2028-01-01', 'no']],
        ['v-change-dns-domain-ttl', ['bob', 'example.com', '600', 'no']],
        ['v-change-dns-domain-dnssec', ['bob', 'example.com', 'yes']],
    ]);
    foreach ($steps as $step) {
        expect($step->restart)->toBe(['dns']);
    }
});

it('leaves the template to those who may change it', function () {
    expect($this->dns->planUpdate($this->zone, dnsZoneInput(['template' => 'gmail']), false))->toBe([])
        ->and($this->dns->planUpdate($this->zone, dnsZoneInput(['template' => '']), true))->toBe([]);
});

it('changes DNSSEC only when the form shows it', function () {
    $enabled = DnsZoneRecord::fromArray('bob', 'example.com', ['IP' => '203.0.113.10', 'TPL' => 'default', 'TTL' => '14400', 'EXP' => '2027-10-06', 'SOA' => 'ns1.example.net', 'DNSSEC' => 'yes']);

    expect($this->dns->planUpdate($enabled, dnsZoneInput(['dnssec' => null]), true))->toBe([])
        ->and(dnsPlan($this->dns->planUpdate($enabled, dnsZoneInput(['dnssec' => false]), true)))
        ->toBe([['v-change-dns-domain-dnssec', ['bob', 'example.com', 'no']]])
        ->and($this->dns->planUpdate($enabled, dnsZoneInput(['dnssec' => true]), true))->toBe([]);
});

// Records

it('adds a record with an empty id, RESTART=yes and the TTL last', function () {
    $step = $this->dns->planAddRecord('bob', 'example.com', ['record' => 'www', 'type' => 'A', 'value' => '203.0.113.20', 'priority' => '', 'ttl' => '']);

    expect([$step->command, $step->args])->toBe(['v-add-dns-record', ['bob', 'example.com', 'www', 'A', '203.0.113.20', '', '', 'yes', '']])
        ->and($step->restart)->toBe([]);
});

it('changes a record only when a field changed', function () {
    expect($this->dns->planUpdateRecord($this->record, $this->record->fields(), '4'))->toBe([])
        ->and($this->dns->planUpdateRecord($this->record, $this->record->fields(), ''))->toBe([]);

    expect(dnsPlan($this->dns->planUpdateRecord($this->record, ['priority' => '5'] + $this->record->fields(), '4')))
        ->toBe([['v-change-dns-record', ['bob', 'example.com', '4', '@', 'MX', 'mail.example.com.', '5', 'yes', '14400']]]);
});

it('changes the record id after the record', function () {
    $steps = $this->dns->planUpdateRecord($this->record, ['ttl' => ''] + $this->record->fields(), '40');

    expect(dnsPlan($steps))->toBe([
        ['v-change-dns-record', ['bob', 'example.com', '4', '@', 'MX', 'mail.example.com.', '10', 'yes', '']],
        ['v-change-dns-record-id', ['bob', 'example.com', '4', '40']],
    ]);
});
