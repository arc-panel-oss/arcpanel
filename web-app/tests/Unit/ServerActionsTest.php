<?php

use App\Http\Resources\V2\IpResource;
use App\Http\Resources\V2\NetworkInterfaceResource;
use App\Http\Resources\V2\ServiceResource;
use App\Http\Resources\V2\ServiceStatusResource;
use App\Services\Ips\SystemIp;
use App\Services\Server\ServiceActions;
use App\Services\Server\SystemServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The server classes of E34 batch 6 (§5.1–§5.3, D61, D66): the rules that
// the server pages and API v2 share, and the Resources of the server
// endpoints.

beforeEach(function () {
    $this->fake = fakeArc();
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net']]);
    $this->fake->on('v-list-sys-services')->returnsJson([
        'nginx' => ['SYSTEM' => 'reverse proxy', 'STATE' => 'running', 'CPU' => '12', 'MEM' => '46', 'RTIME' => '120'],
        'arcpanel' => ['SYSTEM' => 'panel', 'STATE' => 'running', 'CPU' => '0', 'MEM' => '10', 'RTIME' => '45'],
    ]);
});

it('never stops a protected service and restarts it (D61)', function () {
    expect(ServiceActions::allows('stop', 'arcpanel'))->toBeFalse()
        ->and(ServiceActions::allows('restart', 'arcpanel'))->toBeTrue()
        ->and(ServiceActions::allows('start', 'arcpanel'))->toBeTrue()
        ->and(ServiceActions::allows('stop', 'nginx'))->toBeTrue()
        ->and(ServiceActions::partition('stop', ['nginx', 'arcpanel', 'exim4']))->toBe([['nginx', 'exim4'], ['arcpanel']])
        ->and(ServiceActions::partition('restart', ['nginx', 'arcpanel']))->toBe([['nginx', 'arcpanel'], []]);
});

it('knows the services of the server', function () {
    $actions = app(ServiceActions::class);

    $actions->assertKnown(['nginx', 'arcpanel']);
    expect(fn () => $actions->assertKnown(['nginx', 'ghost']))->toThrow(NotFoundHttpException::class);
});

it('confirms a server restart with the exact host name only', function () {
    $actions = app(ServiceActions::class);

    $actions->confirmRestart('srv1.example.net');
    foreach (['srv1', 'SRV1.example.net', '', null, ['srv1.example.net']] as $typed) {
        expect(fn () => $actions->confirmRestart($typed, 'confirm_hostname'))->toThrow(ValidationException::class);
    }
    try {
        $actions->confirmRestart('nope', 'confirm_hostname');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['confirm_hostname' => ['Type the host name of the server to confirm.']]);
    }
});

it('refuses every confirmation when the server has no host name', function () {
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => '']]);

    expect(fn () => app(ServiceActions::class)->confirmRestart(''))->toThrow(ValidationException::class);
});

it('plans service actions with the commands of the page', function () {
    $restart = SystemServices::step('restart', 'nginx');
    $server = SystemServices::restartServerStep();

    expect([$restart->command, $restart->args])->toBe(['v-restart-service', ['nginx', 'yes']])
        ->and(SystemServices::step('stop', 'iptables')->command)->toBe('v-stop-firewall')
        ->and(SystemServices::step('start', 'iptables')->command)->toBe('v-update-firewall')
        ->and([$server->command, $server->args])->toBe(['v-restart-system', ['yes']]);
});

it('types the service, status and IP resources', function () {
    $request = Request::create('/');
    $service = app(SystemServices::class)->all()['nginx'];

    expect((new ServiceResource($service))->resolve($request))->toBe([
        'name' => 'nginx', 'system' => 'reverse proxy', 'running' => true, 'cpu' => 1.2, 'memory_mb' => 46, 'uptime_minutes' => 120,
    ])->and((new ServiceStatusResource(['kind' => 'cpu', 'text' => "load\n"]))->resolve($request))->toBe(['kind' => 'cpu', 'text' => "load\n"])
        ->and((new NetworkInterfaceResource('eth0'))->resolve($request))->toBe(['name' => 'eth0']);

    $ip = SystemIp::fromArray('10.0.0.12', [
        'OWNER' => 'bob', 'STATUS' => 'dedicated', 'NAME' => '', 'U_SYS_USERS' => 'bob,,hostco', 'U_WEB_DOMAINS' => '2',
        'INTERFACE' => 'eth0', 'NETMASK' => '255.255.255.0', 'NAT' => '203.0.113.12', 'TIME' => '11:00:00', 'DATE' => '2026-09-02',
    ]);
    expect((new IpResource($ip))->resolve($request))->toBe([
        'ip' => '10.0.0.12', 'netmask' => '255.255.255.0', 'interface' => 'eth0', 'shared' => false, 'owner' => 'bob',
        'name' => null, 'nat' => '203.0.113.12', 'web_domains' => 2, 'users' => ['bob', 'hostco'], 'created_at' => '2026-09-02T11:00:00',
    ]);
});
