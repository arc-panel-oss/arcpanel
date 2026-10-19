<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// Firewall pages (E7 batch 2 spec §6).

/** @return array<string, array<string, string>> `v-list-firewall json` */
function firewallRules(): array
{
    $rule = fn (string $action, string $protocol, string $port, string $ip, string $comment, string $suspended = 'no') => [
        'ACTION' => $action, 'PROTOCOL' => $protocol, 'PORT' => $port, 'IP' => $ip, 'COMMENT' => $comment,
        'SUSPENDED' => $suspended, 'TIME' => '07:40:16', 'DATE' => '2014-05-25',
    ];

    return [
        '1' => $rule('ACCEPT', 'ICMP', '0', '0.0.0.0/0', 'PING'),
        '2' => $rule('ACCEPT', 'TCP', '8083', '0.0.0.0/0', 'ARCPANEL'),
        '3' => $rule('DROP', 'TCP', '25', '10.0.0.0/8', 'SMTP-OUT', 'yes'),
        '10' => $rule('ACCEPT', 'TCP', '30005', '0.0.0.0/0', 'arcpanel-port-30005-bob'),
    ];
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $rules = firewallRules();
    $this->fake->on('v-list-firewall')->returnsJson($rules);
    $this->fake->on('v-list-firewall-rule')->using(fn (array $args) => isset($rules[$args[0]])
        ? [0, (string) json_encode([$args[0] => $rules[$args[0]]])]
        : [ExitCode::NotExist->value, 'Error: rule not found']);
    $this->fake->on('v-list-firewall-ipset')->returnsJson([
        'country-nl' => ['IP_VERSION' => 'v4', 'AUTOUPDATE' => 'yes', 'SUSPENDED' => 'no', 'SOURCE' => 'https://example.org/nl.txt', 'TIME' => '10:00:00', 'DATE' => '2026-10-01'],
        'blocked6' => ['IP_VERSION' => 'v6', 'AUTOUPDATE' => 'no', 'SUSPENDED' => 'no', 'SOURCE' => '/root/blocked6.txt', 'TIME' => '10:00:00', 'DATE' => '2026-10-01'],
        'old' => ['IP_VERSION' => 'v4', 'AUTOUPDATE' => 'no', 'SUSPENDED' => 'yes', 'SOURCE' => '/root/old.txt', 'TIME' => '10:00:00', 'DATE' => '2026-10-01'],
    ]);
    $this->fake->on('v-list-firewall-ban')->returnsJson([
        '203.0.113.7' => ['IP' => '203.0.113.7', 'CHAIN' => 'SSH', 'TIME' => '12:00:00', 'DATE' => '2026-10-05'],
        '2001:db8::7' => ['IP' => '2001:db8::7', 'CHAIN' => 'WEB', 'TIME' => '12:30:00', 'DATE' => '2026-10-05'],
    ]);
    $this->fake->on('v-check-firewall', ['json'])->returnsJson(['ENGINE' => 'nftables', 'STATUS' => 'ok', 'CHECKED' => '2026-10-19T12:00:00Z']);
});

// Rules list (§6.2)

it('lists the rules in order with the managed port rule locked', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall')->assertOk()
        ->assertSeeInOrder(['PING', 'ARCPANEL', 'SMTP-OUT', 'arcpanel-port-30005-bob'])
        // ICMP has no port
        ->assertSee('data-label="Port" class="mono">—</td>', false)->assertDontSee('data-label="Port" class="mono">0</td>', false)
        ->assertSee('Managed by Ports')->assertSee('Add Rule')->assertSee('IPset IP Lists')
        ->assertDontSee('Banned IP Addresses')
        ->assertSee('Are you sure you want to move rule #2 up?')
        ->assertDontSee('Are you sure you want to move rule #1 up?')
        ->assertDontSee('Are you sure you want to move rule #10 down?')
        ->assertSee('Are you sure you want to suspend rule #2?')
        ->assertSee('Are you sure you want to unsuspend rule #3?')
        ->assertDontSee('Are you sure you want to delete rule #10?')
        ->assertSee('4 firewall rules');
});

it('links the ban list when the firewall extension runs', function () {
    withSysConfig($this->fake, ['FIREWALL_EXTENSION' => 'fail2ban']);
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall')->assertOk()->assertSee('Banned IP Addresses');
});

it('answers 404 without a firewall and 403 for everyone but administrators', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/firewall')->assertForbidden();
        $this->get('/server/firewall/create')->assertForbidden();
        $this->get('/server/firewall/2/edit')->assertForbidden();
        $this->get('/server/firewall/ipsets')->assertForbidden();
        $this->post('/server/firewall', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '22', 'ip' => '0.0.0.0/0'])->assertForbidden();
        $this->post('/server/firewall/2/suspend')->assertForbidden();
        $this->post('/server/firewall/bulk', ['action' => 'delete', 'items' => ['2']])->assertForbidden();
    }
    $this->fake->assertNotRan('v-add-firewall-rule');
    $this->fake->assertNotRan('v-suspend-firewall-rule');

    withSysConfig($this->fake, ['FIREWALL_SYSTEM' => '']);
    signIn('arcadmin', 'admin');
    $this->get('/server/firewall')->assertNotFound();
});

// Add and edit (§6.2)

it('adds a rule with the legacy port clean-up and accepts port 0', function () {
    $this->fake->on('v-add-firewall-rule')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/create')->assertOk()
        ->assertSee('(Ranges and lists are acceptable)')->assertSee('All ports: 0, Range: 80-82, List: 80,443,8080,8443')
        // IPv6 lists too since E40; suspended lists are not offered
        ->assertSee('ipset:country-nl', false)->assertSee('ipset:blocked6', false)->assertDontSee('ipset:old', false);

    $this->post('/server/firewall', ['action' => 'ACCEPT', 'protocol' => 'TCP', 'port' => ' 80  443,,8080, ', 'ip' => 'ipset:country-nl', 'comment' => 'web'])
        ->assertRedirect('/server/firewall')->assertSessionHas('ok', 'Rule has been created successfully.');
    $this->fake->assertRan('v-add-firewall-rule', fn ($a) => $a === ['ACCEPT', 'ipset:country-nl', '80,443,8080', 'TCP', 'web']);

    $this->post('/server/firewall', ['action' => 'DROP', 'protocol' => 'ICMP', 'port' => '0', 'ip' => '0.0.0.0/0'])->assertRedirect('/server/firewall');
    $this->fake->assertRan('v-add-firewall-rule', fn ($a) => $a === ['DROP', '0.0.0.0/0', '0', 'ICMP', '']);
});

it('requires action, protocol, port and address', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/firewall', ['action' => '', 'protocol' => 'TCP', 'port' => '', 'ip' => ''])
        ->assertSessionHasErrors([
            'action' => 'Field "Action" can not be blank.',
            'port' => 'Field "Port" can not be blank.',
            'ip' => 'Field "IP Address" can not be blank.',
        ]);
    $this->post('/server/firewall', ['action' => 'REJECT', 'protocol' => 'TCP', 'port' => '22', 'ip' => '1.2.3.4'])->assertSessionHasErrors(['action']);
    $this->fake->assertNotRan('v-add-firewall-rule');
});

it('shows the command error and keeps the input', function () {
    $this->fake->on('v-add-firewall-rule')->returnsExit(ExitCode::Invalid, 'Error: invalid port format :: 99999');
    signIn('arcadmin', 'admin');

    $this->from('/server/firewall/create')->post('/server/firewall', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '99999', 'ip' => '1.2.3.4'])
        ->assertRedirect('/server/firewall/create')->assertSessionHas('error', 'Error: invalid port format :: 99999')
        ->assertSessionHasInput('port', '99999');
});

it('edits a rule with the arguments of web/edit/firewall', function () {
    $this->fake->on('v-change-firewall-rule')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/2/edit')->assertOk()->assertSee('ARCPANEL')->assertSee('8083');
    $this->post('/server/firewall/2/edit', ['action' => 'ACCEPT', 'protocol' => 'TCP', 'port' => '8083 8084', 'ip' => '10.0.0.0/8', 'comment' => 'PANEL'])
        ->assertRedirect('/server/firewall/2/edit')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-firewall-rule', fn ($a) => $a === ['2', 'ACCEPT', '10.0.0.0/8', '8083,8084', 'TCP', 'PANEL']);

    $this->get('/server/firewall/99/edit')->assertNotFound();
});

it('keeps rules of the port registry read-only', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/10/edit')->assertOk()
        ->assertSee('The port registry opened this rule for a public port. Change it on the Ports page.')
        ->assertSee('<fieldset class="ap-fieldset-plain" disabled', false);
    $this->post('/server/firewall/10/edit', ['action' => 'DROP', 'protocol' => 'TCP', 'port' => '30005', 'ip' => '0.0.0.0/0'])->assertForbidden();
    $this->post('/server/firewall/10/suspend')->assertForbidden();
    $this->delete('/server/firewall/10')->assertForbidden();
    $this->fake->assertNotRan('v-change-firewall-rule');
    $this->fake->assertNotRan('v-suspend-firewall-rule');
    $this->fake->assertNotRan('v-delete-firewall-rule');
});

// Actions (§6.1)

it('moves a rule up or down, managed ones included', function () {
    $this->fake->on('v-move-firewall-rule')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/firewall/2/move/up')->assertRedirect('/server/firewall');
    $this->post('/server/firewall/10/move/up')->assertRedirect('/server/firewall');
    $this->fake->assertRan('v-move-firewall-rule', fn ($a) => $a === ['2', 'up']);
    $this->fake->assertRan('v-move-firewall-rule', fn ($a) => $a === ['10', 'up']);
    $this->post('/server/firewall/2/move/sideways')->assertNotFound();
    $this->post('/server/firewall/99/move/down')->assertNotFound();
});

it('suspends, unsuspends and deletes single rules', function () {
    $this->fake->on('v-suspend-firewall-rule')->returns();
    $this->fake->on('v-unsuspend-firewall-rule')->returns();
    $this->fake->on('v-delete-firewall-rule')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/firewall/2/suspend')->assertRedirect('/server/firewall')->assertSessionHas('ok', 'Rule has been suspended.');
    $this->post('/server/firewall/3/unsuspend')->assertSessionHas('ok', 'Rule has been unsuspended.');
    $this->delete('/server/firewall/2')->assertSessionHas('ok', 'Rule has been deleted.');
    $this->fake->assertRan('v-suspend-firewall-rule', fn ($a) => $a === ['2']);
    $this->fake->assertRan('v-unsuspend-firewall-rule', fn ($a) => $a === ['3']);
    $this->fake->assertRan('v-delete-firewall-rule', fn ($a) => $a === ['2']);
});

it('runs bulk actions and skips the managed rules', function () {
    $this->fake->on('v-suspend-firewall-rule')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/firewall/bulk', ['action' => 'suspend', 'items' => ['1', '2', '10']])
        ->assertRedirect('/server/firewall')->assertSessionHas('error', '2 done, 1 failed');
    $this->fake->assertRan('v-suspend-firewall-rule', fn ($a) => $a === ['1']);
    $this->fake->assertRan('v-suspend-firewall-rule', fn ($a) => $a === ['2']);
    $this->fake->assertRan('v-suspend-firewall-rule', fn ($a) => $a === ['10'], 0);

    $this->post('/server/firewall/bulk', ['action' => 'delete', 'items' => ['99']])->assertNotFound();
    $this->post('/server/firewall/bulk', ['action' => 'rebuild', 'items' => ['1']])->assertSessionHasErrors(['action']);
});

// IPset lists (§6.2)

it('lists, adds and deletes IPset lists', function () {
    $this->fake->on('v-add-firewall-ipset')->returns();
    $this->fake->on('v-delete-firewall-ipset')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/ipsets')->assertOk()
        ->assertSeeInOrder(['blocked6', 'country-nl', 'old'])->assertSee('Enabled')->assertSee('Disabled')
        ->assertSee('Are you sure you want to delete IP list country-nl?')->assertSee('3 IP lists');
    $this->get('/server/firewall/ipsets/create')->assertOk()
        ->assertSee('(URL, script or file)')->assertSee('[IPv4] Block Malicious IPs')
        ->assertSee('country/nl/ipv6-aggregated.txt', false)->assertSee('[IPv6] Country - Netherlands')
        ->assertSee('script:/usr/local/arcpanel/install/common/firewall/ipset/blacklist.sh', false);

    $this->post('/server/firewall/ipsets', ['name' => 'country-de', 'source' => 'https://example.org/de.txt', 'version' => 'v4', 'autoupdate' => 'yes'])
        ->assertRedirect('/server/firewall/ipsets')->assertSessionHas('ok', 'IP list has been created successfully.');
    $this->fake->assertRan('v-add-firewall-ipset', fn ($a) => $a === ['country-de', 'https://example.org/de.txt', 'v4', 'yes']);

    $this->post('/server/firewall/ipsets', ['name' => '', 'source' => '', 'version' => 'v4', 'autoupdate' => 'yes'])
        ->assertSessionHasErrors(['name' => 'Field "IP List Name" can not be blank.', 'source' => 'Field "Data Source" can not be blank.']);

    $this->delete('/server/firewall/ipsets/country-nl')->assertRedirect('/server/firewall/ipsets');
    $this->fake->assertRan('v-delete-firewall-ipset', fn ($a) => $a === ['country-nl']);
    $this->post('/server/firewall/ipsets/bulk', ['action' => 'delete', 'items' => ['old', 'blocked6']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->delete('/server/firewall/ipsets/ghost')->assertNotFound();
});

// Ban list (§6.2)

it('needs the firewall extension for the ban list', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/bans')->assertNotFound();
    $this->post('/server/firewall/bans', ['ip' => '198.51.100.1', 'chain' => 'SSH'])->assertNotFound();
});

it('bans and unbans addresses, IPv6 included', function () {
    withSysConfig($this->fake, ['FIREWALL_EXTENSION' => 'fail2ban']);
    $this->fake->on('v-add-firewall-ban')->returns();
    $this->fake->on('v-delete-firewall-ban')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/bans')->assertOk()
        ->assertSeeInOrder(['2001:db8::7', '203.0.113.7'])
        ->assertSee('WEB:2001:db8::7', false)->assertSee('Are you sure you want to delete IP address 203.0.113.7?');
    $this->get('/server/firewall/bans/create')->assertOk()->assertSee('ARCPANEL')->assertSee('RECIDIVE')
        ->assertSee('(IPv4 or IPv6, CIDR format supported)');

    $this->post('/server/firewall/bans', ['ip' => '198.51.100.0/24', 'chain' => 'MAIL'])
        ->assertRedirect('/server/firewall/bans')->assertSessionHas('ok', 'IP address has been banned successfully.');
    $this->fake->assertRan('v-add-firewall-ban', fn ($a) => $a === ['198.51.100.0/24', 'MAIL']);
    $this->post('/server/firewall/bans', ['ip' => '1.2.3.4', 'chain' => 'NOPE'])->assertSessionHasErrors(['chain']);

    $this->delete('/server/firewall/bans', ['item' => 'WEB:2001:db8::7'])->assertRedirect('/server/firewall/bans');
    $this->fake->assertRan('v-delete-firewall-ban', fn ($a) => $a === ['2001:db8::7', 'WEB']);
    $this->post('/server/firewall/bans/bulk', ['action' => 'delete', 'items' => ['SSH:203.0.113.7', 'WEB:2001:db8::7']])->assertSessionHas('ok', '2 done, 0 failed');
    $this->delete('/server/firewall/bans', ['item' => 'SSH:192.0.2.1'])->assertNotFound();
    $this->delete('/server/firewall/bans', ['item' => 'garbage'])->assertStatus(422);
});

it('has a capability for every firewall route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.firewall'));

    expect($routes)->toContain('server.firewall', 'server.firewall.create', 'server.firewall.store', 'server.firewall.edit',
        'server.firewall.update', 'server.firewall.move', 'server.firewall.suspend', 'server.firewall.unsuspend',
        'server.firewall.destroy', 'server.firewall.bulk', 'server.firewall.ipsets', 'server.firewall.ipsets.store',
        'server.firewall.ipsets.destroy', 'server.firewall.bans', 'server.firewall.bans.store', 'server.firewall.bans.destroy',
        'server.firewall.reload')
        ->and(Capabilities::names())->toContain('server.firewall');
});

// E40: nftables with IPv6 (docs/features/specs/2026-10-19-002-e40-nftables-firewall-design.md)

it('offers IPv6 lists in the rule form', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall/create')->assertOk()
        ->assertSee('value="ipset:blocked6"', false)->assertSee('value="ipset:country-nl"', false)
        ->assertDontSee('value="ipset:old"', false)
        ->assertSee('0.0.0.0/0 or ::/0 means every address of both versions.');
});

it('warns about a drifted or missing table and reloads it for administrators', function (string $status, string $title) {
    $this->fake->on('v-check-firewall', ['json'])->returnsJson(['ENGINE' => 'nftables', 'STATUS' => $status, 'CHECKED' => '2026-10-19T12:00:00Z']);
    $this->fake->on('v-update-firewall')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall')->assertOk()->assertSee($title)->assertSee('Reload firewall');
    $this->post('/server/firewall/reload')->assertRedirect('/server/firewall')
        ->assertSessionHas('ok', 'The firewall has been reloaded.');
    $this->fake->assertRan('v-update-firewall', fn ($a) => $a === []);
})->with([
    'drift' => ['drift', 'The loaded firewall rules differ from these rules'],
    'missing' => ['missing', 'The firewall rules are not loaded'],
]);

it('shows no warning while the table is in sync', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall')->assertOk()->assertDontSee('Reload firewall');
});

it('reports a reload that nftables refuses', function () {
    $this->fake->on('v-update-firewall')->returnsExit(ExitCode::Update, 'Error: nftables refused the firewall rules: custom.nft:1');
    signIn('arcadmin', 'admin');

    $this->post('/server/firewall/reload')->assertRedirect('/server/firewall')
        ->assertSessionHas('error', 'Error: nftables refused the firewall rules: custom.nft:1');
});

it('refuses the reload to everyone but administrators', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->post('/server/firewall/reload')->assertForbidden();
    $this->fake->assertNotRan('v-update-firewall');
});

it('names the any address of both versions in the rule list', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/firewall')->assertOk()->assertSee('Any address')->assertSee('IPv4 and IPv6')->assertSee('title="0.0.0.0/0"', false);
});
