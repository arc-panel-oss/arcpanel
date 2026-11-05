<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The alert history of the panel (E16 spec §7): arcpanel:alerts-sync
// projects v-list-alert-history into alerts_history, one row per alert id.

beforeEach(function () {
    $this->fake = fakeArc();
});

it('follows an alert from firing to resolved and keeps every delivery once', function () {
    $fired = alertLine('fired', '17900000000301');
    $this->fake->on('v-list-alert-history', ['all', '', 'json'])->returnsJson([$fired]);
    $this->artisan('arcpanel:alerts-sync')->assertSuccessful();
    $row = DB::table('alerts_history')->first();
    expect($row->state)->toBe('firing')->and($row->scope)->toBe('system')->and($row->user)->toBeNull()
        ->and($row->fired_at)->toBe('2026-11-06 09:55:00')->and($row->last_notified_at)->toBe('2026-11-06 10:00:00')
        ->and(json_decode($row->channels, true))->toHaveCount(1);

    // The next run asks from two minutes before the newest row and reads the fired line again
    $this->fake->on('v-list-alert-history', ['all', '2026-11-06 09:58:00', 'json'])->returnsJson([
        $fired,
        alertLine('raised', '17900000000301', ['time' => '2026-11-06 10:05:00', 'severity' => 'critical', 'value' => '96%', 'count' => 2]),
        alertLine('delivery', '17900000000301', ['time' => '2026-11-06 10:06:00', 'rule' => '', 'object' => '', 'severity' => '',
            'deliveries' => [['channel' => '17900000000002', 'name' => 'Ops chat', 'type' => 'telegram', 'result' => 'dropped', 'error' => 'HTTP 500']]]),
        alertLine('resolved', '17900000000301', ['time' => '2026-11-06 11:00:00', 'value' => '40%', 'deliveries' => []]),
        alertLine('test', '', ['time' => '2026-11-06 11:01:00']),
    ]);
    $this->artisan('arcpanel:alerts-sync')->assertSuccessful();
    $row = DB::table('alerts_history')->first();
    expect(DB::table('alerts_history')->count())->toBe(1)
        ->and($row->state)->toBe('resolved')->and($row->resolved_at)->toBe('2026-11-06 11:00:00')
        ->and($row->severity)->toBe('critical')->and($row->value)->toBe('40%')->and($row->notify_count)->toBe(2)
        ->and(array_column(json_decode($row->channels, true), 'result'))->toBe(['sent', 'sent', 'dropped']);
});

it('writes an event as one resolved row of its user scope', function () {
    $this->fake->on('v-list-alert-history', ['all', '', 'json'])->returnsJson([
        alertLine('event', '17900000000401', ['scope' => 'bob', 'user' => 'bob', 'owner' => 'hostco', 'rule' => 'job.failed',
            'object' => 'job:17280000000001', 'value' => '17280000000001', 'severity' => 'warning', 'since' => '2026-11-06 09:59:00']),
    ]);
    $this->artisan('arcpanel:alerts-sync')->assertSuccessful();
    $row = DB::table('alerts_history')->first();
    expect($row->scope)->toBe('user')->and($row->user)->toBe('bob')->and($row->owner)->toBe('hostco')
        ->and($row->state)->toBe('resolved')->and($row->fired_at)->toBe('2026-11-06 10:00:00')
        ->and($row->resolved_at)->toBe('2026-11-06 10:00:00')->and($row->value)->toBe('17280000000001');
});

it('ends an open row left behind when the same alert fires again', function () {
    $this->fake->on('v-list-alert-history', ['all', '', 'json'])->returnsJson([
        alertLine('fired', '17900000000501'),
        alertLine('fired', '17900000000502', ['time' => '2026-11-06 12:00:00', 'since' => '2026-11-06 12:00:00']),
    ]);
    $this->artisan('arcpanel:alerts-sync')->assertSuccessful();
    expect(DB::table('alerts_history')->where('state', 'firing')->pluck('id')->all())->toBe([17900000000502]);
});

it('builds the table again with --full and when it is missing', function () {
    $this->fake->on('v-list-alert-history', ['all', '', 'json'])->returnsJson([alertLine('fired', '17900000000601')]);
    $this->artisan('arcpanel:alerts-sync')->assertSuccessful();
    $this->fake->on('v-list-alert-history', ['all', '', 'json'])->returnsJson([]);
    $this->artisan('arcpanel:alerts-sync', ['--full' => true])->assertSuccessful();
    expect(DB::table('alerts_history')->count())->toBe(0);

    Schema::drop('alerts_history');
    $this->fake->on('v-list-alert-history', ['all', '', 'json'])->returnsJson([alertLine('fired', '17900000000602')]);
    $this->artisan('arcpanel:alerts-sync')->assertSuccessful();
    expect(DB::table('alerts_history')->pluck('id')->all())->toBe([17900000000602]);
});
