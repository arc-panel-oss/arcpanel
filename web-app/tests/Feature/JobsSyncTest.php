<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The job index of the panel (E15 spec §6.1): arcpanel:jobs-sync projects
// v-list-jobs into jobs_log, incrementally or in full, and rebuilds a
// missing table.

beforeEach(function () {
    $this->fake = fakeArc();
});

function syncedIds(): array
{
    return DB::table('jobs_log')->orderBy('id')->pluck('id')->all();
}

it('builds the index from every job on the first run, then asks only for the recent ones', function () {
    $this->fake->on('v-list-jobs', ['all', 'json'])->returnsJson([
        '17280000000001' => jobRecord('17280000000001', ['UPDATED' => '2026-11-06 10:00:00']),
        '17280000000002' => jobRecord('17280000000002', ['STATUS' => 'running', 'EXIT' => '', 'END' => '', 'UPDATED' => '2026-11-06 10:05:00']),
    ]);
    $this->artisan('arcpanel:jobs-sync')->assertSuccessful();
    expect(syncedIds())->toBe(['17280000000001', '17280000000002']);

    // Two minutes before the newest row; the running job has finished
    $this->fake->on('v-list-jobs', ['2026-11-06 10:03:00', 'json'])->returnsJson([
        '17280000000002' => jobRecord('17280000000002', ['STATUS' => 'failed', 'EXIT' => '1', 'ERROR' => 'pull failed', 'UPDATED' => '2026-11-06 10:06:00']),
    ]);
    $this->artisan('arcpanel:jobs-sync')->assertSuccessful();
    $row = DB::table('jobs_log')->where('id', '17280000000002')->first();
    expect($row->status)->toBe('failed')->and($row->exit_code)->toBe(1)->and($row->error)->toBe('pull failed')
        ->and(syncedIds())->toBe(['17280000000001', '17280000000002']);
    $this->fake->assertRan('v-list-jobs', fn ($a) => $a === ['2026-11-06 10:03:00', 'json']);
});

it('drops the rows of removed jobs with --full', function () {
    $this->fake->on('v-list-jobs', ['all', 'json'])->returnsJson([
        '17280000000001' => jobRecord('17280000000001'),
        '17280000000002' => jobRecord('17280000000002'),
    ]);
    $this->artisan('arcpanel:jobs-sync')->assertSuccessful();

    $this->fake->on('v-list-jobs', ['all', 'json'])->returnsJson([
        '17280000000002' => jobRecord('17280000000002'),
    ]);
    $this->artisan('arcpanel:jobs-sync', ['--full' => true])->assertSuccessful();
    expect(syncedIds())->toBe(['17280000000002']);
});

it('rebuilds the table when it is missing', function () {
    Schema::drop('jobs_log');
    $this->fake->on('v-list-jobs', ['all', 'json'])->returnsJson([
        '17280000000003' => jobRecord('17280000000003'),
    ]);
    $this->artisan('arcpanel:jobs-sync')->assertSuccessful();
    expect(Schema::hasTable('jobs_log'))->toBeTrue()->and(syncedIds())->toBe(['17280000000003']);
});

it('skips records without a valid id and fails when the jobs cannot be listed', function () {
    $this->fake->on('v-list-jobs', ['all', 'json'])->returnsJson([
        'x' => ['ID' => '../etc', 'TYPE' => 'bad'],
        '17280000000004' => jobRecord('17280000000004'),
    ]);
    $this->artisan('arcpanel:jobs-sync')->assertSuccessful();
    expect(syncedIds())->toBe(['17280000000004']);

    $this->fake->on('v-list-jobs')->returnsExit(1, 'Error: no access');
    $this->artisan('arcpanel:jobs-sync', ['--full' => true])->assertFailed();
});
