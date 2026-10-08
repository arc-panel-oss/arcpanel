<?php

use App\Arc\ExitCode;

// REST API v2, firewall of the server (E34 batch 6 spec §5.3, D59). The
// fake keeps the rules like the commands do: positions 1–4, a move swaps
// two positions, a delete renumbers, an add takes the position it is given.

/** @return array<string, string> one row of `v-list-firewall json` */
function apiFirewallRule(string $action, string $protocol, string $port, string $ip, string $comment, string $suspended = 'no'): array
{
    return ['ACTION' => $action, 'PROTOCOL' => $protocol, 'PORT' => $port, 'IP' => $ip, 'COMMENT' => $comment,
        'SUSPENDED' => $suspended, 'TIME' => '07:40:16', 'DATE' => '2026-10-01'];
}

beforeEach(function () {
    $this->fake = fakeApi(['FIREWALL_EXTENSION' => 'fail2ban']);
    fakeResellers($this->fake);
    $this->rules = [
        '1' => apiFirewallRule('ACCEPT', 'ICMP', '0', '0.0.0.0/0', 'PING'),
        '2' => apiFirewallRule('ACCEPT', 'TCP', '8083', '0.0.0.0/0', 'ARCPANEL'),
        '3' => apiFirewallRule('DROP', 'TCP', '25', '10.0.0.0/8', 'SMTP-OUT', 'yes'),
        '4' => apiFirewallRule('ACCEPT', 'TCP', '30005', '0.0.0.0/0', 'arcpanel-port-30005-bob'),
    ];
    $this->fake->on('v-list-firewall')->using(fn () => [0, (string) json_encode($this->rules)]);
    $this->fake->on('v-add-firewall-rule')->using(function (array $a) {
        $this->rules[$a[5]] = apiFirewallRule($a[0], $a[3], $a[2], $a[1], $a[4]);

        return [0, ''];
    });
    $this->fake->on('v-change-firewall-rule')->using(function (array $a) {
        $this->rules[$a[0]] = apiFirewallRule($a[1], $a[4], $a[3], $a[2], $a[5], $this->rules[$a[0]]['SUSPENDED']);

        return [0, ''];
    });
    $this->fake->on('v-move-firewall-rule')->using(function (array $a) {
        $target = (string) ((int) $a[0] + ($a[1] === 'up' ? -1 : 1));
        [$this->rules[$a[0]], $this->rules[$target]] = [$this->rules[$target], $this->rules[$a[0]]];

        return [0, ''];
    });
    $this->fake->on('v-delete-firewall-rule')->using(function (array $a) {
        unset($this->rules[$a[0]]);
        $this->rules = array_combine(array_map('strval', range(1, count($this->rules))), array_values($this->rules));

        return [0, ''];
    });
    foreach (['v-suspend-firewall-rule' => 'yes', 'v-unsuspend-firewall-rule' => 'no'] as $command => $state) {
        $this->fake->on($command)->using(function (array $a) use ($state) {
            $this->rules[$a[0]]['SUSPENDED'] = $state;

            return [0, ''];
        });
    }
    $this->fake->on('v-list-firewall-ipset')->returnsJson([
        'country-nl' => ['IP_VERSION' => 'v4', 'AUTOUPDATE' => 'yes', 'SUSPENDED' => 'no', 'SOURCE' => 'https://example.org/nl.txt', 'TIME' => '10:00:00', 'DATE' => '2026-10-01'],
        'blocked6' => ['IP_VERSION' => 'v6', 'AUTOUPDATE' => 'no', 'SUSPENDED' => 'no', 'SOURCE' => '/root/blocked6.txt', 'TIME' => '10:00:00', 'DATE' => '2026-10-01'],
    ]);
    $this->fake->on('v-list-firewall-ban')->returnsJson([
        '203.0.113.7' => ['IP' => '203.0.113.7', 'CHAIN' => 'SSH', 'TIME' => '12:00:00', 'DATE' => '2026-10-05'],
        '2001:db8::7' => ['IP' => '2001:db8::7', 'CHAIN' => 'WEB', 'TIME' => '12:30:00', 'DATE' => '2026-10-05'],
        '198.51.100.0/24' => ['IP' => '198.51.100.0/24', 'CHAIN' => 'MAIL', 'TIME' => '13:00:00', 'DATE' => '2026-10-05'],
    ]);
    foreach (['v-add-firewall-ipset', 'v-delete-firewall-ipset', 'v-add-firewall-ban', 'v-delete-firewall-ban'] as $command) {
        $this->fake->on($command)->returns();
    }
});

// Rules

it('lists the rules in order for administrators and refuses everyone else', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/firewall/rules', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.*.id', [1, 2, 3, 4])
            ->assertJsonPath('data.2', [
                'id' => 3, 'action' => 'DROP', 'protocol' => 'TCP', 'port' => '25', 'source' => '10.0.0.0/8', 'comment' => 'SMTP-OUT',
                'suspended' => true, 'managed' => false, 'updated_at' => '2026-10-01T07:40:16',
            ])
            ->assertJsonPath('data.3.managed', true);
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every firewall endpoint to non-administrators before any change', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->getJson('/api/v2/system/firewall/rules/2', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '22', 'source' => '1.2.3.4'], $headers)->assertForbidden();
    $this->patchJson('/api/v2/system/firewall/rules/2', ['port' => '8084'], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/firewall/rules/2/move', ['direction' => 'up'], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/firewall/rules/2/suspend', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/firewall/rules/2', [], $headers)->assertForbidden();
    $this->getJson('/api/v2/system/firewall/ipsets', $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/firewall/ipsets/country-nl', [], $headers)->assertForbidden();
    $this->getJson('/api/v2/system/firewall/bans', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/firewall/bans', ['ip' => '192.0.2.1', 'chain' => 'SSH'], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/firewall/bans/SSH/203.0.113.7', [], $headers)->assertForbidden();
    foreach (['v-add-firewall-rule', 'v-change-firewall-rule', 'v-move-firewall-rule', 'v-suspend-firewall-rule', 'v-delete-firewall-rule',
        'v-delete-firewall-ipset', 'v-add-firewall-ban', 'v-delete-firewall-ban'] as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('answers 404 for every firewall path without a firewall, and for the bans without the extension', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FIREWALL_EXTENSION' => '']);
    $this->getJson('/api/v2/system/firewall/rules', $headers)->assertOk();
    $this->getJson('/api/v2/system/firewall/bans', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->postJson('/api/v2/system/firewall/bans', ['ip' => '192.0.2.1', 'chain' => 'SSH'], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/system/firewall/bans/SSH/203.0.113.7', [], $headers)->assertNotFound();
    $reads = count($this->fake->calls('v-list-firewall'));

    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'FIREWALL_SYSTEM' => '', 'FIREWALL_EXTENSION' => 'fail2ban']);
    $this->getJson('/api/v2/system/firewall/rules', $headers)->assertNotFound();
    $this->getJson('/api/v2/system/firewall/rules/1', $headers)->assertNotFound();
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '22', 'source' => '1.2.3.4'], $headers)->assertNotFound();
    $this->postJson('/api/v2/system/firewall/rules/2/move', ['direction' => 'up'], $headers)->assertNotFound();
    $this->getJson('/api/v2/system/firewall/ipsets', $headers)->assertNotFound();
    $this->getJson('/api/v2/system/firewall/bans', $headers)->assertNotFound();
    expect($this->fake->calls('v-list-firewall'))->toHaveCount($reads);
    $this->fake->assertNotRan('v-add-firewall-rule');
});

it('adds a rule at the next position with a key holding only v2-server (D59)', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'accept', 'protocol' => 'tcp', 'port' => ' 80  443,,8080, ', 'source' => 'ipset:country-nl', 'comment' => 'web'], $headers)
        ->assertCreated()
        ->assertHeader('Location', route('api.v2.system.firewall.rules.show', ['rule' => 5]))
        ->assertJsonPath('data', [
            'id' => 5, 'action' => 'ACCEPT', 'protocol' => 'TCP', 'port' => '80,443,8080', 'source' => 'ipset:country-nl', 'comment' => 'web',
            'suspended' => false, 'managed' => false, 'updated_at' => '2026-10-01T07:40:16',
        ]);
    $this->fake->assertRan('v-add-firewall-rule', fn ($a) => $a === ['ACCEPT', 'ipset:country-nl', '80,443,8080', 'TCP', 'web', '5']);

    // A numeric port, every port by default
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => 22, 'source' => '192.0.2.0/24'], $headers)
        ->assertCreated()->assertJsonPath('data.id', 6)->assertJsonPath('data.comment', null);
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'UDP', 'source' => '192.0.2.9'], $headers)
        ->assertCreated()->assertJsonPath('data.port', '0');
});

it('refuses an identical rule with the existing one, bad values and registry comments', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'ACCEPT', 'protocol' => 'TCP', 'port' => '8083', 'source' => '0.0.0.0/0', 'comment' => 'other'], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing', '/api/v2/system/firewall/rules/2');
    // A suspended identical rule counts too
    $this->postJson('/api/v2/system/firewall/rules?dry_run=1', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '25', 'source' => '10.0.0.0/8'], $headers)
        ->assertStatus(409)->assertJsonPath('error.existing', '/api/v2/system/firewall/rules/3');

    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'REJECT', 'protocol' => 'TCP', 'source' => '1.2.3.4'], $headers)->assertStatus(422)->assertJsonPath('error.fields.action.0', fn ($m) => is_string($m));
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'SCTP', 'source' => '1.2.3.4'], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP'], $headers)->assertStatus(422)->assertJsonPath('error.fields.source.0', fn ($m) => is_string($m));
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => ',,', 'source' => '1.2.3.4'], $headers)->assertStatus(422)->assertJsonPath('error.fields.port.0', 'The port is empty.');
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'source' => "1.2.3.4\n"], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'ACCEPT', 'protocol' => 'TCP', 'port' => '30006', 'source' => '0.0.0.0/0', 'comment' => 'arcpanel-port-30006-bob'], $headers)
        ->assertStatus(422)->assertJsonPath('error.fields.comment.0', 'Comments of the form arcpanel-port-PORT-USER belong to the port registry.');
    $this->fake->assertNotRan('v-add-firewall-rule');

    $this->fake->on('v-add-firewall-rule')->returnsExit(ExitCode::Invalid, 'Error: invalid port format :: 99999');
    $this->postJson('/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '99999', 'source' => '1.2.3.4'], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'E_INVALID')->assertJsonPath('error.message', 'Error: invalid port format :: 99999');
});

it('reads and updates a rule, keeping the fields left out', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/firewall/rules/2', $headers)->assertOk()->assertJsonPath('data.port', '8083');
    $this->getJson('/api/v2/system/firewall/rules/9', $headers)->assertNotFound();

    $this->patchJson('/api/v2/system/firewall/rules/2', ['port' => '8083 8084', 'comment' => null], $headers)->assertOk()
        ->assertJsonPath('data.id', 2)->assertJsonPath('data.port', '8083,8084')->assertJsonPath('data.comment', null);
    $this->fake->assertRan('v-change-firewall-rule', fn ($a) => $a === ['2', 'ACCEPT', '0.0.0.0/0', '8083,8084', 'TCP', '']);

    // Nothing changed: nothing runs
    $this->patchJson('/api/v2/system/firewall/rules/1?dry_run=1', ['action' => 'ACCEPT', 'comment' => 'PING'], $headers)->assertOk()->assertJsonPath('data.steps', []);
    $this->patchJson('/api/v2/system/firewall/rules/1', ['comment' => 'arcpanel-port-30005-bob'], $headers)->assertStatus(422);
    $this->fake->assertRan('v-change-firewall-rule', null, 1);
});

it('keeps the port registry rules from changes, but moves them (D59)', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/system/firewall/rules/4', $headers)->assertOk()->assertJsonPath('data.managed', true);
    $this->patchJson('/api/v2/system/firewall/rules/4', ['port' => '30006'], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'Rule 4 belongs to the port registry: change it through the ports of its user.');
    $this->postJson('/api/v2/system/firewall/rules/4/suspend', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/firewall/rules/4/unsuspend', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/firewall/rules/4', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/firewall/rules/4?dry_run=1', [], $headers)->assertForbidden();
    foreach (['v-change-firewall-rule', 'v-suspend-firewall-rule', 'v-unsuspend-firewall-rule', 'v-delete-firewall-rule'] as $command) {
        $this->fake->assertNotRan($command);
    }

    $this->postJson('/api/v2/system/firewall/rules/4/move', ['direction' => 'up'], $headers)->assertOk()
        ->assertHeader('Content-Location', route('api.v2.system.firewall.rules.show', ['rule' => 3]))
        ->assertHeaderMissing('Location')
        ->assertJsonPath('data.id', 3)->assertJsonPath('data.managed', true);
});

it('moves a rule by swapping it with its neighbour and answers its new id (D59)', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/firewall/rules/2/move', ['direction' => 'down'], $headers)->assertOk()
        ->assertHeader('Content-Location', route('api.v2.system.firewall.rules.show', ['rule' => 3]))
        ->assertJsonPath('data.id', 3)->assertJsonPath('data.comment', 'ARCPANEL');
    $this->fake->assertRan('v-move-firewall-rule', fn ($a) => $a === ['2', 'down']);
    $this->getJson('/api/v2/system/firewall/rules', $headers)->assertJsonPath('data.*.comment', ['PING', 'SMTP-OUT', 'ARCPANEL', 'arcpanel-port-30005-bob']);

    $this->postJson('/api/v2/system/firewall/rules/3/move?dry_run=1', ['direction' => 'up'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Move firewall rule 3 up', 'command' => 'v-move-firewall-rule', 'args' => ['3', 'up']]);
    $this->fake->assertRan('v-move-firewall-rule', null, 1);

    $this->postJson('/api/v2/system/firewall/rules/1/move', ['direction' => 'up'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.direction.0', 'Rule 1 is the first rule.');
    $this->postJson('/api/v2/system/firewall/rules/4/move', ['direction' => 'down'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.direction.0', 'Rule 4 is the last rule.');
    $this->postJson('/api/v2/system/firewall/rules/2/move', ['direction' => 'sideways'], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/firewall/rules/9/move', ['direction' => 'up'], $headers)->assertNotFound();

    // A gap in the positions: v-move-firewall-rule would lose a rule, so the move is refused
    $this->rules = ['1' => $this->rules['1'], '5' => $this->rules['2']];
    $this->postJson('/api/v2/system/firewall/rules/5/move', ['direction' => 'up'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.direction.0', 'There is no rule at position 4 to swap with.');
    $this->fake->assertRan('v-move-firewall-rule', null, 1);
});

it('suspends, unsuspends and deletes rules; later ids drop by one', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/firewall/rules/2/suspend', [], $headers)->assertOk()->assertJsonPath('data.suspended', true);
    $this->fake->assertRan('v-suspend-firewall-rule', fn ($a) => $a === ['2']);
    $this->postJson('/api/v2/system/firewall/rules/2/suspend?dry_run=1', [], $headers)->assertOk()->assertJsonPath('data.steps', []);
    $this->postJson('/api/v2/system/firewall/rules/3/unsuspend', [], $headers)->assertOk()->assertJsonPath('data.suspended', false);
    $this->fake->assertRan('v-unsuspend-firewall-rule', fn ($a) => $a === ['3']);

    $this->deleteJson('/api/v2/system/firewall/rules/2', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-firewall-rule', fn ($a) => $a === ['2']);
    $this->getJson('/api/v2/system/firewall/rules/2', $headers)->assertOk()->assertJsonPath('data.comment', 'SMTP-OUT');
    $this->deleteJson('/api/v2/system/firewall/rules/9', [], $headers)->assertNotFound();
});

it('plans rule changes without running them and refuses a v2-read key before any command', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');
    $this->postJson('/api/v2/system/firewall/rules?dry_run=1', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '22', 'source' => '192.0.2.1'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Add firewall rule 5', 'command' => 'v-add-firewall-rule', 'args' => ['DROP', '192.0.2.1', '22', 'TCP', '', '5']]);
    $this->deleteJson('/api/v2/system/firewall/rules/2?dry_run=1', [], $headers)->assertOk()->assertJsonPath('data.steps.0.command', 'v-delete-firewall-rule');
    $this->fake->assertNotRan('v-add-firewall-rule');
    $this->fake->assertNotRan('v-delete-firewall-rule');

    $read = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);
    $this->getJson('/api/v2/system/firewall/rules', $read)->assertOk();
    $this->getJson('/api/v2/system/firewall/ipsets', $read)->assertOk();
    $this->getJson('/api/v2/system/firewall/bans', $read)->assertOk();
    $refused = [
        ['post', '/api/v2/system/firewall/rules', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '22', 'source' => '192.0.2.1'], 'v-add-firewall-rule'],
        ['patch', '/api/v2/system/firewall/rules/2', ['port' => '8084'], 'v-change-firewall-rule'],
        ['post', '/api/v2/system/firewall/rules/2/move', ['direction' => 'up'], 'v-move-firewall-rule'],
        ['post', '/api/v2/system/firewall/rules/2/suspend', [], 'v-suspend-firewall-rule'],
        ['post', '/api/v2/system/firewall/rules/3/unsuspend', [], 'v-unsuspend-firewall-rule'],
        ['delete', '/api/v2/system/firewall/rules/2', [], 'v-delete-firewall-rule'],
        ['post', '/api/v2/system/firewall/ipsets', ['name' => 'country-de', 'source' => 'https://example.org/de.txt', 'ip_version' => 'v4'], 'v-add-firewall-ipset'],
        ['delete', '/api/v2/system/firewall/ipsets/country-nl', [], 'v-delete-firewall-ipset'],
        ['post', '/api/v2/system/firewall/bans', ['ip' => '192.0.2.1', 'chain' => 'SSH'], 'v-add-firewall-ban'],
        ['delete', '/api/v2/system/firewall/bans/SSH/203.0.113.7', [], 'v-delete-firewall-ban'],
    ];
    foreach ($refused as [$method, $uri, $body, $command]) {
        $this->json($method, $uri, $body, $read)->assertForbidden()->assertJsonPath('error.commands', [$command]);
        $this->fake->assertNotRan($command);
    }
});

// IPset lists

it('lists, adds and deletes IPset lists', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/firewall/ipsets', $headers)->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0', ['name' => 'blocked6', 'source' => '/root/blocked6.txt', 'ip_version' => 'v6', 'auto_update' => false, 'suspended' => false, 'created_at' => '2026-10-01T10:00:00'])
        ->assertJsonPath('data.1.name', 'country-nl');

    $this->postJson('/api/v2/system/firewall/ipsets', ['name' => 'country-de', 'source' => 'https://example.org/de.txt', 'ip_version' => 'v4', 'auto_update' => true], $headers)
        ->assertCreated()->assertHeader('Location', route('api.v2.system.firewall.ipsets.index'))
        ->assertJsonPath('data.name', 'country-de')->assertJsonPath('data.auto_update', true);
    $this->fake->assertRan('v-add-firewall-ipset', fn ($a) => $a === ['country-de', 'https://example.org/de.txt', 'v4', 'yes']);
    $this->postJson('/api/v2/system/firewall/ipsets?dry_run=1', ['name' => 'local', 'source' => '/root/list.txt', 'ip_version' => 'v6'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['local', '/root/list.txt', 'v6', 'no']);

    $this->postJson('/api/v2/system/firewall/ipsets', ['name' => 'country-nl', 'source' => 'https://example.org/nl.txt', 'ip_version' => 'v4'], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->postJson('/api/v2/system/firewall/ipsets', ['name' => 'bad name', 'source' => 'x', 'ip_version' => 'v4'], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/firewall/ipsets', ['name' => 'x', 'source' => 'x', 'ip_version' => 'v5'], $headers)->assertStatus(422);
    $this->fake->assertRan('v-add-firewall-ipset', null, 1);

    $this->deleteJson('/api/v2/system/firewall/ipsets/country-nl', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-firewall-ipset', fn ($a) => $a === ['country-nl']);
    $this->deleteJson('/api/v2/system/firewall/ipsets/ghost', [], $headers)->assertNotFound();
});

// Ban list

it('lists, bans and unbans addresses, IPv6 and networks URL-encoded', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/firewall/bans', $headers)->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0', ['ip' => '198.51.100.0/24', 'chain' => 'MAIL', 'created_at' => '2026-10-05T13:00:00'])
        ->assertJsonPath('data.*.ip', ['198.51.100.0/24', '2001:db8::7', '203.0.113.7']);

    $this->postJson('/api/v2/system/firewall/bans', ['ip' => '192.0.2.1', 'chain' => 'arcpanel'], $headers)->assertCreated()
        ->assertHeader('Location', route('api.v2.system.firewall.bans.index'))
        ->assertJsonPath('data.ip', '192.0.2.1')->assertJsonPath('data.chain', 'ARCPANEL');
    $this->fake->assertRan('v-add-firewall-ban', fn ($a) => $a === ['192.0.2.1', 'ARCPANEL']);
    $this->postJson('/api/v2/system/firewall/bans', ['ip' => '203.0.113.7', 'chain' => 'SSH'], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_EXISTS');
    $this->postJson('/api/v2/system/firewall/bans', ['ip' => '192.0.2.1', 'chain' => 'NOPE'], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/firewall/bans', ['ip' => 'example.com', 'chain' => 'SSH'], $headers)->assertStatus(422);

    $this->deleteJson('/api/v2/system/firewall/bans/WEB/2001%3Adb8%3A%3A7', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-firewall-ban', fn ($a) => $a === ['2001:db8::7', 'WEB']);
    $this->deleteJson('/api/v2/system/firewall/bans/MAIL/198.51.100.0%2F24', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-firewall-ban', fn ($a) => $a === ['198.51.100.0/24', 'MAIL']);
    $this->deleteJson('/api/v2/system/firewall/bans/SSH/2001%3Adb8%3A%3A7', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/system/firewall/bans/NOPE/203.0.113.7', [], $headers)->assertNotFound();
    $this->deleteJson('/api/v2/system/firewall/bans/SSH/203.0.113.7?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Unban 203.0.113.7', 'command' => 'v-delete-firewall-ban', 'args' => ['203.0.113.7', 'SSH']]);
    $this->fake->assertRan('v-delete-firewall-ban', null, 2);
});
