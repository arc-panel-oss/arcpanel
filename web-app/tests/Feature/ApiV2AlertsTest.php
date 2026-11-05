<?php

use App\Arc\ExitCode;

// REST API v2, alerts (E16 spec §8). hostco owns bob, resb owns carol and
// dave; arcadmin is the root administrator. Secrets never come back.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeAlertCommands($this->fake);
});

/** Main paths run with the default key and with a key that holds only the alerts set (D30). */
dataset('alert keys', [
    'default key' => [null],
    'v2-alerts key' => ['alerts'],
]);

it('lists the open alerts of an account in the key\'s reach', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/alerts", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.object', 'web:bob.example')
            ->assertJsonPath('data.0.severity', 'warning')
            ->assertJsonPath('data.0.fired_at', '2026-11-06T06:00:00');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('lists the open alerts of every scope the key sees', function () {
    $this->getJson('/api/v2/alerts', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v2/alerts', apiAs($this->fake, 'hostco', 'reseller'))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.scope', 'bob');
    $this->getJson('/api/v2/alerts', apiAs($this->fake, 'carol', 'user'))->assertOk()->assertJsonPath('meta.total', 0);
    $this->fake->assertRan('v-list-alerts', fn ($a) => $a === ['all', 'json']);
});

it('keeps the server scope to administrators', function (string $principal, string $role, int $status) {
    $this->fake->on('v-change-alert-rule')->returns();
    $this->getJson('/api/v2/system/alert-rules', apiAs($this->fake, $principal, $role))->assertStatus($status);
    $this->getJson('/api/v2/system/alert-channels', apiAs($this->fake, $principal, $role))->assertStatus($status);
    $this->putJson('/api/v2/system/alert-rules/disk.usage', ['enabled' => false], apiAs($this->fake, $principal, $role))->assertStatus($status);
})->with([
    'admin' => ['arcadmin', 'admin', 200],
    'reseller' => ['hostco', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('reads and changes a rule; fields left out keep their value', function (?string $area) {
    $this->fake->on('v-change-alert-rule')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);

    $this->getJson('/api/v2/users/bob/alert-rules', $headers)->assertOk()
        ->assertJsonPath('data.0', ['rule' => 'ssl.expiring', 'title' => 'Certificate expiring', 'kind' => 'metric', 'enabled' => true,
            'warning' => 14, 'critical' => 3, 'unit' => 'days', 'levels' => 'below', 'for' => 0, 'severity' => null, 'cooldown' => 1440,
            'channels' => ['17900000000001'], 'options' => '', 'interval' => 360, 'custom' => true]);
    $this->getJson('/api/v2/users/bob/alert-rules/backup.failed', $headers)->assertOk()->assertJsonPath('data.severity', 'info');
    $this->getJson('/api/v2/users/bob/alert-rules/disk.usage', $headers)->assertNotFound();

    $this->putJson('/api/v2/users/bob/alert-rules/ssl.expiring', ['warning' => 21, 'channels' => ['17900000000001', '17900000000002']], $headers)
        ->assertOk()->assertJsonPath('data.rule', 'ssl.expiring');
    $this->fake->assertRan('v-change-alert-rule', fn ($a) => $a === ['bob', 'ssl.expiring', 'yes', '21', '3', '0', '', '1440', '17900000000001,17900000000002', '']);
    $this->putJson('/api/v2/users/bob/alert-rules/ssl.expiring', ['cooldown' => 0], $headers)->assertStatus(422);

    $this->fake->on('v-change-alert-rule')->returnsExit(ExitCode::Invalid, 'Error: the critical threshold must not be above the warning threshold');
    $this->putJson('/api/v2/users/bob/alert-rules/ssl.expiring', ['critical' => 30], $headers)->assertStatus(422)
        ->assertJsonPath('error.code', 'E_INVALID');
})->with('alert keys');

it('adds a channel with a write-only secret, changes, tests and deletes it', function (?string $area) {
    $this->fake->on('v-add-alert-channel')->using(function () {
        $this->fake->on('v-list-alert-channels')->using(fn (array $a) => [0, (string) json_encode(alertChannelRows($a[0]) + ['17900000000003' => [
            'ID' => '17900000000003', 'NAME' => 'Hook', 'TYPE' => 'slack', 'TARGET' => '', 'ENABLED' => 'yes', 'STATUS' => '', 'LAST_SENT' => '',
            'FAILS' => '0', 'SECRET_SET' => 'yes']])]);

        return [0, '17900000000003'];
    });
    $this->fake->on('v-change-alert-channel')->returns();
    $this->fake->on('v-delete-alert-channel')->returns();
    $this->fake->on('v-test-alert-channel')->using(fn () => [ExitCode::Connect->value, (string) json_encode(['RESULT' => 'failed', 'ERROR' => 'HTTP 404'])]);
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);

    $response = $this->postJson('/api/v2/users/bob/alert-channels', ['name' => 'Hook', 'type' => 'slack', 'secret' => 'https://hooks.example/T0/B0/xyz'], $headers)
        ->assertStatus(201)->assertJsonPath('data.id', '17900000000003')->assertJsonPath('data.secret_set', true);
    expect($response->getContent())->not->toContain('hooks.example');
    $this->fake->assertRan('v-add-alert-channel', fn ($a) => $a === ['bob', 'Hook', 'slack', '', 'https://hooks.example/T0/B0/xyz']);
    $this->postJson('/api/v2/users/bob/alert-channels', ['name' => 'Chat', 'type' => 'telegram', 'target' => '1'], $headers)->assertStatus(422);

    $list = $this->getJson('/api/v2/users/bob/alert-channels', $headers)->assertOk();
    expect($list->getContent())->not->toContain('hooks.example');
    $list->assertJsonPath('data.1', ['id' => '17900000000002', 'name' => 'Ops chat', 'type' => 'telegram', 'target' => '-1001234', 'enabled' => true,
        'secret_set' => true, 'error' => 'HTTP 401: Unauthorized', 'failures' => 3, 'last_sent_at' => null]);

    $this->putJson('/api/v2/users/bob/alert-channels/17900000000002', ['name' => 'Ops', 'enabled' => false], $headers)->assertOk();
    $this->fake->assertRan('v-change-alert-channel', fn ($a) => $a === ['bob', '17900000000002', 'Ops', 'no']);
    $this->putJson('/api/v2/users/bob/alert-channels/17900000000002', ['name' => 'Ops', 'enabled' => true, 'secret' => ALERT_TOKEN], $headers)->assertOk();
    $this->fake->assertRan('v-change-alert-channel', fn ($a) => $a === ['bob', '17900000000002', 'Ops', 'yes', '-1001234', ALERT_TOKEN]);

    $this->postJson('/api/v2/users/bob/alert-channels/17900000000002/test', [], $headers)->assertOk()
        ->assertJsonPath('data', ['ok' => false, 'error' => 'HTTP 404']);
    $this->deleteJson('/api/v2/users/bob/alert-channels/17900000000002', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-alert-channel', fn ($a) => $a === ['bob', '17900000000002']);
    $this->deleteJson('/api/v2/users/bob/alert-channels/17900000000001', [], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/alert-channels/17999999999999', [], $headers)->assertNotFound();
})->with('alert keys');

it('plans a change without running it and refuses a v2-read key before any command', function () {
    $response = $this->postJson('/api/v2/users/bob/alert-channels?dry_run=1', ['name' => 'Chat', 'type' => 'telegram', 'target' => '1', 'secret' => ALERT_TOKEN],
        apiAs($this->fake, 'bob', 'user'))->assertOk()->assertJsonPath('data.steps.0.command', 'v-add-alert-channel');
    expect($response->getContent())->not->toContain(ALERT_TOKEN);
    $this->putJson('/api/v2/users/bob/alert-rules/ssl.expiring?dry_run=1', ['enabled' => false], apiAs($this->fake, 'bob', 'user'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-change-alert-rule');

    $read = apiAs($this->fake, 'bob', 'user', readOnly: true);
    $this->postJson('/api/v2/users/bob/alert-channels', ['name' => 'Mail', 'type' => 'email', 'target' => 'a@example.com'], $read)->assertForbidden()
        ->assertJsonPath('error.commands', ['v-add-alert-channel']);
    $this->putJson('/api/v2/users/bob/alert-rules/ssl.expiring', ['enabled' => false], $read)->assertForbidden();
    $this->postJson('/api/v2/users/bob/alert-channels/17900000000002/test', [], $read)->assertForbidden();
    $this->putJson('/api/v2/users/carol/alert-rules/ssl.expiring', ['enabled' => false], apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    foreach (['v-add-alert-channel', 'v-change-alert-rule', 'v-test-alert-channel'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('lists the history of a scope, newest first, with filters', function () {
    fakeAlertCommands($this->fake, [
        alertLine('fired', '17900000000701'),
        alertLine('event', '17900000000702', ['time' => '2026-11-06 10:30:00', 'scope' => 'bob', 'user' => 'bob', 'owner' => 'hostco',
            'rule' => 'backup.failed', 'object' => 'backup:bob', 'severity' => 'critical', 'value' => '']),
    ]);
    $this->getJson('/api/v2/system/alert-history', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', '17900000000701')->assertJsonPath('data.0.state', 'firing')
        ->assertJsonPath('data.0.deliveries.0.result', 'sent');
    $this->getJson('/api/v2/users/bob/alert-history?severity=critical', apiAs($this->fake, 'hostco', 'reseller'))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.scope', 'bob')->assertJsonPath('data.0.rule', 'backup.failed');
    $this->getJson('/api/v2/users/bob/alert-history?severity=warning', apiAs($this->fake, 'bob', 'user', area: 'alerts'))->assertOk()
        ->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v2/users/carol/alert-history', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->getJson('/api/v2/system/alert-history', apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
});
