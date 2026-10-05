<?php

use App\Arc\ExitCode;
use App\Services\Dashboard\SystemStatus;
use App\Services\WebDomains\Step;
use App\Services\WebDomains\WebDomainService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->fake = fakeArc();
    $this->web = app(WebDomainService::class);
});

it('lists the domains of several users, merged and sorted', function () {
    $this->fake->on('v-list-web-domains', ['bob', 'json'])->returnsFixture('v-list-web-domains/bob.json');
    $this->fake->on('v-list-web-domains', ['carol', 'json'])->returnsFixture('v-list-web-domains/carol.json');

    $records = $this->web->forUsers(['bob', 'carol']);

    expect(array_map(fn ($r) => $r->user.'/'.$r->domain, $records))
        ->toBe(['carol/carol.example', 'bob/example.com', 'bob/shop.example']);
    // one command per user and request
    $this->web->forUser('bob');
    $this->fake->assertRan('v-list-web-domains', fn ($args) => $args[0] === 'bob', times: 1);
});

it('answers an unknown domain with 404', function () {
    $this->fake->on('v-list-web-domain')->returnsExit(ExitCode::NotExist, "Error: web domain doesn't exist");

    $this->web->find('bob', 'nope.example');
})->throws(NotFoundHttpException::class);

it('runs the steps in order and restarts once at the end', function () {
    $this->fake->on('v-add-web-domain-alias')->returns();
    $this->fake->on('v-add-dns-on-web-alias')->returns();
    $this->fake->on('v-restart-web')->returns();
    $this->fake->on('v-restart-proxy')->returns();
    $this->fake->on('v-restart-dns')->returns();

    $result = $this->web->apply([
        new Step('Add alias', 'v-add-web-domain-alias', ['bob', 'example.com', 'a.example.com', 'no'], ['web', 'proxy']),
        new Step('Add DNS', 'v-add-dns-on-web-alias', ['bob', 'a.example.com', '10.0.0.10', 'no'], ['dns']),
    ]);

    expect($result->ok())->toBeTrue()->and($result->applied)->toHaveCount(2)
        ->and(array_values(array_diff(array_column($this->fake->calls(), 'command'), ['v-list-sys-config'])))->toBe(['v-add-web-domain-alias', 'v-add-dns-on-web-alias', 'v-restart-web', 'v-restart-proxy', 'v-restart-dns',
        ]);
});

it('stops at the first failure, keeps what ran and does not restart', function () {
    $this->fake->on('v-add-web-domain-alias')->returns();
    $this->fake->on('v-add-dns-on-web-alias')->returnsExit(ExitCode::Exists, 'Error: record exists');

    $result = $this->web->apply([
        new Step('Add alias', 'v-add-web-domain-alias', ['bob', 'example.com', 'a.example.com', 'no'], ['web']),
        new Step('Add DNS record', 'v-add-dns-on-web-alias', ['bob', 'a.example.com', '10.0.0.10', 'no'], ['dns']),
        new Step('Never', 'v-add-web-domain-alias', ['bob', 'example.com', 'b.example.com', 'no']),
    ]);

    expect($result->ok())->toBeFalse()
        ->and($result->failed->label)->toBe('Add DNS record')
        ->and($result->error)->toBe('Error: record exists')
        ->and($result->applied)->toHaveCount(1);
    $this->fake->assertRan('v-add-web-domain-alias', times: 1);
    $this->fake->assertNotRan('v-restart-web');
});

it('runs bulk actions with RESTART=no for every item, then one round of restarts', function () {
    $this->fake->on('v-suspend-web-domain', ['bob', 'example.com', 'no'])->returns();
    $this->fake->on('v-suspend-web-domain', ['bob', 'gone.example', 'no'])->returnsExit(ExitCode::NotExist, "Error: web domain gone.example doesn't exist");
    foreach (['v-restart-web', 'v-restart-proxy', 'v-restart-dns', 'v-restart-web-backend'] as $restart) {
        $this->fake->on($restart)->returns();
    }

    $result = $this->web->bulk('suspend', [['user' => 'bob', 'domain' => 'example.com'], ['user' => 'bob', 'domain' => 'gone.example']]);

    expect($result['done'])->toBe(['example.com'])
        ->and($result['failed'])->toBe(['gone.example' => "Error: web domain gone.example doesn't exist"]);
    $this->fake->assertRan('v-restart-web-backend', times: 1);
});

it('plans the commands of web/add/web, with the public IP for the DNS zone', function () {
    $steps = $this->web->planCreate('bob', 'new.example', '10.0.0.10', true, true, ['10.0.0.10' => ['NAT' => '203.0.113.10']]);

    expect(array_map(fn (Step $s) => $s->command.' '.implode(' ', $s->args), $steps))->toBe([
        'v-add-web-domain bob new.example 10.0.0.10 yes',
        'v-add-dns-domain bob new.example 203.0.113.10         no',
        'v-add-mail-domain bob new.example',
    ]);
});

it('reads the last lines of the logs as plain text', function () {
    $this->fake->on('v-list-web-domain-errorlog', ['bob', 'example.com'])->returns("line one\nline two\n");

    expect($this->web->log('bob', 'example.com', 'error'))->toBe(['line one', 'line two']);
});

it('parses the plain reports of the system status commands', function () {
    $dir = base_path('tests/Fixtures/commands/v-list-sys-status/');

    expect(SystemStatus::cpu((string) file_get_contents($dir.'cpu.txt')))->toBe(['percent' => 23])
        ->and(SystemStatus::memory((string) file_get_contents($dir.'memory.txt')))->toBe([
            'totalMb' => 16000, 'usedMb' => 9800, 'percent' => 61, 'swapTotalMb' => 2048, 'swapUsedMb' => 205,
        ])
        ->and(SystemStatus::disk((string) file_get_contents($dir.'disk.txt')))->toBe([
            'size' => '500G', 'used' => '410G', 'available' => '90G', 'percent' => 82,
        ])
        ->and(SystemStatus::cpu('garbage'))->toBe(['percent' => 0]);
});
