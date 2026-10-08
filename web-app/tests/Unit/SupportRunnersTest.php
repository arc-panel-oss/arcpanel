<?php

use App\Arc\ExitCode;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\OwnerSelection;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;

beforeEach(function () {
    $this->fake = fakeArc();
});

it('runs every bulk item, collects failures and restarts once', function () {
    $this->fake->on('v-suspend-database')->returns();
    $this->fake->on('v-suspend-database', ['bob', 'bob_bad', 'no'])->returnsExit(ExitCode::NotExist, "Error: database bob_bad doesn't exist");
    $this->fake->on('v-restart-cron')->returns();

    $result = app(BulkRunner::class)->run(
        [['bob', 'bob_a'], ['bob', 'bob_bad'], ['carol', 'carol_b']],
        fn (array $item) => [$item[1], 'v-suspend-database', [$item[0], $item[1], 'no']],
        ['v-restart-cron'],
    );

    expect($result->done)->toBe(['bob_a', 'carol_b'])
        ->and($result->failed)->toBe(['bob_bad' => "Error: database bob_bad doesn't exist"])
        ->and($result->ok())->toBeFalse();
    $this->fake->assertRan('v-suspend-database', times: 3);
    $this->fake->assertRan('v-restart-cron', times: 1);
});

it('runs the after hook for items that succeeded only', function () {
    $this->fake->on('v-delete-cron-job')->returns();
    $this->fake->on('v-delete-cron-job', ['bob', '2', 'no'])->returnsExit(ExitCode::NotExist, 'missing');
    $seen = [];

    app(BulkRunner::class)->run(['1', '2'], fn (string $job) => [$job, 'v-delete-cron-job', ['bob', $job, 'no']], [], function (string $job) use (&$seen) {
        $seen[] = $job;
    });

    expect($seen)->toBe(['1']);
});

it('does not restart for an empty selection', function () {
    $result = app(BulkRunner::class)->run([], fn ($item) => ['', '', []], ['v-restart-dns']);

    expect($result->done)->toBe([]);
    $this->fake->assertNotRan('v-restart-dns');
});

it('flashes the done and failed summary', function () {
    $ok = (new BulkResult(['a', 'b']))->flash(redirect('/'));
    $failed = (new BulkResult(['a'], ['b' => 'boom']))->flash(redirect('/'));

    expect($ok->getSession()->get('ok'))->toBe('2 done, 0 failed')
        ->and($failed->getSession()->get('error'))->toBe('1 done, 1 failed')
        ->and($failed->getSession()->get('error_detail'))->toBe('b: boom');
});

it('applies a plan, stops at the first failure and restarts only after success', function () {
    $this->fake->on('v-change-dns-domain-ip')->returns();
    $this->fake->on('v-change-dns-domain-ttl')->returnsExit(ExitCode::Invalid, 'Error: invalid ttl');
    $this->fake->on('v-restart-dns')->returns();

    $result = app(PlanRunner::class)->apply([
        new Step('Change the IP address', 'v-change-dns-domain-ip', ['bob', 'example.com', '10.0.0.2', 'no'], ['dns']),
        new Step('Change the TTL', 'v-change-dns-domain-ttl', ['bob', 'example.com', 'x', 'no'], ['dns']),
        new Step('Never', 'v-change-dns-domain-soa', ['bob', 'example.com', 'ns.example.com', 'no'], ['dns']),
    ]);

    expect($result->ok())->toBeFalse()
        ->and($result->failed->label)->toBe('Change the TTL')
        ->and($result->error)->toContain('invalid ttl')
        ->and(count($result->applied))->toBe(1);
    $this->fake->assertNotRan('v-restart-dns');
});

it('marks a step whose argument the runner refused, keeping the steps before it', function () {
    $this->fake->on('v-change-dns-domain-ip')->returns();
    $this->fake->on('v-restart-dns')->returns();

    $result = app(PlanRunner::class)->apply([
        new Step('Change the IP address', 'v-change-dns-domain-ip', ['bob', 'example.com', '10.0.0.2', 'no'], ['dns']),
        new Step('Change the SOA', 'v-change-dns-domain-soa', ['bob', 'example.com', "ns.example.com\n", 'no'], ['dns']),
    ]);

    expect($result->ok())->toBeFalse()
        ->and($result->failed->label)->toBe('Change the SOA')
        ->and($result->invalidArgument)->toBeTrue()
        ->and($result->exitCode)->toBe(0)
        ->and(count($result->applied))->toBe(1);
    $this->fake->assertNotRan('v-change-dns-domain-soa');
    $this->fake->assertNotRan('v-restart-dns');
});

it('restarts each named service once after a successful plan', function () {
    $this->fake->on('v-change-cron-job')->returns();
    $this->fake->on('v-restart-cron')->returns();

    $result = app(PlanRunner::class)->apply([
        new Step('a', 'v-change-cron-job', ['bob', '1'], ['cron']),
        new Step('b', 'v-change-cron-job', ['bob', '2'], ['cron']),
    ]);

    expect($result->ok())->toBeTrue();
    $this->fake->assertRan('v-restart-cron', times: 1);
});

it('lists the selected owner or every visible owner', function () {
    $all = new OwnerSelection(['bob', 'carol'], '', true);
    $one = new OwnerSelection(['bob', 'carol'], 'carol', true);
    $self = new OwnerSelection(['bob'], '', false);

    expect($all->users())->toBe(['bob', 'carol'])
        ->and($one->users())->toBe(['carol'])
        ->and($all->showOwner())->toBeTrue()
        ->and($self->showOwner())->toBeFalse();
});
