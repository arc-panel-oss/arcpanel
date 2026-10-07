<?php

use App\Api\CronSchedule;
use Illuminate\Validation\ValidationException;

// REST API v2, cron (E34 batch 2 spec §6.5). bob has job 1 and the suspended
// job 2; carol has job 1.

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeCronJobs($this->fake);
});

it('lists the cron jobs of a user inside the tree and answers 404 outside it', function (string $principal, string $role, string $owner, int $status) {
    $response = $this->getJson("/api/v2/users/{$owner}/cron-jobs", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('meta.total', count($response->json('data')))
            ->assertJsonPath('meta.timezone', 'UTC');
    }
})->with([
    'admin, bob' => ['arcadmin', 'admin', 'bob', 200],
    'admin, an unknown user' => ['arcadmin', 'admin', 'ghost', 404],
    'reseller A, its customer' => ['hostco', 'reseller', 'bob', 200],
    'reseller A, a customer of reseller B' => ['hostco', 'reseller', 'carol', 404],
    'reseller B, its customer' => ['resb', 'reseller', 'carol', 200],
    'reseller B, a customer of reseller A' => ['resb', 'reseller', 'bob', 404],
    'user, itself' => ['bob', 'user', 'bob', 200],
    'user, another user' => ['bob', 'user', 'carol', 404],
]);

it('describes a job as one schedule string', function () {
    $this->getJson('/api/v2/users/bob/cron-jobs/1', apiAs($this->fake, 'bob', 'user', readOnly: true))->assertOk()
        ->assertJsonPath('data.id', 1)
        ->assertJsonPath('data.schedule', '0 * * * *')
        ->assertJsonPath('data.command', 'php /home/bob/web/example.com/public_html/cron.php')
        ->assertJsonPath('data.suspended', false);
});

it('creates a job with the next id using a key that holds only v2-cron', function () {
    $this->fake->on('v-add-cron-job')->returns();
    $this->fake->on('v-list-cron-job', ['bob', '3', 'json'])->returnsJson(['3' => [
        'JOB' => '3', 'MIN' => '*/5', 'HOUR' => '*', 'DAY' => '*', 'MONTH' => '*', 'WDAY' => '*',
        'CMD' => 'echo hi', 'SUSPENDED' => 'no', 'DATE' => '2026-10-06', 'TIME' => '12:00:00',
    ]]);

    $this->postJson('/api/v2/users/bob/cron-jobs', [
        'schedule' => '*/5 * * * *', 'command' => 'echo hi',
    ], apiAs($this->fake, 'bob', 'user', area: 'cron'))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/users/bob/cron-jobs/3')
        ->assertJsonPath('data.id', 3);
    $this->fake->assertRan('v-add-cron-job', fn ($a) => $a === ['bob', '*/5', '*', '*', '*', '*', 'echo hi', '3']);
});

it('points a duplicate job at the existing one and refuses a macro', function () {
    $headers = apiAs($this->fake, 'bob', 'user', area: 'cron');

    $this->postJson('/api/v2/users/bob/cron-jobs', [
        'schedule' => '0 * * * *', 'command' => 'php /home/bob/web/example.com/public_html/cron.php',
    ], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS')
        ->assertJsonPath('error.existing', '/api/v2/users/bob/cron-jobs/1');
    $this->postJson('/api/v2/users/bob/cron-jobs', ['schedule' => '@daily', 'command' => 'echo hi'], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['schedule']]]);
    $this->fake->assertNotRan('v-add-cron-job');
});

it('plans a create without running it and refuses a v2-read key', function () {
    $this->postJson('/api/v2/users/bob/cron-jobs?dry_run=1', [
        'schedule' => '*/5 * * * *', 'command' => 'echo hi',
    ], apiAs($this->fake, 'bob', 'user', area: 'cron'))
        ->assertOk()->assertJsonPath('data.steps.0.command', 'v-add-cron-job')
        ->assertJsonPath('data.steps.0.args.7', '3');
    $this->fake->assertNotRan('v-add-cron-job');

    $this->postJson('/api/v2/users/bob/cron-jobs', [
        'schedule' => '*/5 * * * *', 'command' => 'echo hi',
    ], apiAs($this->fake, 'bob', 'user', readOnly: true))
        ->assertForbidden()->assertJsonPath('error.commands.0', 'v-add-cron-job');
});

it('suspends a job once and turns reports on', function () {
    $this->fake->on('v-suspend-cron-job')->returns();
    $this->fake->on('v-add-cron-reports')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'cron');

    $this->postJson('/api/v2/users/bob/cron-jobs/2/suspend', [], $headers)->assertOk();
    $this->fake->assertNotRan('v-suspend-cron-job');
    $this->postJson('/api/v2/users/bob/cron-jobs/1/suspend', [], $headers)->assertOk();
    $this->fake->assertRan('v-suspend-cron-job', fn ($a) => $a === ['bob', '1']);

    $this->getJson('/api/v2/users/bob/cron-reports', $headers)
        ->assertOk()->assertJsonPath('data.enabled', false);
    $this->putJson('/api/v2/users/bob/cron-reports', ['enabled' => true], $headers)->assertOk()
        ->assertJsonPath('data.enabled', true);
    $this->fake->assertRan('v-add-cron-reports', fn ($a) => $a === ['bob']);
});

it('parses five crontab fields and refuses a macro', function () {
    expect(CronSchedule::parse('*/5 * * * *'))->toBe([
        'min' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'wday' => '*',
    ]);
    expect(fn () => CronSchedule::parse('@daily'))->toThrow(ValidationException::class);
});
