<?php

use App\Arc\ExitCode;
use App\Audit\AuditWriter;

// REST API v2 tasks (E34 batch 3 spec §5, §8), since E15 the jobs of the Job
// Center (docs/features/specs/2026-11-05-001-e15-job-center-design.md §7).

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
});

function fakeTaskJobs($fake): void
{
    fakeJobs($fake, [
        jobRecord('17280000009001', ['TYPE' => 'sys.php', 'USER' => 'system', 'OWNER' => 'system', 'ACTOR' => 'arcadmin', 'OBJECT' => 'php',
            'ARGS' => 'v-run-sys-task php 8.2 -', 'CLASS' => 'immediate', 'RETRYABLE' => 'no', 'LOG' => "done\n"]),
        jobRecord('17280000008001', ['TYPE' => 'docker.engine', 'USER' => 'system', 'OWNER' => 'system', 'OBJECT' => 'docker:engine',
            'ARGS' => 'v-run-sys-docker-task', 'STATUS' => 'running', 'EXIT' => '', 'END' => '', 'CLASS' => 'immediate', 'RETRYABLE' => 'no', 'LOG' => 'pull']),
        jobRecord('17280000007001', ['TYPE' => 'node.install', 'OBJECT' => 'node:bob', 'ARGS' => 'v-run-user-node-task bob add-version lts',
            'CLASS' => 'immediate', 'RETRYABLE' => 'no', 'LOG' => "ok\n"]),
        jobRecord('17280000006001', ['TYPE' => 'quick-install', 'OBJECT' => 'web:example.com', 'ARGS' => 'v-run-user-quick-install bob example.com WordPress',
            'CLASS' => 'immediate', 'LOG' => "installed\n"]),
        jobRecord('17280000005001', ['TYPE' => 'docker.update', 'OBJECT' => 'docker:bob:kuma', 'ARGS' => 'v-run-docker-app-task bob kuma update 2.0.0',
            'STATUS' => 'failed', 'EXIT' => '1', 'ERROR' => 'pull failed', 'CLASS' => 'immediate', 'LOG' => "err\n"]),
        jobRecord('17280000004002', ['TYPE' => 'letsencrypt.domain', 'OBJECT' => 'web:shop.example',
            'ARGS' => 'v-add-letsencrypt-domain bob shop.example www.shop.example no ecdsa-256', 'STATUS' => 'queued', 'EXIT' => '',
            'START' => '', 'END' => '', 'QUEUED' => '2026-10-08 08:00:00', 'UPDATED' => '2026-10-08 08:00:00', 'CLASS' => 'letsencrypt']),
        jobRecord('17280000004001', ['TYPE' => 'backup.download', 'ARGS' => 'v-download-backup bob bob.2026-10-01.tar', 'LOG' => "backup ok\n"]),
        jobRecord('17280000003001', ['TYPE' => 'app-process.migrate', 'OBJECT' => 'app-process:bob:shop.example:web',
            'ARGS' => 'v-run-app-process-task bob shop.example web migrate ""', 'START' => '2026-10-07 08:00:00', 'END' => '2026-10-07 08:00:30',
            'CLASS' => 'immediate', 'RETRYABLE' => 'no']),
        jobRecord('17280000002001', ['TYPE' => 'backup.user', 'USER' => 'carol', 'OWNER' => 'resb', 'OBJECT' => 'user:carol',
            'ARGS' => 'v-backup-user carol yes', 'STATUS' => 'failed', 'EXIT' => '3', 'ERROR' => 'no space']),
    ]);
}

it('reads jobs by job:ID and by every older task id', function () {
    fakeTaskJobs($this->fake);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/tasks/job:17280000009001', $headers)->assertOk()
        ->assertJsonPath('data.id', 'job:17280000009001')
        ->assertJsonPath('data.kind', 'php')
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.resource', null)
        ->assertJsonPath('data.object', 'php')
        ->assertJsonPath('data.actor', 'arcadmin')
        ->assertJsonPath('data.log', 'done');
    $this->getJson('/api/v2/tasks/sys:17280000009001', $headers)->assertOk()->assertJsonPath('data.id', 'job:17280000009001');

    $this->getJson('/api/v2/tasks/docker-engine:17280000008001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'docker-engine')
        ->assertJsonPath('data.resource', '/system/docker')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.exit_code', null);

    $this->getJson('/api/v2/tasks/node:bob:17280000007001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'add-version')
        ->assertJsonPath('data.resource', '/users/bob/node');

    $this->getJson('/api/v2/tasks/install:bob:17280000006001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'quick-install')
        ->assertJsonPath('data.resource', '/users/bob/web-domains/example.com');

    $this->getJson('/api/v2/tasks/docker:bob:kuma:17280000005001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'update')
        ->assertJsonPath('data.error', 'pull failed')
        ->assertJsonPath('data.resource', '/users/bob/docker-apps/kuma');

    $this->getJson('/api/v2/tasks/queue:bob:17280000004001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'backup-download')
        ->assertJsonPath('data.queued_at', '2026-10-07T05:00:00')
        ->assertJsonPath('data.resource', '/users/bob/backups/bob.2026-10-01.tar');

    $this->getJson('/api/v2/tasks/app-process:bob:17280000003001', $headers)->assertOk()
        ->assertJsonPath('data.kind', 'app-process')
        ->assertJsonPath('data.started_at', '2026-10-07T08:00:00')
        ->assertJsonPath('data.resource', '/users/bob/app-processes/shop.example/web');
});

it('answers 404 for a job that is gone, an id of another source and a malformed id', function () {
    fakeTaskJobs($this->fake);
    $this->fake->on('v-list-job', ['17280000000000', '0', 'json'])->returnsExit(ExitCode::NotExist->value, 'Error: job 17280000000000 does not exist');
    $admin = apiAs($this->fake, 'arcadmin', 'admin');

    $this->getJson('/api/v2/tasks/job:17280000000000', $admin)->assertNotFound();
    $this->getJson('/api/v2/tasks/sys:17280000000000', $admin)->assertNotFound();
    // the 14 digits name a job of bob: not a system task, not a task of carol, not another app
    $this->getJson('/api/v2/tasks/sys:17280000004001', $admin)->assertNotFound();
    $this->getJson('/api/v2/tasks/queue:carol:17280000004001', $admin)->assertNotFound();
    $this->getJson('/api/v2/tasks/docker:bob:other:17280000005001', $admin)->assertNotFound();
    foreach (['queue:bob:abc', 'queue:bob:../x', 'queue:bob:3999', 'sys:1', 'job:1728000000700', 'not-a-source:bob:17280000004001'] as $malformed) {
        $this->getJson('/api/v2/tasks/'.$malformed, $admin)->assertNotFound();
    }
    $this->fake->assertRan('v-list-job', fn ($a) => ! preg_match('/^\d{14}$/', $a[0]), times: 0);
});

it('answers 500 when a job status cannot be mapped', function () {
    fakeJobs($this->fake, [jobRecord('17280000004001', ['STATUS' => 'weird'])]);

    $this->getJson('/api/v2/tasks/job:17280000004001', apiAs($this->fake, 'hostco', 'reseller'))->assertStatus(500)
        ->assertJsonPath('error.code', 'E_SERVER');
});

it('hides server jobs from non-administrators', function () {
    fakeTaskJobs($this->fake);
    $this->getJson('/api/v2/tasks/job:17280000009001', apiAs($this->fake, 'bob', 'user'))->assertNotFound();
    $this->getJson('/api/v2/tasks/sys:17280000009001', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->getJson('/api/v2/system/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
    $this->getJson('/api/v2/system/tasks', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', 'job:17280000009001');
});

it('lists the jobs of a user newest first and respects the tree', function () {
    fakeTaskJobs($this->fake);
    $this->getJson('/api/v2/users/bob/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertOk()
        ->assertJsonPath('data.0.id', 'job:17280000007001')
        ->assertJsonFragment(['id' => 'job:17280000004001', 'status' => 'succeeded'])
        ->assertJsonCount(6, 'data');
    $this->getJson('/api/v2/users/carol/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertNotFound();
});

it('lists the jobs a key can see with filters and pages', function () {
    fakeTaskJobs($this->fake);

    $all = $this->getJson('/api/v2/tasks', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('meta.total', 9)
        ->json('data.*.id');
    expect($all[0])->toBe('job:17280000009001');
    $this->getJson('/api/v2/tasks', apiAs($this->fake, 'hostco', 'reseller'))->assertOk()
        ->assertJsonPath('meta.total', 6);
    $this->getJson('/api/v2/tasks', apiAs($this->fake, 'resb', 'reseller'))->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', 'job:17280000002001');
    $this->getJson('/api/v2/tasks', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('meta.total', 6);

    $admin = apiAs($this->fake, 'arcadmin', 'admin');
    $this->getJson('/api/v2/tasks?status=failed', $admin)->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v2/tasks?type=docker.*', $admin)->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v2/tasks?type=sys.php&user=system', $admin)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v2/tasks?user=carol', $admin)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v2/tasks?since=2026-10-08', $admin)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v2/tasks?limit=2&offset=1', $admin)->assertOk()
        ->assertJsonPath('data.*.id', array_slice($all, 1, 2))
        ->assertJsonPath('meta', ['total' => 9, 'limit' => 2, 'offset' => 1]);
    $this->getJson('/api/v2/tasks?status=done', $admin)->assertUnprocessable();
    $this->getJson('/api/v2/tasks?type=../x', $admin)->assertUnprocessable();
});

it('lists user tasks with a key of every set that lists the task queue', function () {
    $sets = array_values(array_filter(
        [...apiAreaSets(), 'v2-read', 'v2-write'],
        fn (string $set) => in_array('v-list-user-tasks', apiSetCommands($set), true),
    ));
    expect($sets)->toContain('v2-web', 'v2-mail', 'v2-backup', 'v2-read');

    fakeTaskJobs($this->fake);
    foreach ($sets as $set) {
        expect(apiSetCommands($set))->toContain('v-list-job', 'v-list-jobs');
        $this->getJson('/api/v2/users/bob/tasks', apiKey($this->fake, 'hostco', 'reseller', [$set]))
            ->assertOk()->assertJsonCount(6, 'data');
    }
});

it('pages the user tasks with limit and offset', function () {
    fakeTaskJobs($this->fake);
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $all = $this->getJson('/api/v2/users/bob/tasks', $headers)->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonMissingPath('meta.limit')
        ->json('data.*.id');
    $this->getJson('/api/v2/users/bob/tasks?limit=2&offset=1', $headers)->assertOk()
        ->assertJsonPath('data.*.id', array_slice($all, 1, 2))
        ->assertJsonPath('meta', ['total' => 6, 'limit' => 2, 'offset' => 1]);
    $this->getJson('/api/v2/users/bob/tasks?limit=0', $headers)->assertUnprocessable();
});

it('lets admin, both resellers and users read tasks inside their tree only', function (string $principal, string $role, string $owner, int $status) {
    fakeTaskJobs($this->fake);

    $this->getJson("/api/v2/users/{$owner}/tasks", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);

    $taskId = $owner === 'carol' ? 'queue:carol:17280000002001' : 'job:17280000004001';
    $this->getJson('/api/v2/tasks/'.$taskId, apiAs($this->fake, $principal, $role, readOnly: true))
        ->assertStatus($status === 200 ? 200 : 404);
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'reseller B, a customer of A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('cancels a queued or running job with the actor and records the event', function () {
    fakeTaskJobs($this->fake);
    $this->fake->on('v-cancel-job')->returns('');
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/tasks/job:17280000004002/cancel', [], $headers)->assertStatus(202)
        ->assertJsonPath('data.id', 'job:17280000004002');
    $this->fake->assertRan('v-cancel-job', fn ($a) => $a[0] === '17280000004002' && str_starts_with($a[1], 'api:'));
    expect(app(AuditWriter::class)->events('job.cancel'))->toHaveCount(1);

    // a finished job is refused before any command runs
    $this->postJson('/api/v2/tasks/job:17280000004001/cancel', [], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_INVALID');
    $this->fake->assertRan('v-cancel-job', times: 1);
});

it('retries a failed retryable job as a new job with a monitor link', function () {
    fakeTaskJobs($this->fake);
    fakeJobs($this->fake, [
        jobRecord('17280000002001', ['TYPE' => 'backup.user', 'USER' => 'carol', 'OWNER' => 'resb', 'OBJECT' => 'user:carol',
            'ARGS' => 'v-backup-user carol yes', 'STATUS' => 'failed', 'EXIT' => '3', 'ERROR' => 'no space']),
        jobRecord('17280000002002', ['USER' => 'carol', 'OWNER' => 'resb', 'OBJECT' => 'user:carol', 'ARGS' => 'v-backup-user carol yes',
            'STATUS' => 'queued', 'EXIT' => '', 'START' => '', 'END' => '', 'RETRY_OF' => '17280000002001', 'ACTOR' => 'api:k1']),
    ]);
    $this->fake->on('v-retry-job')->returnsJson(['ID' => '17280000002002']);

    $this->postJson('/api/v2/tasks/job:17280000002001/retry', [], apiAs($this->fake, 'resb', 'reseller'))->assertStatus(202)
        ->assertJsonPath('data.id', 'job:17280000002002')
        ->assertJsonPath('data.retry_of', 'job:17280000002001')
        ->assertJsonPath('data.status', 'queued')
        ->assertHeader('Link', '</api/v2/tasks/job:17280000002002>; rel="monitor"');
    $this->fake->assertRan('v-retry-job', fn ($a) => $a[0] === '17280000002001' && $a[2] === 'json');
});

it('refuses a retry of a type that cannot be retried, or of a job that has not finished', function () {
    fakeTaskJobs($this->fake);
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/tasks/job:17280000007001/retry', [], $headers)->assertStatus(409);
    $this->postJson('/api/v2/tasks/job:17280000004002/retry', [], $headers)->assertStatus(409);
    $this->fake->assertRan('v-retry-job', times: 0);
});

it('keeps cancel and retry in the tree; a reseller or user cannot touch a server job', function (string $principal, string $role, string $job, int $status) {
    fakeTaskJobs($this->fake);
    $this->fake->on('v-cancel-job')->returns('');
    $this->postJson("/api/v2/tasks/job:{$job}/cancel", [], apiAs($this->fake, $principal, $role))->assertStatus($status);
})->with([
    'admin, a server job' => ['arcadmin', 'admin', '17280000008001', 202],
    'reseller A, a server job' => ['hostco', 'reseller', '17280000008001', 404],
    'reseller A, its customer' => ['hostco', 'reseller', '17280000004002', 202],
    'reseller B, a customer of A' => ['resb', 'reseller', '17280000004002', 404],
    'user, itself' => ['bob', 'user', '17280000004002', 202],
    'user carol, bob' => ['carol', 'user', '17280000004002', 404],
]);

it('refuses cancel to read-only keys before any command', function () {
    fakeTaskJobs($this->fake);
    $this->postJson('/api/v2/tasks/job:17280000004002/cancel', [], apiAs($this->fake, 'bob', 'user', readOnly: true))->assertForbidden();
    $this->fake->assertRan('v-cancel-job', times: 0);
});

it('answers 202 with job ids on Let’s Encrypt requests', function () {
    fakeWebDomains($this->fake);
    fakeMailDomains($this->fake);
    $this->fake->on('v-schedule-letsencrypt-domain')->returns("17290000001234\n");
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/users/bob/web-domains/shop.example/letsencrypt', [], $headers)
        ->assertStatus(202)->assertDocumentedHeaders('requestWebDomainLetsEncrypt')
        ->assertHeader('Location')
        ->assertHeader('Link', '</api/v2/tasks/job:17290000001234>; rel="monitor"');
});

it('lets a v2-read key and an area key follow tasks', function () {
    fakeTaskJobs($this->fake);
    $this->getJson('/api/v2/tasks/node:bob:17280000007001', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk();
    $this->getJson('/api/v2/tasks/job:17280000007001', apiAs($this->fake, 'bob', 'user', area: 'backup'))->assertOk();
});
