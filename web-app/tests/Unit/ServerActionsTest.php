<?php

use App\Arc\ExitCode;
use App\Http\Resources\V2\FirewallBanResource;
use App\Http\Resources\V2\FirewallIpsetResource;
use App\Http\Resources\V2\FirewallRuleResource;
use App\Http\Resources\V2\GraphDataResource;
use App\Http\Resources\V2\GraphResource;
use App\Http\Resources\V2\IpResource;
use App\Http\Resources\V2\NetworkInterfaceResource;
use App\Http\Resources\V2\PanelPackageResource;
use App\Http\Resources\V2\ServiceResource;
use App\Http\Resources\V2\ServiceStatusResource;
use App\Http\Resources\V2\SystemPackageResource;
use App\Http\Resources\V2\UpdateSettingsResource;
use App\Services\Firewall\FirewallActions;
use App\Services\Firewall\FirewallBan;
use App\Services\Firewall\FirewallRule;
use App\Services\Firewall\FirewallService;
use App\Services\Firewall\IpsetList;
use App\Services\Ips\SystemIp;
use App\Services\Server\PanelUpdates;
use App\Services\Server\UpdateCenter;
use App\Services\Server\RrdService;
use App\Services\Server\ServiceActions;
use App\Services\Server\SystemServices;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        ->and(SystemServices::step('stop', 'nftables')->command)->toBe('v-stop-firewall')
        ->and(SystemServices::step('restart', 'nftables')->command)->toBe('v-update-firewall')
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

it('checks rules, IPset lists and bans and filters the managed rules (D66)', function () {
    $row = fn (string $comment, string $port = '22') => ['ACTION' => 'DROP', 'PROTOCOL' => 'TCP', 'PORT' => $port, 'IP' => '0.0.0.0/0', 'COMMENT' => $comment, 'SUSPENDED' => 'no', 'TIME' => '', 'DATE' => ''];
    $this->fake->on('v-list-firewall')->returnsJson(['2' => $row('SSH'), '1' => $row('PING', '0'), '3' => $row('arcpanel-port-30005-bob', '30005')]);
    $this->fake->on('v-list-firewall-ipset')->returnsJson(['country-nl' => ['IP_VERSION' => 'v4']]);
    $this->fake->on('v-list-firewall-ban')->returnsJson(['2001:db8::7' => ['IP' => '2001:db8::7', 'CHAIN' => 'WEB']]);
    $actions = app(FirewallActions::class);

    $rules = $actions->existingRules(['3', '1']);
    expect(array_map(fn (FirewallRule $rule) => $rule->id, $rules))->toBe(['3', '1'])
        ->and(FirewallActions::partition($rules))->toBe([['1'], ['3']])
        ->and(FirewallActions::changeable($rules[0]))->toBeFalse()
        ->and(fn () => $actions->existingRules(['1', '9']))->toThrow(NotFoundHttpException::class);

    $actions->assertIpsetsExist(['country-nl']);
    expect(fn () => $actions->assertIpsetsExist(['ghost']))->toThrow(NotFoundHttpException::class)
        ->and($actions->existingBans(['WEB:2001:db8::7', 'WEB:2001:db8::7']))->toBe([['WEB', '2001:db8::7']])
        ->and(fn () => $actions->existingBans(['SSH:2001:db8::7']))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $actions->existingBans(['garbage']))->toThrow(HttpException::class)
        ->and($actions->ban('WEB', '2001:db8::7')->ip)->toBe('2001:db8::7')
        ->and(fn () => $actions->ban('SSH', '2001:db8::7'))->toThrow(NotFoundHttpException::class);
});

it('finds identical rules, the next position and the neighbour of a move (D59)', function () {
    $row = fn (string $action, string $port, string $ip) => ['ACTION' => $action, 'PROTOCOL' => 'TCP', 'PORT' => $port, 'IP' => $ip, 'COMMENT' => '', 'SUSPENDED' => 'no'];
    $this->fake->on('v-list-firewall')->returnsJson(['1' => $row('ACCEPT', '80,443', '0.0.0.0/0'), '2' => $row('DROP', '25', '10.0.0.0/8'), '4' => $row('DROP', '22', '0.0.0.0/0')]);
    $actions = app(FirewallActions::class);

    expect($actions->duplicateOf('accept', 'tcp', ' 80 443 ', '0.0.0.0/0')?->id)->toBe('1')
        ->and($actions->duplicateOf('ACCEPT', 'UDP', '80,443', '0.0.0.0/0'))->toBeNull()
        ->and($actions->duplicateOf('DROP', 'TCP', '25', '10.0.0.0/16'))->toBeNull()
        ->and($actions->nextId())->toBe(5);

    $rules = app(FirewallService::class)->rules();
    expect($actions->neighbour($rules['1'], 'down')?->id)->toBe('2')
        ->and($actions->neighbour($rules['2'], 'up')?->id)->toBe('1')
        ->and($actions->neighbour($rules['1'], 'up'))->toBeNull()
        ->and($actions->neighbour($rules['2'], 'down'))->toBeNull();

    $this->fake->on('v-list-firewall')->returnsJson([]);
    expect(app(FirewallActions::class)->nextId())->toBe(1);
});

it('types the firewall resources', function () {
    $request = Request::create('/');
    $rule = FirewallRule::fromArray('4', ['ACTION' => 'ACCEPT', 'PROTOCOL' => 'TCP', 'PORT' => '30005', 'IP' => '0.0.0.0/0', 'COMMENT' => 'arcpanel-port-30005-bob', 'SUSPENDED' => 'no', 'TIME' => '07:40:16', 'DATE' => '2026-10-01']);
    $plain = FirewallRule::fromArray('1', ['ACTION' => 'DROP', 'PROTOCOL' => 'ICMP', 'PORT' => '0', 'IP' => 'ipset:nl', 'COMMENT' => '', 'SUSPENDED' => 'yes']);
    $list = IpsetList::fromArray('nl', ['SOURCE' => 'https://example.org/nl.txt', 'IP_VERSION' => 'v4', 'AUTOUPDATE' => 'yes', 'SUSPENDED' => 'no', 'TIME' => '10:00:00', 'DATE' => '2026-10-01']);
    $ban = FirewallBan::fromArray('2001:db8::7', ['CHAIN' => 'WEB', 'TIME' => '12:30:00', 'DATE' => '2026-10-05']);

    expect((new FirewallRuleResource($rule))->resolve($request))->toBe([
        'id' => 4, 'action' => 'ACCEPT', 'protocol' => 'TCP', 'port' => '30005', 'source' => '0.0.0.0/0', 'comment' => 'arcpanel-port-30005-bob',
        'suspended' => false, 'managed' => true, 'updated_at' => '2026-10-01T07:40:16',
    ])->and((new FirewallRuleResource($plain))->resolve($request))->toMatchArray(['id' => 1, 'comment' => null, 'suspended' => true, 'managed' => false, 'updated_at' => null])
        ->and((new FirewallIpsetResource($list))->resolve($request))
        ->toBe(['name' => 'nl', 'source' => 'https://example.org/nl.txt', 'ip_version' => 'v4', 'auto_update' => true, 'suspended' => false, 'created_at' => '2026-10-01T10:00:00'])
        ->and((new FirewallBanResource($ban))->resolve($request))->toBe(['ip' => '2001:db8::7', 'chain' => 'WEB', 'created_at' => '2026-10-05T12:30:00']);
});

it('plans the firewall commands of the pages', function () {
    $create = FirewallService::planCreate('ACCEPT', '0.0.0.0/0', ' 80  443,,', 'TCP', 'web', 7);
    $ipset = FirewallService::planCreateIpset('nl', 'https://example.org/nl.txt', 'v4', false);

    expect([$create->command, $create->args])->toBe(['v-add-firewall-rule', ['ACCEPT', '0.0.0.0/0', '80,443', 'TCP', 'web', '7']])
        ->and(FirewallService::planUpdate('2', 'DROP', '1.2.3.4', '22', 'TCP', '')->args)->toBe(['2', 'DROP', '1.2.3.4', '22', 'TCP', ''])
        ->and(FirewallService::planMove('3', 'up')->args)->toBe(['3', 'up'])
        ->and(FirewallService::planRule('unsuspend', '3')->command)->toBe('v-unsuspend-firewall-rule')
        ->and($ipset->args)->toBe(['nl', 'https://example.org/nl.txt', 'v4', 'no'])
        ->and(FirewallService::planDeleteBan('2001:db8::7', 'WEB')->args)->toBe(['2001:db8::7', 'WEB']);
});

it('plans the system task steps of updates and system packages (§5.4, §5.5)', function () {
    $all = PanelUpdates::planRun(null);
    $one = PanelUpdates::planRun('arcpanel-php');
    $package = SystemTaskService::step('Install Go', 'package', ['go', 'add']);

    expect([$all->command, $all->args])->toBe(['v-start-sys-task', ['arcpanel-update']])
        ->and($one->args)->toBe(['arcpanel-update', 'arcpanel-php'])
        ->and([$package->label, $package->args])->toBe(['Install Go', ['package', 'go', 'add']]);

});

it('plans the steps of the Update Center (E33 phase 1 §9)', function () {
    $this->fake->on('v-list-sys-update-policy')->returnsFixture('v-list-sys-update-policy/default.json');
    $center = app(UpdateCenter::class);
    $release = UpdateCenter::planRelease('2.5.1', 'admin', 'arcadmin');
    $rollback = UpdateCenter::planRollback(12);

    expect([$release->command, $release->args])->toBe(['v-start-sys-task', ['arcpanel-release', '2.5.1', 'admin', 'arcadmin']])
        ->and([$rollback->command, $rollback->args])->toBe(['v-start-sys-task', ['arcpanel-rollback', '12']])
        ->and(UpdateCenter::planResume()->command)->toBe('v-resume-sys-arcpanel-autoupdate')
        ->and($center->planAutoUpdate(true))->toBe([])
        ->and($center->planAutoUpdate(false)[0]->args)->toBe(['notify'])
        ->and($center->planPolicy(null, null, null, null))->toBe([])
        ->and($center->planPolicy('patch', ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], '02-04', 2))->toBe([])
        ->and($center->planPolicy(null, ['fri', 'mon'], null, 0)[0]->args)->toBe(['patch', 'mon,fri', '02-04', '0']);
});

it('reads the series of a graph and knows its unit (D65)', function () {
    $this->fake->on('v-list-sys-rrd')->returnsJson(commandFixture('v-list-sys-rrd/default.json'));
    $this->fake->on('v-export-rrd')->returns('{"about": "RRDtool xport JSON output", "meta": {"start": 100, "end": 700, "step": 300, "legend": ["Used", "Swap"]}, "data": [[1.0000000000e+03, null], [2.5e+02, 3]]}');
    $rrd = app(RrdService::class);

    expect($rrd->find('net_eth0'))->toBe(['service' => 'net_eth0', 'title' => 'Bandwidth Usage eth0', 'type' => 'net'])
        ->and($rrd->find('eth0'))->toBeNull()
        ->and($rrd->export('mem', 'weekly'))->toBe([
            'start' => 100, 'end' => 700, 'step' => 300, 'series' => ['Used', 'Swap'],
            'points' => [['time' => 100, 'values' => [1000.0, null]], ['time' => 400, 'values' => [250.0, 3.0]]],
        ])
        ->and(RrdService::unit('mem'))->toBe('Mbytes')
        ->and(RrdService::unit('net_eth0'))->toBe('KBytes')
        ->and(RrdService::unit('mysql_localhost'))->toBe('Queries')
        ->and(RrdService::unit('httpd'))->toBeNull();

    $this->fake->on('v-export-rrd')->returnsExit(ExitCode::Args, 'Does not exist');
    expect($rrd->export('mem', 'daily'))->toBeNull();
});

it('types the update, system package and graph resources', function () {
    $request = Request::create('/');

    expect((new PanelPackageResource(['name' => 'arcpanel', 'version' => '2.0.0', 'arch' => 'amd64', 'upToDate' => false, 'description' => 'core', 'date' => '']))->resolve($request))
        ->toBe(['name' => 'arcpanel', 'version' => '2.0.0', 'arch' => 'amd64', 'description' => 'core', 'up_to_date' => false, 'built_at' => null])
        ->and((new UpdateSettingsResource(['auto_update' => false, 'send_email' => true, 'send_log' => true]))->resolve($request))
        ->toBe(['auto_update' => false, 'send_email' => true, 'send_log' => true])
        ->and((new GraphResource(['service' => 'la', 'title' => 'Load Average', 'type' => 'la']))->resolve($request))
        ->toBe(['id' => 'la', 'title' => 'Load Average', 'type' => 'la'])
        ->and(array_keys((new GraphDataResource(['graph' => ['service' => 'la', 'title' => 'Load', 'type' => 'la'], 'period' => 'daily', 'unit' => null,
            'start' => 1, 'end' => 2, 'step' => 1, 'series' => [], 'points' => []]))->resolve($request)))
        ->toBe(['id', 'title', 'period', 'unit', 'start', 'end', 'step', 'series', 'points']);

    $entry = ['slug' => 'ffmpeg', 'name' => 'FFmpeg', 'summary' => 's', 'category' => 'media', 'provides' => [], 'packages' => ['ffmpeg'],
        'available' => true, 'status' => 'partial', 'source' => 'system', 'version' => '', 'upgradable' => false, 'domains' => [], 'usedBy' => 0];
    expect((new SystemPackageResource($entry))->resolve($request))->toMatchArray(['status' => 'partial', 'source' => 'system', 'version' => null, 'used_by' => 0]);
});
