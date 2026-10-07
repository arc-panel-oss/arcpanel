<?php

use App\Services\Node\NodeAppActions;
use App\Services\Node\NodeAppLogCursor;

it('round-trips log cursors', function () {
    expect(NodeAppLogCursor::decode(NodeAppLogCursor::encode(20)))->toBe(20)
        ->and(NodeAppLogCursor::decode('not-valid'))->toBeNull();
});

it('masks environment entries unless reveal is on', function () {
    $actions = app(NodeAppActions::class);
    $vars = [['key' => 'API_KEY', 'value' => 'secret'], ['key' => 'EMPTY', 'value' => '']];

    expect($actions->envEntries($vars, false))->toBe([
        ['name' => 'API_KEY', 'set' => true],
        ['name' => 'EMPTY', 'set' => false],
    ])->and($actions->envEntries($vars, true)[0]['value'])->toBe('secret');
});

it('keeps stored values when an api entry omits value', function () {
    $actions = app(NodeAppActions::class);
    $merged = $actions->mergeEnvEntries(
        [['name' => 'API_KEY'], ['name' => 'MODE', 'value' => 'staging']],
        [['key' => 'API_KEY', 'value' => 's3cr3t'], ['key' => 'OLD', 'value' => 'gone']],
    );

    expect($merged)->toBe([
        ['key' => 'API_KEY', 'value' => 's3cr3t'],
        ['key' => 'MODE', 'value' => 'staging'],
    ]);
});

it('builds log snapshot meta with a next cursor', function () {
    $actions = app(NodeAppActions::class);
    $body = $actions->logResponse(['stream' => 'out', 'size' => 40, 'offset' => 20, 'lines' => ['a']]);

    expect($body['lines'])->toBe(['a'])
        ->and($body['meta']['stream'])->toBe('out')
        ->and(NodeAppLogCursor::decode((string) $body['meta']['next']))->toBe(20);
});
