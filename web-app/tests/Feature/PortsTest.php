<?php

use App\Arc\ExitCode;

// Port registry pages and actions (E12 spec §7).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    $this->fake->on('v-list-user-ports', ['hostco', 'json'])->returnsJson(portAccount('hostco',
        ['ROLE' => 'reseller', 'LIMIT' => '20', 'ALLOCATED' => '15', 'FREE' => '15', 'TREE_TOTAL' => '20', 'TREE_USED' => '5'],
        [portEntry('30010-30024', ['PUBLIC_OK' => 'yes'])]));
    $this->fake->on('v-list-user-ports', ['bob', 'json'])->returnsJson(portAccount('bob',
        ['OWNER' => 'hostco', 'LIMIT' => '5', 'ALLOCATED' => '5', 'ASSIGNED' => '1', 'FREE' => '4'],
        [portEntry('30005', ['APP' => 'manual', 'COMMENT' => 'game server', 'PUBLIC_OK' => 'yes', 'LISTEN' => 'local']), portEntry('30006-30009', ['PUBLIC_OK' => 'yes'])]));
    $this->fake->on('v-list-user-ports', ['carol', 'json'])->returnsJson(portAccount('carol',
        ['OWNER' => 'resb', 'LIMIT' => '2', 'ALLOCATED' => '2', 'ASSIGNED' => '1', 'FREE' => '1'],
        [portEntry('30030', ['APP' => 'node:carol.example']), portEntry('30031')]));
    $this->fake->on('v-list-sys-ports', ['json'])->returnsJson([
        'RANGE_START' => '30000', 'RANGE_END' => '39999', 'REGISTRY' => 'yes', 'FIREWALL' => 'iptables',
        'USERS' => [
            'arcadmin' => ['OWNER' => '', 'ROLE' => 'admin', 'SUSPENDED' => 'no', 'LIMIT' => '0', 'ALLOCATED' => '0', 'ASSIGNED' => '0', 'PUBLIC' => '0', 'FREE' => '0', 'TREE_TOTAL' => '', 'TREE_USED' => ''],
            'hostco' => ['OWNER' => 'arcadmin', 'ROLE' => 'reseller', 'SUSPENDED' => 'no', 'LIMIT' => '20', 'ALLOCATED' => '15', 'ASSIGNED' => '0', 'PUBLIC' => '0', 'FREE' => '15', 'TREE_TOTAL' => '20', 'TREE_USED' => '5'],
            'bob' => ['OWNER' => 'hostco', 'ROLE' => 'user', 'SUSPENDED' => 'no', 'LIMIT' => '5', 'ALLOCATED' => '5', 'ASSIGNED' => '1', 'PUBLIC' => '0', 'FREE' => '4', 'TREE_TOTAL' => '', 'TREE_USED' => ''],
        ],
        'ENTRIES' => [
            portEntry('30005', ['USER' => 'bob', 'APP' => 'manual', 'COMMENT' => 'game server', 'PUBLIC_OK' => 'yes']),
            portEntry('30010-30024', ['USER' => 'hostco', 'PUBLIC_OK' => 'yes']),
            portEntry('30302', ['USER' => 'carol', 'APP' => 'manual']),
        ],
        'RESERVED' => [portEntry('8083', ['APP' => 'system', 'COMMENT' => 'panel']), portEntry('31000-31009', ['APP' => 'admin', 'COMMENT' => 'monitoring'])],
        'SOCKETS' => [['PROTO' => 'tcp', 'PORT' => '30300', 'SCOPE' => 'any', 'UID' => '1004', 'ACCOUNT' => 'bob']],
        'FINDINGS' => [
            ['TYPE' => 'orphan-socket', 'USER' => 'bob', 'PORT' => '30300', 'PROTO' => 'tcp', 'RULE' => '', 'DETAIL' => 'listening without an entry'],
            ['TYPE' => 'unmanaged-rule', 'USER' => '', 'PORT' => '30302', 'PROTO' => 'tcp', 'RULE' => '14', 'DETAIL' => 'manual rule'],
            ['TYPE' => 'stale-rule', 'USER' => '', 'PORT' => '30400', 'PROTO' => 'tcp', 'RULE' => '15', 'DETAIL' => 'arcpanel-port-30400-gone'],
        ],
    ]);
});

it('shows Admin → Server → Ports with entries, findings and their actions', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/ports')->assertOk()
        ->assertSee('30010-30024')->assertSee('game server', false)->assertSee('Listening without an owner')
        ->assertSee(route('server.ports.register', ['user' => 'bob', 'port' => '30300', 'proto' => 'tcp']))
        ->assertSee(route('server.ports.adopt', ['port' => '30302', 'rule' => '14']))
        ->assertSee(route('server.ports.close-rule', ['rule' => '15']), false)
        ->assertSee('monitoring')->assertSee(route('server.ports.reserved.destroy', ['range' => '31000-31009']), false)
        ->assertDontSee(route('server.ports.reserved.destroy', ['range' => '8083']), false)
        ->assertSee('Allocate ports');
});

it('links the signed-in administrator to Account on Server → Ports, not to its refused user page', function () {
    $sys = json_decode($this->fake->run('v-list-sys-ports', ['json'])->stdout, true);
    $sys['ENTRIES'][] = portEntry('31500', ['USER' => 'arcadmin', 'APP' => 'manual']);
    $this->fake->on('v-list-sys-ports', ['json'])->returnsJson($sys);
    signIn('arcadmin', 'admin');

    $this->get('/server/ports')->assertOk()
        ->assertSee(route('users.show', ['user' => 'hostco']), false)
        ->assertSee(route('account.edit').'">arcadmin<', false)
        ->assertDontSee(route('users.show', ['user' => 'arcadmin']), false);
});

it('keeps Server → Ports for administrators and sends them there from /ports', function () {
    signIn('bob', 'user');
    $this->get('/server/ports')->assertForbidden();
    $this->post('/server/ports/allocate', ['user' => 'bob', 'mode' => 'count', 'count' => 5])->assertForbidden();

    signIn('hostco', 'reseller');
    $this->get('/server/ports')->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->get('/ports')->assertRedirect('/server/ports');
});

it('shows a user its own ports with reserve and request actions', function () {
    signIn('bob', 'user');

    $this->get('/ports')->assertOk()
        ->assertSee('30005')->assertSee('30006-30009')->assertSee('Reserve a port')->assertSee('Request more ports')
        ->assertSee(route('ports.public', ['user' => 'bob', 'range' => '30005', 'public' => 'yes']), false)
        ->assertDontSee('30030')->assertDontSee('Give ports');
});

it('shows a reseller its pool and its customers only', function () {
    signIn('hostco', 'reseller');

    $this->get('/ports')->assertOk()
        ->assertSee('30010-30024')->assertSee('Your pool')->assertSee('Give ports')
        ->assertSee('30005')->assertSee(route('ports.give', ['user' => 'bob']), false)
        ->assertDontSee('30030');
});

it('reserves and releases ports of the own pool', function () {
    signIn('bob', 'user');
    $this->fake->on('v-allocate-user-port')->returns('30006');
    $this->fake->on('v-release-user-port')->returns();

    $this->post('/ports/bob/reserve', ['proto' => 'tcp', 'comment' => 'web app'])->assertSessionHas('ok');
    $this->fake->assertRan('v-allocate-user-port', fn ($a) => $a === ['bob', 'manual', 'tcp', '', 'web app']);

    $this->post('/ports/bob/reserve', ['port' => '30007', 'proto' => 'udp'])->assertSessionHas('ok');
    $this->fake->assertRan('v-allocate-user-port', fn ($a) => $a === ['bob', 'manual', 'udp', '30007', '']);

    $this->post('/ports/bob/reserve', ['proto' => 'tcp', 'comment' => "bad'quote"])->assertSessionHasErrors('comment');

    $this->post('/ports/bob/30005/release')->assertSessionHas('ok');
    $this->fake->assertRan('v-release-user-port', fn ($a) => $a === ['bob', '30005']);
});

it('answers 404 for ports outside the scope', function () {
    signIn('bob', 'user');

    $this->post('/ports/carol/reserve', ['proto' => 'tcp'])->assertNotFound();
    $this->post('/ports/carol/30030/public', ['public' => 'no'])->assertNotFound();

    signIn('hostco', 'reseller');
    $this->post('/ports/carol/give', ['mode' => 'count', 'count' => 1])->assertNotFound();
});

it('opens only assigned ports that may be public', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-user-port-public')->returns();

    $this->post('/ports/bob/30005/public', ['public' => 'yes'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-public', fn ($a) => $a === ['bob', '30005', 'yes']);

    // a free range is not an app's port
    $this->post('/ports/bob/30006-30009/public', ['public' => 'yes'])->assertNotFound();

    signIn('arcadmin', 'admin');
    $this->post('/ports/carol/30030/public', ['public' => 'yes'])->assertForbidden();
});

it('lets administrators allow public ports and resellers only forbid them', function () {
    $this->fake->on('v-change-user-port-public-ok')->returns();

    signIn('bob', 'user');
    $this->post('/ports/bob/30006-30009/public-ok', ['public_ok' => 'no'])->assertForbidden();

    signIn('hostco', 'reseller');
    $this->post('/ports/bob/30006-30009/public-ok', ['public_ok' => 'yes'])->assertForbidden();
    $this->post('/ports/bob/30006-30009/public-ok', ['public_ok' => 'no'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-public-ok', fn ($a) => $a === ['bob', '30006-30009', 'no']);
    $this->post('/ports/hostco/30010-30024/public-ok', ['public_ok' => 'no'])->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->post('/ports/carol/30031/public-ok', ['public_ok' => 'yes'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-public-ok', fn ($a) => $a === ['carol', '30031', 'yes']);
});

it('lets a reseller give and take back free ports of its pool', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-change-user-port-owner')->returns();

    $this->post('/ports/bob/give', ['mode' => 'count', 'count' => 5])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['hostco', '+5', 'bob']);

    $this->post('/ports/bob/give', ['mode' => 'range', 'range' => '30020-30024'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['hostco', '30020-30024', 'bob']);

    $this->post('/ports/bob/30006-30009/reclaim')->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-owner', fn ($a) => $a === ['bob', '30006-30009', 'hostco']);

    signIn('bob', 'user');
    $this->post('/ports/bob/give', ['mode' => 'count', 'count' => 5])->assertForbidden();
});

it('shows the command error when a pool has no room', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-change-user-port-owner')->returnsExit(ExitCode::Limit, 'Error: hostco has 15 free ports, 50 requested');

    $this->post('/ports/bob/give', ['mode' => 'count', 'count' => 50])
        ->assertSessionHas('error')->assertSessionHas('error_detail', 'hostco has 15 free ports, 50 requested');
});

it('allocates, deletes and repairs as an administrator', function () {
    signIn('arcadmin', 'admin');
    foreach (['v-add-user-port', 'v-add-user-port-pool', 'v-delete-user-port', 'v-change-sys-port-range', 'v-add-sys-port-reserved',
        'v-delete-sys-port-reserved', 'v-check-sys-ports', 'v-allocate-user-port', 'v-change-user-port-public', 'v-change-user-port-public-ok', 'v-delete-firewall-rule'] as $command) {
        $this->fake->on($command)->returns();
    }

    $this->post('/server/ports/allocate', ['user' => 'hostco', 'mode' => 'range', 'range' => '31100-31199', 'public_ok' => '1', 'comment' => 'reseller block'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-user-port', fn ($a) => $a === ['hostco', '31100-31199', 'yes', 'reseller block']);
    $this->post('/server/ports/allocate', ['user' => 'bob', 'mode' => 'count', 'count' => '5'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-user-port-pool', fn ($a) => $a === ['bob', '5', 'no']);
    $this->post('/server/ports/allocate', ['user' => 'ghost', 'mode' => 'count', 'count' => '5'])->assertSessionHasErrors('user');
    $this->post('/server/ports/allocate', ['user' => 'bob', 'mode' => 'range', 'range' => '31100;rm'])->assertSessionHasErrors('range');

    $this->delete('/ports/bob/30005?force=1')->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-user-port', fn ($a) => $a === ['bob', '30005', 'force']);

    $this->post('/server/ports/range', ['start' => '30000', 'end' => '29999'])->assertSessionHasErrors('end');
    $this->post('/server/ports/range', ['start' => '30000', 'end' => '34999'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-port-range', fn ($a) => $a === ['30000', '34999']);

    $this->post('/server/ports/reserved', ['range' => '31500', 'comment' => 'agent'])->assertSessionHas('ok');
    $this->delete('/server/ports/reserved/31500')->assertSessionHas('ok');
    $this->post('/server/ports/repair')->assertSessionHas('ok');
    $this->fake->assertRan('v-check-sys-ports', fn ($a) => $a === ['repair']);

    $this->post('/server/ports/register', ['user' => 'bob', 'port' => '30300', 'proto' => 'tcp'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-user-port', fn ($a) => $a === ['bob', '30300']);
    $this->fake->assertRan('v-allocate-user-port', fn ($a) => $a === ['bob', 'manual', 'tcp', '30300']);

    $this->post('/server/ports/adopt', ['port' => '30302', 'rule' => '14'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-user-port-public', fn ($a) => $a === ['carol', '30302', 'yes', '14']);

    $this->post('/server/ports/close-rule', ['rule' => '15'])->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-firewall-rule', fn ($a) => $a === ['15']);
});

it('sends a port request to the owner', function () {
    signIn('bob', 'user');
    $this->fake->on('v-add-user-notification')->returns();

    $this->post('/ports/request', ['count' => '3', 'reason' => 'For a staging app'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-user-notification', fn ($a) => $a[0] === 'hostco' && $a[1] === 'Port request' && $a[2] === 'bob asks for 3 more ports. For a staging app');

    $this->post('/ports/request', ['count' => '3', 'reason' => '<script>'])->assertSessionHasErrors('reason');

    signIn('arcadmin', 'admin');
    $this->post('/ports/request', ['count' => '3'])->assertForbidden();
});

it('shows the ports of a user on its detail page', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-list-web-domains', ['bob', 'json'])->returnsJson([]);

    $this->get('/users/bob')->assertOk()->assertSee('Ports')->assertSee('5 / 5');
});
