<?php

use App\Arc\ExitCode;
use App\Audit\AuditWriter;

// The Job Center pages (E15 spec §6.2, §6.3): the Jobs list, a Job with its
// log, the JSON state of the pollers, the tray, cancel and retry, per role.
// hostco owns bob, resb owns carol; arcadmin is the administrator.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    fakeJobs($this->fake, [
        jobRecord('17280000009001', ['TYPE' => 'sys.php', 'USER' => 'system', 'OWNER' => 'system', 'ACTOR' => 'arcadmin', 'OBJECT' => 'php',
            'ARGS' => 'v-run-sys-task php 8.2', 'CLASS' => 'immediate', 'RETRYABLE' => 'no', 'LOG' => "done\n"]),
        jobRecord('17280000007001', ['TYPE' => 'docker.install', 'OBJECT' => 'docker:bob:kuma', 'STATUS' => 'running', 'EXIT' => '', 'END' => '',
            'ARGS' => 'v-run-docker-app-task bob kuma install', 'CLASS' => 'immediate', 'RETRYABLE' => 'yes', 'LOG' => "pulling\n"]),
        jobRecord('17280000006001', ['TYPE' => 'backup.user', 'OBJECT' => 'user:bob', 'STATUS' => 'failed', 'EXIT' => '1', 'ERROR' => 'disk full',
            'LOG' => "Error: disk full\n"]),
        jobRecord('17280000005001', ['TYPE' => 'backup.user', 'USER' => 'carol', 'OWNER' => 'resb', 'OBJECT' => 'user:carol', 'ACTOR' => 'carol']),
    ]);
});

it('lists the jobs of each role in its scope', function (string $login, string $role, array $see, array $hidden) {
    signIn($login, $role);
    $response = $this->get('/jobs')->assertOk()->assertSee('Jobs');
    foreach ($see as $id) {
        $response->assertSee('data-job-id="'.$id.'"', false);
    }
    foreach ($hidden as $id) {
        $response->assertDontSee('data-job-id="'.$id.'"', false);
    }
})->with([
    'administrator' => ['arcadmin', 'admin', ['17280000009001', '17280000007001', '17280000006001', '17280000005001'], []],
    'reseller' => ['hostco', 'reseller', ['17280000007001', '17280000006001'], ['17280000009001', '17280000005001']],
    'user' => ['bob', 'user', ['17280000007001', '17280000006001'], ['17280000009001', '17280000005001']],
]);

it('filters the list by status and type', function () {
    signIn('arcadmin', 'admin');
    $this->get('/jobs?status=failed')->assertOk()
        ->assertSee('data-job-id="17280000006001"', false)->assertDontSee('data-job-id="17280000007001"', false);
    $this->get('/jobs?type=docker.*')->assertOk()
        ->assertSee('data-job-id="17280000007001"', false)->assertDontSee('data-job-id="17280000006001"', false);
    $this->get('/jobs?status=bogus')->assertRedirect();
});

it('shows a job with its log and answers 404 outside the scope', function () {
    signIn('hostco', 'reseller');
    $this->get('/jobs/17280000006001')->assertOk()->assertSee('disk full')->assertSee('apJob(', false)
        ->assertSee(route('jobs.retry', ['id' => '17280000006001']), false)
        ->assertSee('<dt>Arguments</dt>', false)->assertDontSee('<dt>Command</dt>', false);
    $this->get('/jobs/17280000009001')->assertNotFound();
    $this->get('/jobs/17280000005001')->assertNotFound();
    $this->get('/jobs/17280000009001/status')->assertNotFound();
    $this->post('/jobs/17280000009001/cancel')->assertNotFound();
});

it('answers the state of a job from an offset for the pollers', function () {
    $this->fake->on('v-list-job', ['17280000007001', '8', 'json'])->returnsJson(jobRecord('17280000007001', [
        'TYPE' => 'docker.install', 'OBJECT' => 'docker:bob:kuma', 'STATUS' => 'succeeded', 'LOG' => "done\n", 'OFFSET' => 13, 'SIZE' => 13,
    ]));
    signIn('bob', 'user');

    $this->getJson('/jobs/17280000007001/status')->assertOk()
        ->assertJsonPath('task.status', 'running')->assertJsonPath('task.log', "pulling\n")->assertJsonPath('task.offset', 8)
        ->assertJsonPath('task.finished', false)->assertJsonPath('task.kind', 'install');
    $this->getJson('/jobs/17280000007001/status?offset=8')->assertOk()
        ->assertJsonPath('task.status', 'succeeded')->assertJsonPath('task.log', "done\n")->assertJsonPath('task.offset', 13)
        ->assertJsonPath('task.finished', true);
    $this->getJson('/jobs/17280000009001/status')->assertNotFound();
});

it('cancels a running job and retries a failed one with the login as actor', function () {
    $this->fake->on('v-cancel-job')->returns('');
    $this->fake->on('v-retry-job')->returnsJson(['ID' => '17280000006002']);
    signIn('bob', 'user');

    $this->post('/jobs/17280000007001/cancel')->assertRedirect('/jobs/17280000007001')->assertSessionHas('success');
    $this->fake->assertRan('v-cancel-job', fn ($a) => $a === ['17280000007001', 'bob']);
    $this->post('/jobs/17280000006001/retry')->assertRedirect('/jobs/17280000006002')->assertSessionHas('success');
    $this->fake->assertRan('v-retry-job', fn ($a) => $a === ['17280000006001', 'bob', 'json']);
    expect(app(AuditWriter::class)->events('job.cancel'))->toHaveCount(1)
        ->and(app(AuditWriter::class)->events('job.retry'))->toHaveCount(1);
});

it('refuses to cancel a finished job, to retry a running one and reports a busy object', function () {
    $this->fake->on('v-retry-job')->returnsExit(ExitCode::InUse, 'Error: a job of user:bob is queued');
    signIn('bob', 'user');

    $this->post('/jobs/17280000006001/cancel')->assertRedirect('/jobs/17280000006001')->assertSessionHas('error');
    $this->post('/jobs/17280000007001/retry')->assertRedirect('/jobs/17280000007001')->assertSessionHas('error');
    $this->post('/jobs/17280000006001/retry')->assertRedirect('/jobs/17280000006001')
        ->assertSessionHas('error', 'Another job of the same object is queued or running.');
    $this->fake->assertNotRan('v-cancel-job');
});

it('answers the running jobs and the newest finished ones for the tray', function () {
    signIn('arcadmin', 'admin');
    $this->getJson('/jobs/tray')->assertOk()
        ->assertJsonPath('running', 1)
        ->assertJsonPath('items.0.id', '17280000007001')
        ->assertJsonCount(4, 'items');
    signIn('carol', 'user');
    $this->getJson('/jobs/tray')->assertOk()->assertJsonPath('running', 0)->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.url', route('jobs.show', ['id' => '17280000005001']));
});

it('rebuilds the job index for administrators only', function () {
    signIn('bob', 'user');
    $this->post('/jobs/rebuild')->assertForbidden();
    signIn('arcadmin', 'admin');
    $this->post('/jobs/rebuild')->assertRedirect('/jobs')->assertSessionHas('success');
});

it('puts Jobs in the sidebar of every role', function (string $login, string $role) {
    signIn($login, $role);
    $this->get('/jobs')->assertOk()->assertSee('href="'.route('jobs.index').'"', false);
})->with([['arcadmin', 'admin'], ['hostco', 'reseller'], ['bob', 'user']]);

it('lists a job started a moment after the tray read the jobs', function () {
    signIn('bob', 'user');
    $this->getJson('/jobs/tray')->assertOk();
    fakeJobs($this->fake, [jobRecord('17280000006009', ['TYPE' => 'backup.download', 'STATUS' => 'failed', 'EXIT' => '3'])]);
    $this->get('/jobs')->assertOk()->assertSee('data-job-id="17280000006009"', false);
});
