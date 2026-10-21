<?php

use App\Arc\ExitCode;

// REST API v2, the Update Center (E33 phase 1 spec §9): releases, pre-flight,
// policy, history, rollback and resume. Nothing here updates anything: every
// command is answered by the fake.

const UPDATE_CENTER_TASK = '17286400004321';

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    $this->fake->on('v-list-sys-update-policy')->returnsFixture('v-list-sys-update-policy/default.json');
    $this->fake->on('v-list-sys-arcpanel-releases')->returnsFixture('v-list-sys-arcpanel-releases/default.json');
    $this->fake->on('v-check-sys-arcpanel-update')->returnsFixture('v-check-sys-arcpanel-update/ready.json');
    $this->fake->on('v-list-sys-update-history')->returnsFixture('v-list-sys-update-history/default.json');
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-start-sys-task')->returns(UPDATE_CENTER_TASK."\n");
    foreach (['v-change-sys-update-policy', 'v-resume-sys-arcpanel-autoupdate', 'v-change-sys-config-value'] as $command) {
        $this->fake->on($command)->returns();
    }
});

it('reads the releases, pre-flight, policy and history for administrators only', function (string $principal, string $role, int $status) {
    $headers = apiAs($this->fake, $principal, $role, readOnly: true);

    $this->getJson('/api/v2/system/updates/releases', $headers)->assertStatus($status);
    $this->getJson('/api/v2/system/updates/releases/2.5.1/preflight', $headers)->assertStatus($status);
    $this->getJson('/api/v2/system/updates/policy', $headers)->assertStatus($status);
    $this->getJson('/api/v2/system/updates/history', $headers)->assertStatus($status);
    if ($status === 403) {
        foreach (['v-list-sys-arcpanel-releases', 'v-check-sys-arcpanel-update', 'v-list-sys-update-policy', 'v-list-sys-update-history'] as $command) {
            $this->fake->assertNotRan($command);
        }
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every write of the Update Center to non-administrators before any command', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->patchJson('/api/v2/system/updates/policy', ['policy' => 'off'], $headers)->assertForbidden()->assertJsonPath('error.code', 'E_FORBIDDEN');
    $this->postJson('/api/v2/system/updates/resume', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/updates/history/3/rollback', [], $headers)->assertForbidden();
    $this->postJson('/api/v2/system/updates', ['version' => '2.5.1'], $headers)->assertForbidden();
    foreach (['v-change-sys-update-policy', 'v-resume-sys-arcpanel-autoupdate', 'v-start-sys-task', 'v-list-sys-arcpanel-releases'] as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

// Releases and pre-flight

it('lists the releases of the manifest with a key holding only v2-server', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/updates/releases', $headers)->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', [
            'version' => '2.6.0', 'line' => '2.6', 'date' => '2026-11-01', 'kind' => 'minor', 'security' => false, 'attention' => true,
            'attention_items' => ['PHP 8.2 is no longer installed by default'], 'steps' => true, 'min_from' => '2.5.0',
            'engines' => ['arcpanel-nginx' => '1.30.5-3-1', 'arcpanel-php' => '8.5.11-1-1'], 'notes' => 'https://arcpanel.top/changelog#2.6.0',
            'withdrawn' => null, 'available' => true, 'eligible' => false, 'reason' => 'attention',
        ])
        ->assertJsonPath('data.1.withdrawn', ['date' => '2026-10-31', 'reason' => "breaks the panel's NGINX configuration"])
        ->assertJsonPath('data.2.eligible', true)->assertJsonPath('data.2.reason', null)->assertJsonPath('data.2.notes', null)
        ->assertJsonPath('data.2.security', true)->assertJsonPath('meta', ['total' => 3]);
    $this->fake->assertRan('v-list-sys-arcpanel-releases', fn ($a) => $a === ['json']);

    $this->getJson('/api/v2/system/updates/releases?refresh=true', $headers)->assertOk();
    $this->fake->assertRan('v-list-sys-arcpanel-releases', fn ($a) => $a === ['json', 'yes'], 1);
    $this->getJson('/api/v2/system/updates/releases?refresh=maybe', $headers)->assertStatus(422);

    // A read-only key reads the cached manifest: no apt-get update for it (E39 W5 N33)
    foreach (['read', 'observe'] as $area) {
        $this->getJson('/api/v2/system/updates/releases?refresh=true', apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertOk();
    }
    $this->fake->assertRan('v-list-sys-arcpanel-releases', fn ($a) => $a === ['json', 'yes'], 1);
});

it('answers 404 with the reason when no manifest could be verified', function () {
    $this->fake->on('v-list-sys-arcpanel-releases')->returnsExit(ExitCode::NotExist, 'Error: the manifest https://apt.arcpanel.top/releases.json has a bad signature');

    $this->getJson('/api/v2/system/updates/releases', apiAs($this->fake, 'arcadmin', 'admin'))->assertNotFound()
        ->assertJsonPath('error.code', 'E_NOTEXIST')
        ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'bad signature'));
});

it('runs the pre-flight of a release and lists its checks, also when one fails', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/updates/releases/2.5.1/preflight', $headers)->assertOk()
        ->assertJsonPath('data.version', '2.5.1')
        ->assertJsonPath('data.ready', true)
        ->assertJsonCount(7, 'data.checks')
        ->assertJsonPath('data.checks.0', ['check' => 'manifest', 'status' => 'ok', 'message' => 'ArcPanel 2.5.1 is in the release manifest']);
    $this->fake->assertRan('v-check-sys-arcpanel-update', fn ($a) => $a === ['2.5.1', 'json', 'manual']);

    $this->fake->on('v-check-sys-arcpanel-update')->returnsExit(ExitCode::Forbidden, json_encode(commandFixture('v-check-sys-arcpanel-update/refused.json')));
    $this->getJson('/api/v2/system/updates/releases/2.6.0/preflight', $headers)->assertOk()
        ->assertJsonPath('data.ready', false)
        ->assertJsonPath('data.checks.2', ['check' => 'disk', 'status' => 'fail', 'message' => 'less than 1024 MiB free for /var/cache/apt'])
        ->assertJsonPath('data.checks.5.status', 'warn');

    $this->getJson('/api/v2/system/updates/releases/2.5.0~beta.1/preflight', $headers)->assertOk();
    $this->fake->assertRan('v-check-sys-arcpanel-update', fn ($a) => $a[0] === '2.5.0~beta.1');
    $this->getJson('/api/v2/system/updates/releases/2.5/preflight', $headers)->assertNotFound();
    $this->getJson('/api/v2/system/updates/releases/2.5.1;id/preflight', $headers)->assertNotFound();

    $this->fake->on('v-check-sys-arcpanel-update')->returnsExit(ExitCode::Invalid, 'Error: invalid version 2.5.1');
    $this->getJson('/api/v2/system/updates/releases/2.5.1/preflight', $headers)->assertStatus(422)->assertJsonPath('error.code', 'E_INVALID');
});

// Update to a release

it('starts the update to a release as the system task with the key as actor', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/updates', ['version' => '2.5.1'], $headers)->assertStatus(202)->assertDocumentedHeaders('runUpdates')
        ->assertHeader('Link', '</api/v2/tasks/sys:'.UPDATE_CENTER_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.id', 'sys:'.UPDATE_CENTER_TASK)
        ->assertJsonPath('data.kind', 'arcpanel-release')
        ->assertJsonPath('data.args', '2.5.1 api '.API_KEY_ID);
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-release', '2.5.1', 'api', API_KEY_ID]);

    $this->postJson('/api/v2/system/updates', ['version' => '2.6.0', 'confirm_attention' => true], $headers)->assertStatus(202);
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-release', '2.6.0', 'api', API_KEY_ID]);
    $this->fake->assertRan('v-start-sys-task', null, 2);
});

it('refuses an update to a release that is unknown, withdrawn or not confirmed', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/updates', ['version' => '2.6.0'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.confirm_attention.0', fn ($m) => str_contains($m, 'needs attention'));
    $this->postJson('/api/v2/system/updates', ['version' => '2.6.0', 'confirm_attention' => false], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/updates', ['version' => '2.5.2'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.version.0', 'ArcPanel 2.5.2 was withdrawn.');
    $this->postJson('/api/v2/system/updates', ['version' => '2.4.9'], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields.version.0', 'ArcPanel 2.4.9 is not a release newer than the installed version.');
    $this->postJson('/api/v2/system/updates', ['version' => '2.5.1;id'], $headers)->assertStatus(422)->assertJsonPath('error.fields.version.0', fn ($m) => is_string($m));
    $this->postJson('/api/v2/system/updates', ['version' => '2.5.1', 'package' => 'arcpanel'], $headers)->assertStatus(422);
    $this->fake->assertNotRan('v-start-sys-task');

    $this->fake->on('v-list-sys-arcpanel-releases')->returnsExit(ExitCode::NotExist, 'Error: no release manifest');
    $this->postJson('/api/v2/system/updates', ['version' => '2.5.1'], $headers)->assertNotFound();
    $this->postJson('/api/v2/system/updates', [], $headers)->assertStatus(202);
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-update'], 1);
});

// Policy

it('shows the update policy with the installed version and the manifest', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/updates/policy', $headers)->assertOk()
        ->assertExactJson(['data' => [
            'policy' => 'patch', 'window_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'window_hours' => '02-04', 'delay_days' => 2,
            'paused' => false, 'paused_reason' => null, 'next_window' => '2026-11-03T02:00:00',
            'installed' => ['version' => '2.5.0', 'line' => '2.5'],
            'manifest' => ['channel' => 'stable', 'checked_at' => '2026-11-02T14:03:22', 'generated_at' => '2026-11-02T10:12:00Z', 'error' => null],
        ]]);

    $this->fake->on('v-list-sys-update-policy')->returnsFixture('v-list-sys-update-policy/paused.json');
    $this->getJson('/api/v2/system/updates/policy', $headers)->assertOk()
        ->assertJsonPath('data.window_days', ['sat', 'sun'])
        ->assertJsonPath('data.paused', true)
        ->assertJsonPath('data.paused_reason', 'ArcPanel 2.5.2 failed (failed): apt-get failed with exit code 100')
        ->assertJsonPath('data.next_window', null)
        ->assertJsonPath('data.manifest.checked_at', null)
        ->assertJsonPath('data.manifest.error', fn ($m) => str_contains($m, 'could not be downloaded'));
});

it('changes the policy with the values left out kept, and runs nothing when nothing changes', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->patchJson('/api/v2/system/updates/policy', ['window_days' => ['sun', 'sat'], 'delay_days' => 5], $headers)->assertOk()
        ->assertJsonPath('data.policy', 'patch');
    $this->fake->assertRan('v-change-sys-update-policy', fn ($a) => $a === ['patch', 'sat,sun', '02-04', '5'], 1);

    $this->patchJson('/api/v2/system/updates/policy', ['policy' => 'off', 'window_hours' => '23-02'], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-update-policy', fn ($a) => $a === ['off', 'all', '23-02', '2'], 1);

    $this->patchJson('/api/v2/system/updates/policy', ['policy' => 'patch', 'window_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'window_hours' => '02-04'], $headers)->assertOk();
    $this->patchJson('/api/v2/system/updates/policy', [], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-update-policy', null, 2);
});

it('validates the policy fields', function (array $body, string $field) {
    $this->patchJson('/api/v2/system/updates/policy', $body, apiAs($this->fake, 'arcadmin', 'admin'))->assertStatus(422)
        // A field of an array item is named with a dot (window_days.0), so it is no JSON path
        ->assertJsonPath('error.fields', fn ($fields) => is_string($fields[$field][0] ?? null));
    $this->fake->assertNotRan('v-change-sys-update-policy');
})->with([
    'policy' => [['policy' => 'always'], 'policy'],
    'no days' => [['window_days' => []], 'window_days'],
    'a day' => [['window_days' => ['monday']], 'window_days.0'],
    'a day twice' => [['window_days' => ['mon', 'mon']], 'window_days.0'],
    'hours' => [['window_hours' => '3-5'], 'window_hours'],
    'hours past the day' => [['window_hours' => '02-25'], 'window_hours'],
    'an empty window' => [['window_hours' => '04-04'], 'window_hours'],
    'a line break' => [['window_hours' => "02-04\n"], 'window_hours'],
    'delay' => [['delay_days' => 31], 'delay_days'],
    'a negative delay' => [['delay_days' => -1], 'delay_days'],
]);

it('accepts the whole day as a window', function () {
    $this->patchJson('/api/v2/system/updates/policy', ['window_hours' => '00-24'], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk();
    $this->fake->assertRan('v-change-sys-update-policy', fn ($a) => $a[2] === '00-24', 1);
});

it('resumes the automatic updates', function () {
    $this->postJson('/api/v2/system/updates/resume', [], apiAs($this->fake, 'arcadmin', 'admin', area: 'server'))->assertOk()
        ->assertJsonPath('data.paused', false);
    $this->fake->assertRan('v-resume-sys-arcpanel-autoupdate', fn ($a) => $a === [], 1);
});

// History and rollback

it('lists the update history newest first, paginated', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/updates/history', $headers)->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', [
            'id' => 3, 'from' => '2.5.0', 'to' => '2.5.1', 'trigger' => 'policy', 'actor' => 'policy',
            'started_at' => '2026-11-03T02:14:05', 'ended_at' => '2026-11-03T02:16:40', 'result' => 'done', 'reason' => null,
            'task' => 'sys:17925168372496', 'rollback' => true, 'rollback_reason' => null,
        ])
        ->assertJsonPath('data.1.task', null)->assertJsonPath('data.1.rollback', false)->assertJsonPath('data.1.reason', 'activity: a backup is running')
        ->assertJsonPath('data.2.ended_at', null)->assertJsonPath('data.2.trigger', 'api')
        ->assertJsonPath('meta', ['total' => 3]);
    $this->getJson('/api/v2/system/updates/history?limit=1&offset=2', $headers)->assertOk()
        ->assertJsonPath('data.0.id', 1)->assertJsonPath('meta', ['total' => 3, 'limit' => 1, 'offset' => 2]);
});

it('starts the rollback of an update as the system task', function () {
    $this->postJson('/api/v2/system/updates/history/3/rollback', [], apiAs($this->fake, 'arcadmin', 'admin', area: 'server'))
        ->assertStatus(202)->assertDocumentedHeaders('rollbackPanelUpdate')
        ->assertHeader('Link', '</api/v2/tasks/sys:'.UPDATE_CENTER_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.kind', 'arcpanel-rollback')
        ->assertJsonPath('data.args', '3');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['arcpanel-rollback', '3'], 1);
});

it('answers 409 with the failed condition when the rollback is not allowed', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::Forbidden, 'Error: the rollback is not allowed: ArcPanel 2.5.1 has upgrade steps');
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/system/updates/history/3/rollback', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_FORBIDDEN')
        ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'ArcPanel 2.5.1 has upgrade steps'));
    $this->postJson('/api/v2/system/updates/history/x1/rollback', [], $headers)->assertNotFound();

    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    $this->postJson('/api/v2/system/updates/history/3/rollback', [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
});

// Dry run and keys

it('plans the writes in a dry run without running them', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->patchJson('/api/v2/system/updates/policy?dry_run=1', ['policy' => 'notify'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Change the update policy', 'command' => 'v-change-sys-update-policy', 'args' => ['notify', 'all', '02-04', '2']]);
    $this->postJson('/api/v2/system/updates/resume?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-resume-sys-arcpanel-autoupdate');
    $this->postJson('/api/v2/system/updates/history/3/rollback?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0', ['label' => 'Roll back update run 3', 'command' => 'v-start-sys-task', 'args' => ['arcpanel-rollback', '3']]);
    $this->postJson('/api/v2/system/updates?dry_run=1', ['version' => '2.5.1'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['arcpanel-release', '2.5.1', 'api', API_KEY_ID]);
    $this->fake->assertNotRan('v-change-sys-update-policy');
    $this->fake->assertNotRan('v-resume-sys-arcpanel-autoupdate');
    $this->fake->assertNotRan('v-start-sys-task');
});

it('reads with a v2-read key and refuses its writes before any command', function () {
    $headers = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);

    $this->getJson('/api/v2/system/updates/releases', $headers)->assertOk();
    $this->getJson('/api/v2/system/updates/releases/2.5.1/preflight', $headers)->assertOk();
    $this->getJson('/api/v2/system/updates/policy', $headers)->assertOk();
    $this->getJson('/api/v2/system/updates/history', $headers)->assertOk();

    $this->patchJson('/api/v2/system/updates/policy', ['policy' => 'off'], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-change-sys-update-policy']);
    $this->postJson('/api/v2/system/updates/resume', [], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-resume-sys-arcpanel-autoupdate']);
    $this->postJson('/api/v2/system/updates/history/3/rollback', [], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-start-sys-task']);
    $this->postJson('/api/v2/system/updates', ['version' => '2.5.1'], $headers)
        ->assertForbidden()->assertJsonPath('error.commands', ['v-start-sys-task']);
    $this->fake->assertNotRan('v-change-sys-update-policy');
    $this->fake->assertNotRan('v-resume-sys-arcpanel-autoupdate');
    $this->fake->assertNotRan('v-start-sys-task');
});
