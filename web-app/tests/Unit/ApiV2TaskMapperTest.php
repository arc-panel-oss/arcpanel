<?php

use App\Api\TaskId;
use App\Services\Tasks\TaskMapper;

// Task id parser (D31) and source mappings (E34 batch 3 spec §5).

it('parses task ids including dotted usernames in the middle segment', function () {
    expect(TaskId::parse('sys:17280000000001')?->apiId())->toBe('sys:17280000000001')
        ->and(TaskId::parse('docker:bob:kuma:17280000000009')?->app)->toBe('kuma')
        ->and(TaskId::parse('node:host.co:17280000000042')?->user)->toBe('host.co')
        ->and(TaskId::parse('queue:bob:17280000004001')?->recordId)->toBe('17280000004001')
        ->and(TaskId::parse('bad:extra:too:many:parts'))->toBeNull()
        ->and(TaskId::parse('node::17280000000001'))->toBeNull();
});

it('refuses record ids that are not 14 digits', function (string $id) {
    expect(TaskId::parse($id))->toBeNull();
})->with(['sys:1', 'queue:bob:3999', 'queue:bob:abc', 'queue:bob:../x', 'queue:bob:172800000040011', 'docker:bob:kuma:', 'install:bob:1728000000400a']);

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
    $view = TaskMapper::fromSource(TaskId::parse('node:bob:17280000000001') ?? throw new RuntimeException, [
        'ACTION' => 'app-install', 'ARGS' => 'app.example build', 'STATUS' => 'done', 'EXIT' => '0', 'START' => '', 'END' => '', 'LOG' => '',
    ], 100);

    expect($view->status)->toBe('succeeded')
        ->and($view->resource)->toBe('/users/bob/node-apps/app.example');
});

it('maps Git Deploy tasks to the deploy of their web domain (E20)', function (string $args) {
    $view = TaskMapper::fromSource(TaskId::queue('bob', '17617400001234'), [
        'KIND' => 'deploy', 'ARGS' => $args, 'STATUS' => 'running', 'EXIT' => '', 'ERROR' => '',
        'QUEUED' => '2026-10-29 14:24:58', 'START' => '2026-10-29 14:24:58', 'END' => '', 'LOG' => '',
    ], 100);

    expect($view->kind)->toBe('deploy')
        ->and($view->args)->toBe($args)
        ->and($view->resource)->toBe('/users/bob/web-domains/shop.example.com/deploy');
})->with(['shop.example.com pull main', 'shop.example.com archive', 'shop.example.com rollback 20261028-091744']);

it('passes queue succeeded status through unchanged', function () {
    $view = TaskMapper::fromSource(TaskId::queue('bob', '17280000004001'), [
        'KIND' => 'backup', 'ARGS' => 'bob.tar', 'STATUS' => 'succeeded', 'EXIT' => '0', 'ERROR' => '',
        'QUEUED' => '2026-10-07 05:00:00', 'START' => '', 'END' => '', 'LOG' => '',
    ], 100);

    expect($view->status)->toBe('succeeded');
});
