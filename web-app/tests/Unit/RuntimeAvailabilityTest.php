<?php

use App\Arc\ExitCode;
use App\Services\WebDomains\RuntimeAvailability;

// Runtime availability of the template badges (E5 spec 2026-10-06-006 D13).

beforeEach(function () {
    $this->fake = fakeArc(['DOCKER_SYSTEM' => '']);
    $this->fake->on('v-list-sys-packages')->returnsFixture('v-list-sys-packages/default.json');
    $this->fake->on('v-list-user-node-versions')->returnsJson(['ENABLED' => 'no', 'VERSIONS' => []]);
    $this->fake->on('v-list-user-node-versions', ['carol', 'json'])->returnsJson(['ENABLED' => 'yes', 'VERSIONS' => [['VERSION' => '22.11.0', 'MAJOR' => '22']]]);
});

it('answers each runtime by its own rule', function () {
    $runtimes = app(RuntimeAvailability::class);

    expect($runtimes->available('php', 'bob'))->toBeTrue()
        ->and($runtimes->available('python', 'bob'))->toBeTrue()
        ->and($runtimes->available('ruby', 'bob'))->toBeFalse()
        ->and($runtimes->available('go', 'bob'))->toBeFalse()
        ->and($runtimes->available('docker', 'bob'))->toBeFalse()
        ->and($runtimes->available('node', 'bob'))->toBeFalse()
        ->and($runtimes->available('node', 'carol'))->toBeTrue()
        ->and($runtimes->available('none', 'bob'))->toBeNull()
        ->and($runtimes->available('cobol', 'bob'))->toBeNull();
});

it('reads Docker from the server configuration', function () {
    withSysConfig($this->fake, ['DOCKER_SYSTEM' => 'yes']);

    expect(app(RuntimeAvailability::class)->available('docker', 'bob'))->toBeTrue();
});

it('reads the package catalog once and the Node.js runtimes once per owner', function () {
    $runtimes = app(RuntimeAvailability::class);
    foreach (['python', 'go', 'ruby', 'node', 'python', 'node'] as $runtime) {
        $runtimes->available($runtime, 'bob');
    }
    $runtimes->available('node', 'carol');

    // Only the installed entries: the full list asks apt for updates (~650 ms)
    $this->fake->assertRan('v-list-sys-packages', fn ($a) => $a === ['json', 'installed'], times: 1);
    $this->fake->assertRan('v-list-sys-packages', times: 1);
    $this->fake->assertRan('v-list-user-node-versions', fn ($a) => $a[0] === 'bob', times: 1);
    $this->fake->assertRan('v-list-user-node-versions', fn ($a) => $a[0] === 'carol', times: 1);
});

it('treats a failing catalog as no installed runtime', function () {
    $this->fake->on('v-list-sys-packages')->returnsExit(ExitCode::NotExist, 'Error: command not found');

    expect(app(RuntimeAvailability::class)->available('python', 'bob'))->toBeFalse();
});
