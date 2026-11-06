<?php

use App\Arc\ExitCode;

// REST API v2, Doctor (E17 spec §6). hostco owns bob, resb owns carol and
// dave; arcadmin is the root administrator.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeDoctor($this->fake);
});

/** Main paths run with the default key and with a key that holds only the area's set (D30). */
dataset('doctor keys', [
    'default key' => [null],
    'v2-security key' => ['security'],
]);

it('lists the domains of an account in the key\'s reach', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/doctor", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200 && $owner === 'bob') {
        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0', ['user' => 'bob', 'domain' => 'bob.example', 'checked_at' => '2026-11-06T04:41:00',
                'score' => 80, 'fail' => 1, 'warn' => 1, 'failing' => ['web.ssl-aliases']]);
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

it('shows the checks of a domain with their fix', function (?string $area) {
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);
    $this->getJson('/api/v2/users/bob/doctor/bob.example', $headers)->assertOk()
        ->assertJsonPath('data.score', 80)
        ->assertJsonPath('data.checked_at', '2026-11-06T04:41:00')
        ->assertJsonPath('data.checks.0.id', 'web.ssl-aliases')
        ->assertJsonPath('data.checks.0.status', 'fail')
        ->assertJsonPath('data.checks.0.fix', ['label' => "Request a Let's Encrypt certificate", 'command' => 'v-add-letsencrypt-domain bob bob.example www.bob.example'])
        ->assertJsonPath('data.checks.1.link', 'dns');
    $this->getJson('/api/v2/users/bob/doctor/other.example', $headers)->assertNotFound();
    $this->getJson('/api/v2/users/carol/doctor/carol.example', $headers)->assertNotFound();
})->with('doctor keys');

it('runs the Doctor on a domain and answers 202 with the job', function (?string $area) {
    $this->fake->on('v-run-sys-doctor')->returns('17900000000200');
    fakeJobs($this->fake, [jobRecord('17900000000200', ['TYPE' => 'doctor.run', 'OBJECT' => 'doctor:bob:bob.example',
        'ARGS' => 'v-run-sys-doctor bob bob.example api:key job', 'STATUS' => 'running', 'EXIT' => '', 'END' => '', 'RESOURCE' => '/users/bob/doctor/bob.example'])]);
    $headers = apiAs($this->fake, 'bob', 'user', area: $area);

    $this->postJson('/api/v2/users/bob/doctor/bob.example/run', [], $headers)->assertStatus(202)
        ->assertHeader('Link', '</api/v2/tasks/job:17900000000200>; rel="monitor"')
        ->assertJsonPath('data.id', 'job:17900000000200')
        ->assertJsonPath('data.kind', 'doctor.run')
        ->assertJsonPath('data.status', 'running');
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a[0] === 'bob' && $a[1] === 'bob.example' && str_starts_with($a[2], 'api:'));
    $this->postJson('/api/v2/users/bob/doctor/run', [], $headers)->assertStatus(202);
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a[0] === 'bob' && $a[1] === '');

    $this->fake->on('v-run-sys-doctor')->returnsExit(ExitCode::InUse, 'Error: a Doctor run of bob.example is running (job 17900000000200)');
    $this->postJson('/api/v2/users/bob/doctor/bob.example/run', [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
})->with('doctor keys');

it('fixes a check and answers 409 when the check passes or has no fix', function () {
    $this->fake->on('v-fix-sys-doctor')->returns('17900000000201');
    fakeJobs($this->fake, [jobRecord('17900000000201', ['TYPE' => 'doctor.fix', 'OBJECT' => 'doctor:bob:bob.example:web.ssl-aliases',
        'ARGS' => 'v-fix-sys-doctor bob web.ssl-aliases bob.example bob', 'STATUS' => 'running', 'EXIT' => '', 'END' => ''])]);
    $headers = apiAs($this->fake, 'bob', 'user');

    $this->postJson('/api/v2/users/bob/doctor/bob.example/checks/web.ssl-aliases/fix', [], $headers)->assertStatus(202)
        ->assertJsonPath('data.id', 'job:17900000000201')->assertJsonPath('data.kind', 'doctor.fix');
    $this->fake->assertRan('v-fix-sys-doctor', fn ($a) => $a[0] === 'bob' && $a[1] === 'web.ssl-aliases' && $a[2] === 'bob.example');

    $this->fake->on('v-fix-sys-doctor')->returnsExit(ExitCode::Exists, 'Error: web.dns-a does not fail (ok); there is nothing to fix');
    $this->postJson('/api/v2/users/bob/doctor/bob.example/checks/web.dns-a/fix', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->postJson('/api/v2/users/bob/doctor/bob.example/checks/server.fail2ban/fix', [], $headers)->assertNotFound();
});

it('plans a run without running it and refuses a v2-read key before any command', function () {
    $this->postJson('/api/v2/users/bob/doctor/bob.example/run?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-run-sys-doctor');
    $this->postJson('/api/v2/users/bob/doctor/bob.example/checks/web.ssl-aliases/fix?dry_run=1', [], apiAs($this->fake, 'bob', 'user'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-fix-sys-doctor')
        ->assertJsonPath('data.steps.0.label', 'Fix web.ssl-aliases: v-add-letsencrypt-domain bob bob.example www.bob.example');

    $read = apiAs($this->fake, 'bob', 'user', readOnly: true);
    $this->postJson('/api/v2/users/bob/doctor/bob.example/run', [], $read)->assertForbidden()
        ->assertJsonPath('error.commands', ['v-run-sys-doctor']);
    $this->postJson('/api/v2/users/bob/doctor/bob.example/checks/web.ssl-aliases/fix', [], $read)->assertForbidden();
    $this->postJson('/api/v2/users/carol/doctor/carol.example/run', [], apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->fake->assertNotRan('v-run-sys-doctor');
    $this->fake->assertNotRan('v-fix-sys-doctor');
});

it('keeps the server checks to administrators', function (string $principal, string $role, int $status) {
    $this->fake->on('v-run-sys-doctor')->returns('17900000000202');
    $this->fake->on('v-fix-sys-doctor')->returns('17900000000203');
    fakeJobs($this->fake, [
        jobRecord('17900000000202', ['TYPE' => 'doctor.run', 'USER' => 'system', 'OBJECT' => 'doctor:system', 'STATUS' => 'queued', 'EXIT' => '', 'END' => '']),
        jobRecord('17900000000203', ['TYPE' => 'doctor.fix', 'USER' => 'system', 'OBJECT' => 'doctor:system:server.fail2ban', 'STATUS' => 'running', 'EXIT' => '', 'END' => '']),
    ]);
    $headers = apiAs($this->fake, $principal, $role);
    $this->getJson('/api/v2/system/doctor', $headers)->assertStatus($status);
    $this->postJson('/api/v2/system/doctor/run', ['all' => true], $headers)->assertStatus($status === 200 ? 202 : $status);
    $this->postJson('/api/v2/system/doctor/checks/server.fail2ban/fix', [], $headers)->assertStatus($status === 200 ? 202 : $status);
    if ($status === 200) {
        $this->getJson('/api/v2/system/doctor', $headers)->assertJsonPath('data.score', 75)
            ->assertJsonPath('data.checks.0.fix.command', 'v-restart-service fail2ban');
        $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a[0] === 'all');
        $this->fake->assertRan('v-fix-sys-doctor', fn ($a) => $a[0] === 'system' && $a[1] === 'server.fail2ban' && $a[2] === '');
    }
})->with([
    'admin' => ['arcadmin', 'admin', 200],
    'reseller' => ['hostco', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('runs the server checks with a key that holds only v2-server', function () {
    $this->fake->on('v-run-sys-doctor')->returns('17900000000204');
    fakeJobs($this->fake, [jobRecord('17900000000204', ['TYPE' => 'doctor.run', 'USER' => 'system', 'OBJECT' => 'doctor:system', 'STATUS' => 'queued', 'EXIT' => '', 'END' => ''])]);
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');
    $this->getJson('/api/v2/system/doctor', $headers)->assertOk();
    $this->postJson('/api/v2/system/doctor/run', [], $headers)->assertStatus(202);
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a[0] === 'system');
});
