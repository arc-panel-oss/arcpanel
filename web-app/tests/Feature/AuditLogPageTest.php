<?php

use App\Arc\ExitCode;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\View\Navigation;

// E23 audit log: the page, the export, the chain check, the settings and
// REST API v2 (docs/features/specs/2026-10-23-002-e23-audit-log-design.md §7).
// hostco owns bob, resb owns carol and dave; arcadmin is the administrator.

/** One event as `v-list-sys-audit … json` prints it. */
function auditEvent(array $overrides = []): array
{
    return array_replace_recursive([
        'v' => 1,
        'id' => '01JBX6W3Q2Z8K4N7P5R9T0V1C2',
        'ts' => '2026-10-23T08:15:02.481Z',
        'request_id' => 'req-1',
        'channel' => 'web',
        'actor' => ['user' => 'arcadmin', 'role' => 'admin', 'ip' => '203.0.113.7', 'key' => null, 'ua' => '0123456789abcdef'],
        'effective_user' => 'bob',
        'action' => 'web.domains.update.php',
        'object' => ['type' => 'web_domain', 'user' => 'bob', 'name' => 'shop.example.com'],
        'result' => 'ok',
        'status' => 302,
        'fields' => ['version'],
        'prev' => str_repeat('0', 64),
        'hash' => str_repeat('a', 64),
    ], $overrides);
}

/** Answer `v-list-sys-audit` with these events and record the arguments. */
function fakeAuditList($fake, array $events, ?int $total = null): void
{
    $fake->on('v-list-sys-audit')->using(fn (array $args) => $args[3] === 'json'
        ? [0, (string) json_encode(['TOTAL' => $total ?? count($events), 'LIMIT' => (int) $args[4], 'OFFSET' => (int) $args[5], 'SIZE' => 2048, 'EVENTS' => $events])]
        : [0, "TIME,ID,CHANNEL,ACTOR\n2026-10-23T08:15:02.481Z,01JBX6W3Q2Z8K4N7P5R9T0V1C2,web,arcadmin\n"]);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeAuditList($this->fake, [auditEvent(), auditEvent(['id' => '01JBX6W3Q2Z8K4N7P5R9T0V1C3', 'action' => 'impersonate.start', 'result' => 'denied', 'object' => null])]);
});

it('adds the capabilities of the audit log', function () {
    expect(Capabilities::names())->toContain('audit.view', 'audit.verify');
});

it('shows the events to an administrator, newest first, with the login-as actor and the chain check', function () {
    signIn('arcadmin', 'admin');

    $this->get('/audit?user=bob&result=ok&from=2026-10-01&to=2026-10-23')->assertOk()
        ->assertSee('Audit log')
        ->assertSee('web.domains.update.php')
        ->assertSee('as bob')
        ->assertSee('shop.example.com')
        ->assertSee('Verify chain')
        ->assertSee('Export CSV');

    $this->fake->assertRan('v-list-sys-audit', fn ($a) => $a === ['2026-10-01', '2026-10-23', 'USER=bob RESULT=ok', 'json', '50', '0']);
});

it('lists the last 7 days by default and pages by 50', function () {
    signIn('arcadmin', 'admin');
    fakeAuditList($this->fake, [auditEvent()], 120);

    $this->get('/audit?page=3')->assertOk();

    $today = gmdate('Y-m-d');
    $from = gmdate('Y-m-d', strtotime($today.' -6 days'));
    $this->fake->assertRan('v-list-sys-audit', fn ($a) => $a === [$from, $today, '', 'json', '50', '100']);
});

it('refuses filters outside their grammar', function () {
    signIn('arcadmin', 'admin');

    $this->get('/audit?result=maybe')->assertSessionHasErrors('result');
    $this->get('/audit?user=bad%20name')->assertSessionHasErrors('user');
    $this->get('/audit?from=2026-10-23&to=2026-10-01')->assertSessionHasErrors('to');
    $this->fake->assertNotRan('v-list-sys-audit');
});

it('limits a reseller to its tree and hides the chain check', function (string $reseller) {
    signIn($reseller, 'reseller');

    $this->get('/audit')->assertOk()->assertDontSee('Verify chain');
    $this->get('/audit/verify')->assertForbidden();
    $this->get('/audit/export?format=csv')->assertOk();

    $this->fake->assertRan('v-list-sys-audit', fn ($a) => $a[2] === 'OWNER='.$reseller && $a[3] === 'json');
    $this->fake->assertRan('v-list-sys-audit', fn ($a) => $a[2] === 'OWNER='.$reseller && $a[3] === 'csv');
    $this->fake->assertNotRan('v-check-sys-audit');
})->with(['reseller A' => 'hostco', 'reseller B' => 'resb']);

it('refuses users, also through an administrator acting as one', function () {
    signIn('bob', 'user');
    $this->get('/audit')->assertForbidden();
    $this->get('/audit/export?format=csv')->assertForbidden();

    signIn('arcadmin', 'admin', ['look' => 'bob']);
    $this->get('/audit')->assertForbidden();
    $this->fake->assertNotRan('v-list-sys-audit');
});

it('puts the page under Server for administrators and under Accounts for resellers', function () {
    $keys = function (string $user, string $role) {
        $groups = app(Navigation::class)->for(principal($user, Role::from($role)), null);

        return collect($groups)->mapWithKeys(fn ($group) => [$group['key'] => array_column($group['items'], 'key')])->all();
    };

    expect($keys('arcadmin', 'admin')['server'])->toContain('audit.index')
        ->and($keys('arcadmin', 'admin')['accounts'])->not->toContain('audit.index')
        ->and($keys('hostco', 'reseller')['accounts'])->toContain('audit.index')
        ->and(array_merge(...array_values($keys('bob', 'user'))))->not->toContain('audit.index');
});

it('exports the filtered events as CSV or JSON, at most 100000', function () {
    signIn('arcadmin', 'admin');

    $csv = $this->get('/audit/export?format=csv&action=auth.&from=2026-10-20&to=2026-10-23')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertDownload('audit-2026-10-20-2026-10-23.csv');
    expect($csv->streamedContent())->toStartWith('TIME,ID,CHANNEL,ACTOR');
    $this->fake->assertRan('v-list-sys-audit', fn ($a) => $a === ['2026-10-20', '2026-10-23', 'ACTION=auth.', 'csv', '100000', '0']);

    $json = $this->get('/audit/export?format=json')->assertOk()->assertHeader('Content-Type', 'application/json');
    expect(json_decode($json->streamedContent(), true)['EVENTS'])->toHaveCount(2);

    $this->get('/audit/export?format=xml')->assertSessionHasErrors('format');
});

it('verifies the chain of the shown range and shows a break in red', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-check-sys-audit')->returnsJson(['OK' => true, 'FROM' => '2026-10-01', 'TO' => '2026-10-23', 'DAYS' => 23, 'COUNT' => 4200]);

    $this->get('/audit/verify?from=2026-10-01&to=2026-10-23')->assertOk()
        ->assertSee('The chain is intact')->assertSee('4200 events from 2026-10-01 to 2026-10-23 verified.');
    $this->fake->assertRan('v-check-sys-audit', fn ($a) => $a === ['2026-10-01', '2026-10-23', 'json']);

    $this->fake->on('v-check-sys-audit')->returnsExit(ExitCode::Invalid, (string) json_encode([
        'OK' => false, 'FROM' => '2026-10-01', 'TO' => '2026-10-23', 'DAYS' => 3, 'COUNT' => 20, 'DAY' => '2026-10-03', 'LINE' => 7,
        'REASON' => 'the hash does not match the line (the line was changed)',
    ]));
    $this->get('/audit/verify?from=2026-10-01&to=2026-10-23')->assertOk()
        ->assertSee('The chain is broken')->assertSee('data-audit-verify="broken"', false)
        ->assertSee('2026-10-03, line 7: the hash does not match the line (the line was changed)');
});

// REST API v2

it('lists audit events over REST API v2 with the rules of the page', function (string $principal, string $role, int $status, ?string $owner) {
    $fake = fakeApi();
    fakeResellers($fake);
    fakeAuditList($fake, [auditEvent()]);
    $headers = apiAs($fake, $principal, $role, readOnly: true);

    $response = $this->getJson('/api/v2/audit?from=2026-10-01&to=2026-10-23&user=bob&limit=10&offset=20', $headers)->assertStatus($status);
    if ($status !== 200) {
        $fake->assertNotRan('v-list-sys-audit');

        return;
    }
    $response->assertJsonPath('meta', ['total' => 1, 'limit' => 10, 'offset' => 20, 'from' => '2026-10-01', 'to' => '2026-10-23'])
        ->assertJsonPath('data.0.id', '01JBX6W3Q2Z8K4N7P5R9T0V1C2')
        ->assertJsonPath('data.0.actor', ['user' => 'arcadmin', 'role' => 'admin', 'ip' => '203.0.113.7', 'access_key' => null, 'user_agent_hash' => '0123456789abcdef'])
        ->assertJsonPath('data.0.effective_user', 'bob')
        ->assertJsonPath('data.0.object', ['type' => 'web_domain', 'user' => 'bob', 'name' => 'shop.example.com'])
        ->assertJsonPath('data.0.time', '2026-10-23T08:15:02.481Z');
    $fake->assertRan('v-list-sys-audit', fn ($a) => $a === ['2026-10-01', '2026-10-23', $owner === null ? 'USER=bob' : 'USER=bob OWNER='.$owner, 'json', '10', '20']);
})->with([
    'admin' => ['arcadmin', 'admin', 200, null],
    'reseller A' => ['hostco', 'reseller', 200, 'hostco'],
    'reseller B' => ['resb', 'reseller', 200, 'resb'],
    'user' => ['bob', 'user', 403, null],
]);

it('lists with a key that holds only v2-audit or v2-read and refuses a key without them', function () {
    $fake = fakeApi();
    fakeResellers($fake);
    fakeAuditList($fake, []);

    $this->getJson('/api/v2/audit', apiAs($fake, 'arcadmin', 'admin', area: 'audit'))->assertOk();
    $this->getJson('/api/v2/audit', apiKey($fake, 'arcadmin', 'admin', ['v2-read']))->assertOk();
    $this->getJson('/api/v2/audit', apiAs($fake, 'arcadmin', 'admin', area: 'web'))->assertForbidden();
});

it('verifies the chain over REST API v2 for administrators only', function () {
    $fake = fakeApi();
    fakeResellers($fake);
    $fake->on('v-check-sys-audit')->returnsExit(ExitCode::Invalid, (string) json_encode([
        'OK' => false, 'FROM' => '2026-10-01', 'TO' => '2026-10-23', 'DAYS' => 3, 'COUNT' => 20, 'DAY' => '2026-10-03', 'LINE' => 7, 'REASON' => 'changed',
    ]));

    $this->getJson('/api/v2/audit/verify?from=2026-10-01&to=2026-10-23', apiAs($fake, 'arcadmin', 'admin', area: 'audit'))->assertOk()
        ->assertJsonPath('data', ['ok' => false, 'from' => '2026-10-01', 'to' => '2026-10-23', 'count' => 20, 'day' => '2026-10-03', 'line' => 7, 'reason' => 'changed']);
    $fake->assertRan('v-check-sys-audit', fn ($a) => $a === ['2026-10-01', '2026-10-23', 'json']);

    $this->getJson('/api/v2/audit/verify', apiAs($fake, 'hostco', 'reseller', readOnly: true))->assertForbidden();
    $this->getJson('/api/v2/audit/verify', apiAs($fake, 'bob', 'user', readOnly: true))->assertForbidden();
});
