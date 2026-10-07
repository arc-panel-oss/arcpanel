<?php

use App\Api\TaskId;
use App\Services\Tasks\TaskMapper;

// Task id parser (D31) and source mappings (E34 batch 3 spec §5).

it('parses task ids including dotted usernames in the middle segment', function () {
    expect(TaskId::parse('sys:1')?->apiId())->toBe('sys:1')
        ->and(TaskId::parse('docker:bob:kuma:9')?->app)->toBe('kuma')
        ->and(TaskId::parse('node:host.co:42')?->user)->toBe('host.co')
        ->and(TaskId::parse('bad:extra:too:many:parts'))->toBeNull()
        ->and(TaskId::parse('node::1'))->toBeNull();
});

it('maps CLI statuses to API statuses without inventing values', function () {
    expect(TaskMapper::mapStatus('queued'))->toBe('queued')
        ->and(TaskMapper::mapStatus('running'))->toBe('running')
        ->and(TaskMapper::mapStatus('done'))->toBe('succeeded')
        ->and(TaskMapper::mapStatus('succeeded'))->toBe('succeeded')
        ->and(TaskMapper::mapStatus('failed'))->toBe('failed');

    expect(fn () => TaskMapper::mapStatus(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TaskMapper::mapStatus('unknown'))->toThrow(InvalidArgumentException::class);
});

it('maps node app tasks to node-apps resources', function () {
    $view = TaskMapper::fromSource(TaskId::parse('node:bob:1') ?? throw new RuntimeException, [
        'ACTION' => 'app-install', 'ARGS' => 'app.example build', 'STATUS' => 'done', 'EXIT' => '0', 'START' => '', 'END' => '', 'LOG' => '',
    ], 100);

    expect($view->status)->toBe('succeeded')
        ->and($view->resource)->toBe('/users/bob/node-apps/app.example');
});

it('passes queue succeeded status through unchanged', function () {
    $view = TaskMapper::fromSource(TaskId::queue('bob', '4001'), [
        'KIND' => 'backup', 'ARGS' => 'bob.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
        'QUEUED' => '2026-10-07 05:00:00', 'START' => '', 'END' => '', 'LOG' => '',
    ], 100);

    expect($view->status)->toBe('succeeded');
});
