<?php

use App\Http\Resources\V2\DockerAppResource;
use App\Services\Docker\DockerApp;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('serializes list fields without leaking secret env data', function () {
    $app = DockerApp::from(dockerApp('kuma', [
        'TOKEN' => 'secret',
        'SERVICES' => [['SERVICE' => 'app', 'IMAGE' => 'x', 'STATE' => 'running', 'HEALTH' => '', 'STATUS' => '']],
    ]));
    $data = (new DockerAppResource($app))->resolve(Request::create('/api/v2/users/bob/docker-apps'));

    expect($data)->toHaveKeys([
        'name', 'slug', 'app_name', 'version', 'latest_version', 'update_available', 'domain', 'url', 'ssl',
        'web_port', 'state', 'status', 'suspended', 'busy', 'cpus', 'memory_mb', 'created_at',
    ])->not->toHaveKeys(['services', 'ports', 'TOKEN', 'value', 'variables']);
});

it('includes detail-only fields on show', function () {
    $app = DockerApp::from(dockerApp('kuma', [
        'PORTS' => [['NAME' => 'web', 'PORT' => '30020', 'CONTAINER' => '3001', 'PUBLIC' => 'yes', 'DESCRIPTION' => '']],
        'POST_INSTALL' => 'Done',
        'ROLLBACK' => 'yes',
        'LAST_DUMP' => 'dump.tar',
    ]));
    $request = Request::create('/api/v2/users/bob/docker-apps/kuma');
    $request->setRouteResolver(fn () => tap(new Route('GET', '/', fn () => null), function ($route) {
        $route->name('api.v2.docker-apps.show');
    }));

    $data = (new DockerAppResource($app))->resolve($request);
    expect($data)->toHaveKeys(['services', 'ports', 'post_install', 'rollback', 'last_dump']);
});
