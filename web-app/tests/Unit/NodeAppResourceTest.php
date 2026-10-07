<?php

use App\Http\Resources\V2\NodeAppResource;
use App\Services\Node\NodeApp;
use Illuminate\Http\Request;

it('serializes app fields without environment data', function () {
    $app = NodeApp::from(nodeApp('api.bob.example', [
        'ENV' => 'SHOULD_NOT_LEAK',
        'API_KEY' => 'secret',
    ]));
    $data = (new NodeAppResource($app))->resolve(Request::create('/api/v2/users/bob/node-apps/api.bob.example'));

    expect($data)->toHaveKeys([
        'domain', 'name', 'start', 'script', 'cwd', 'node_version', 'runtime', 'port', 'instances',
        'max_memory_mb', 'template', 'state', 'status', 'online', 'reason', 'pid', 'cpu', 'memory_mb',
        'restarts', 'uptime_seconds', 'url', 'ssl', 'suspended', 'created_at',
    ])->not->toHaveKeys(['env', 'environment', 'variables', 'entries', 'value', 'ENV', 'API_KEY']);
});
