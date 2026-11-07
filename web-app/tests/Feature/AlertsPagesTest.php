<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// The Alerts pages (E16 spec §7). hostco owns bob, resb owns carol and dave;
// arcadmin is the root administrator. Channel secrets never come back.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeAlertCommands($this->fake);
});

it('shows an account its open alerts, rules and channels, never a secret', function () {
    signIn('bob', 'user');

    $this->get('/alerts')->assertRedirect('/alerts/bob');
    $this->get('/alerts/bob')->assertOk()
        ->assertSee('web:bob.example')->assertSee('9 days (warning 14 days, critical 3 days)')
        ->assertSee('Certificate expiring')->assertSee('warning 14 days, critical 3 days')
        ->assertSee('Ops chat')->assertSee('HTTP 401: Unauthorized')->assertSee('Failing')
        ->assertSee('Send test')->assertSee('Add Channel')
        ->assertDontSee('mount:/home')->assertDontSee(ALERT_TOKEN);
    $this->fake->assertRan('v-list-alerts', fn ($a) => $a === ['bob', 'json']);
    $this->get('/alerts/carol')->assertNotFound();
});

it('lets resellers and administrators see the scopes of their customers', function () {
    signIn('hostco', 'reseller');
    $this->get('/alerts/bob')->assertOk()->assertSee('Ops chat');
    $this->get('/alerts/carol')->assertNotFound();
    $this->get('/server/alerts')->assertForbidden();

    signIn('arcadmin', 'admin');
    $this->get('/alerts/carol')->assertOk();
    $this->get('/server/alerts')->assertOk()->assertSee('Server alerts')->assertSee('mount:/home')
        ->assertSee('96% (warning 90%, critical 95%)')->assertSee('Every administrator')
        ->assertSee('<td data-col="cooldown" data-label="Cooldown">1 day</td>', false)->assertDontSee('1440 min');
    $this->fake->assertRan('v-list-alert-rules', fn ($a) => $a === ['system', 'json']);

    signIn('bob', 'user');
    $this->get('/server/alerts')->assertForbidden();
});

it('changes a rule with all of its values', function () {
    $this->fake->on('v-change-alert-rule')->returns();
    signIn('arcadmin', 'admin');

    $this->put('/server/alerts/rules/disk.usage', ['enabled' => '1', 'warning' => '85', 'critical' => '95', 'for' => '5', 'cooldown' => '60',
        'channels' => ['17900000000001', '17900000000002'], 'options' => '/,/home'])
        ->assertRedirect('/server/alerts?tab=rules#rules')->assertSessionHas('ok', 'Rule Disk usage has been saved.');
    $this->fake->assertRan('v-change-alert-rule', fn ($a) => $a === ['system', 'disk.usage', 'yes', '85', '95', '5', '', '60', '17900000000001,17900000000002', '/,/home']);

    // Off, without channels
    $this->put('/server/alerts/rules/disk.usage', ['cooldown' => '1440'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-alert-rule', fn ($a) => $a === ['system', 'disk.usage', 'no', '', '', '0', '', '1440', '', '']);

    $this->put('/server/alerts/rules/disk.usage', ['cooldown' => '0'])->assertSessionHasErrors(['cooldown']);
    $this->put('/server/alerts/rules/disk.usage', ['cooldown' => '60', 'warning' => '9x'])->assertSessionHasErrors(['warning']);
    $this->put('/server/alerts/rules/disk.usage', ['cooldown' => '60', 'options' => 'a;b'])->assertSessionHasErrors(['options']);
    $this->put('/server/alerts/rules/disk.usage', ['cooldown' => '60', 'channels' => ['x']])->assertSessionHasErrors(['channels.0']);
    $this->put('/server/alerts/rules/nope.rule', ['cooldown' => '60'])->assertNotFound();

    $this->fake->on('v-change-alert-rule')->returnsExit(ExitCode::Invalid, 'Error: the critical threshold must not be below the warning threshold');
    $this->put('/server/alerts/rules/disk.usage', ['enabled' => '1', 'warning' => '95', 'critical' => '90', 'cooldown' => '60'])
        ->assertSessionHas('error', 'the critical threshold must not be below the warning threshold');
});

it('adds a channel with its secret in a file and never keeps the secret', function () {
    $this->fake->on('v-add-alert-channel')->returns('17900000000003');
    signIn('bob', 'user');

    $this->post('/alerts/bob/channels', ['name' => 'My chat', 'type' => 'telegram', 'target' => '4242', 'secret' => ALERT_TOKEN])
        ->assertRedirect('/alerts/bob?tab=channels#channels')->assertSessionHas('ok', 'Channel My chat has been added.');
    $this->fake->assertRan('v-add-alert-channel', fn ($a) => $a === ['bob', 'My chat', 'telegram', '4242', ALERT_TOKEN]);
    expect(json_encode(session()->all()))->not->toContain(ALERT_TOKEN);

    $this->post('/alerts/bob/channels', ['name' => 'Mail', 'type' => 'email', 'target' => 'ops@example.com', 'secret' => 'ignored'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-alert-channel', fn ($a) => $a === ['bob', 'Mail', 'email', 'ops@example.com']);
    $this->post('/alerts/bob/channels', ['name' => 'Hook', 'type' => 'slack', 'target' => 'x', 'secret' => 'https://hooks.example/x'])->assertSessionHas('ok');
    $this->fake->assertRan('v-add-alert-channel', fn ($a) => $a === ['bob', 'Hook', 'slack', '', 'https://hooks.example/x']);

    $this->post('/alerts/bob/channels', ['name' => 'No token', 'type' => 'telegram', 'target' => '1'])->assertSessionHasErrors(['secret']);
    $this->post('/alerts/bob/channels', ['name' => 'x', 'type' => 'sms'])->assertSessionHasErrors(['type']);
    $this->post('/alerts/bob/channels', ['name' => "it's", 'type' => 'email', 'target' => 'a@example.com'])->assertSessionHasErrors(['name']);

    $this->fake->on('v-add-alert-channel')->returnsExit(ExitCode::Invalid, 'Error: the Telegram bot token is not valid (digits, a colon and the key)');
    $this->post('/alerts/bob/channels', ['name' => 'Bad', 'type' => 'telegram', 'target' => '1', 'secret' => 'nope-'.ALERT_TOKEN])
        ->assertSessionHas('error', 'the Telegram bot token is not valid (digits, a colon and the key)');
    expect(json_encode(session()->all()))->not->toContain(ALERT_TOKEN);
});

it('changes, tests and deletes a channel; an empty secret keeps the stored one', function () {
    $this->fake->on('v-change-alert-channel')->returns();
    $this->fake->on('v-delete-alert-channel')->returns();
    $this->fake->on('v-test-alert-channel')->returnsJson(['RESULT' => 'ok', 'ERROR' => '']);
    signIn('bob', 'user');

    $this->put('/alerts/bob/channels/17900000000002', ['name' => 'Ops chat', 'enabled' => '1', 'target' => '-1009', 'secret' => ''])
        ->assertSessionHas('ok', 'Channel Ops chat has been saved.');
    $this->fake->assertRan('v-change-alert-channel', fn ($a) => $a === ['bob', '17900000000002', 'Ops chat', 'yes', '-1009']);
    $this->put('/alerts/bob/channels/17900000000002', ['name' => 'Ops chat', 'target' => '-1009', 'secret' => ALERT_TOKEN])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-alert-channel', fn ($a) => $a === ['bob', '17900000000002', 'Ops chat', 'no', '-1009', ALERT_TOKEN]);
    $this->put('/alerts/bob/channels/17900000000001', ['name' => 'Panel', 'enabled' => '1', 'target' => 'x'])->assertSessionHas('ok');
    $this->fake->assertRan('v-change-alert-channel', fn ($a) => $a === ['bob', '17900000000001', 'Panel', 'yes']);

    $this->post('/alerts/bob/channels/17900000000002/test')->assertSessionHas('ok', 'A test message went to Ops chat.');
    $this->fake->on('v-test-alert-channel')->using(fn () => [ExitCode::Connect->value, (string) json_encode(['RESULT' => 'failed', 'ERROR' => 'HTTP 401: Unauthorized'])]);
    $this->post('/alerts/bob/channels/17900000000002/test')->assertSessionHas('error', 'The test message to Ops chat failed: HTTP 401: Unauthorized');

    $this->delete('/alerts/bob/channels/17900000000002')->assertSessionHas('ok', 'Channel Ops chat has been deleted.');
    $this->fake->assertRan('v-delete-alert-channel', fn ($a) => $a === ['bob', '17900000000002']);
    $this->delete('/alerts/bob/channels/17999999999999')->assertNotFound();
});

it('refuses changes outside the tree, to the server scope and in read-only sessions', function () {
    signIn('hostco', 'reseller');
    $this->post('/alerts/carol/channels', ['name' => 'x', 'type' => 'email', 'target' => 'a@example.com'])->assertNotFound();
    $this->put('/server/alerts/rules/disk.usage', ['cooldown' => '60'])->assertForbidden();

    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');
    $this->get('/alerts/bob')->assertOk()->assertDontSee('Add Channel');
    $this->put('/alerts/bob/rules/ssl.expiring', ['cooldown' => '60'])->assertForbidden();
    $this->post('/alerts/bob/channels/17900000000002/test')->assertForbidden();
    foreach (['v-change-alert-rule', 'v-add-alert-channel', 'v-test-alert-channel'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

it('shows the history of the scope with its deliveries and filters', function () {
    fakeAlertCommands($this->fake, [
        alertLine('fired', '17900000000201'),
        alertLine('resolved', '17900000000201', ['time' => '2026-11-06 11:00:00', 'deliveries' => []]),
        alertLine('event', '17900000000202', ['scope' => 'bob', 'user' => 'bob', 'owner' => 'hostco', 'rule' => 'backup.failed', 'object' => 'backup:bob',
            'severity' => 'critical', 'value' => '', 'message' => '[CRITICAL] Backup failed: backup:bob on host',
            'deliveries' => [['channel' => '17900000000002', 'name' => 'Ops chat', 'type' => 'telegram', 'result' => 'failed', 'error' => 'HTTP 401: Unauthorized']]]),
    ]);
    $this->travelTo('2026-11-06 12:00:00');
    signIn('bob', 'user');

    $this->get('/alerts/bob?tab=history')->assertOk()->assertSee('backup:bob')->assertSee('HTTP 401: Unauthorized')
        ->assertDontSee('[WARNING] Disk usage');
    expect(DB::table('alerts_history')->count())->toBe(2);
    $this->get('/alerts/bob?tab=history&severity=warning')->assertOk()->assertDontSee('backup:bob');

    signIn('arcadmin', 'admin');
    $this->get('/server/alerts?tab=history&rule=disk.usage')->assertOk()->assertSee('[WARNING] Disk usage: mount:/home on host')
        ->assertSee('2026-11-06 11:00:00')->assertDontSee('backup:bob');
});

it('has a capability for every alert route and navigation entries', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'alerts.') || str_starts_with($name, 'server.alerts'));

    expect($routes)->toContain('alerts.index', 'alerts.show', 'alerts.rules.update', 'alerts.channels.store', 'alerts.channels.update',
        'alerts.channels.destroy', 'alerts.channels.test', 'server.alerts', 'server.alerts.rules.update', 'server.alerts.channels.store');
    expect(Capabilities::names())->toContain('alerts.view', 'alerts.manage');

    signIn('bob', 'user');
    $this->get('/alerts/bob')->assertSee('href="'.url('/alerts').'"', false)->assertDontSee('href="'.url('/server/alerts').'"', false);
    signIn('arcadmin', 'admin');
    $this->get('/alerts/arcadmin')->assertSee('href="'.url('/server/alerts').'"', false);
});
