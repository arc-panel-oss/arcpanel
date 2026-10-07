<?php

use App\Services\Docker\DockerAppActions;
use App\Services\Docker\DockerAppLogCursor;
use App\Services\Docker\DockerAppService;
use Illuminate\Validation\ValidationException;

it('filters admin-only catalog entries', function () {
    $actions = app(DockerAppActions::class);
    $apps = [
        ['slug' => 'uptime-kuma', 'adminOnly' => false],
        ['slug' => 'portainer', 'adminOnly' => true],
    ];
    expect($actions->visibleCatalog($apps, false))->toHaveCount(1)
        ->and($actions->visibleCatalog($apps, true))->toHaveCount(2);
});

it('masks secret env entries and keeps plain values', function () {
    $actions = app(DockerAppActions::class);
    $entries = $actions->envEntries([
        ['name' => 'TZ', 'label' => 'TZ', 'type' => 'string', 'secret' => false, 'set' => true, 'value' => 'UTC', 'options' => [], 'immutable' => false, 'required' => false, 'pattern' => ''],
        ['name' => 'TOKEN', 'label' => 'Token', 'type' => 'password', 'secret' => true, 'set' => true, 'value' => 'secret', 'options' => [], 'immutable' => false, 'required' => false, 'pattern' => ''],
    ]);
    expect($entries[0]['value'])->toBe('UTC')
        ->and($entries[1])->not->toHaveKey('value');
});

it('round-trips docker log cursors', function () {
    $since = '2026-10-05T10:00:00.123456789Z';
    $cursor = DockerAppLogCursor::encode($since);
    expect(DockerAppLogCursor::decode($cursor))->toBe($since)
        ->and(DockerAppLogCursor::decode('bad'))->toBeNull();
});

it('rejects forbidden variable values like the session island', function () {
    $actions = app(DockerAppActions::class);
    expect(fn () => $actions->checkedVars(['TZ' => "a\nb"], false))->toThrow(ValidationException::class);
    expect($actions->checkedVars(['TZ' => 'UTC'], true))->toBe(['TZ' => 'UTC']);
    expect(preg_match(DockerAppService::NAME, 'kuma'))->toBe(1);
});
