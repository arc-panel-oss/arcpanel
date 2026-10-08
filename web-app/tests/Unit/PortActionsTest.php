<?php

use App\Services\Ports\PortAccount;
use App\Services\Ports\PortActions;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Support\Facades\Validator;

// The port rules shared by the Ports pages and API v2 (E34 batch 4 spec D47).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    $this->actions = app(PortActions::class);
    $this->account = PortAccount::from('bob', portAccount('bob', ['OWNER' => 'hostco'], [
        portEntry('30005', ['APP' => 'manual', 'PROTO' => 'udp']),
        portEntry('30006-30009'),
    ]));
});

it('parses a port or a range and refuses anything else', function () {
    expect($this->actions->bounds('30005'))->toBe([30005, 30005])
        ->and($this->actions->bounds('30006-30009'))->toBe([30006, 30009])
        ->and($this->actions->bounds('30009-30006'))->toBeNull()
        ->and($this->actions->bounds('0'))->toBeNull()
        ->and($this->actions->bounds('70000'))->toBeNull()
        ->and($this->actions->bounds('30005;rm'))->toBeNull();
});

it('finds single ports by protocol and checks that a range is held', function () {
    expect($this->actions->entry($this->account, '30005')?->proto)->toBe('udp')
        ->and($this->actions->entry($this->account, '30005', 'udp'))->not->toBeNull()
        ->and($this->actions->entry($this->account, '30005', 'tcp'))->toBeNull()
        ->and($this->actions->entry($this->account, '30006-30009'))->toBeNull()
        ->and($this->actions->entry($this->account, '30006'))->toBeNull()
        ->and($this->actions->holds($this->account, '30005-30009'))->toBeTrue()
        ->and($this->actions->holds($this->account, '30007'))->toBeTrue()
        ->and($this->actions->holds($this->account, '30009-30010'))->toBeFalse();
});

it('reads a range or a count from the form and from the API', function () {
    expect($this->actions->rangeOrCount(['mode' => 'range', 'range' => '30020-30024', 'count' => null]))->toBe(['30020-30024', null])
        ->and($this->actions->rangeOrCount(['mode' => 'count', 'count' => '5']))->toBe([null, 5])
        ->and($this->actions->rangeOrCount(['range' => '30020']))->toBe(['30020', null])
        ->and($this->actions->rangeOrCount(['range' => null, 'count' => 3]))->toBe([null, 3])
        ->and($this->actions->moveSpec(null, 5))->toBe('+5')
        ->and($this->actions->moveSpec('30020-30024', null))->toBe('30020-30024');
});

it('wants exactly one of range and count in the API', function () {
    $rules = $this->actions->giveRules();

    expect(Validator::make(['to' => 'bob', 'count' => 2], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['to' => 'bob', 'range' => '30020-30024'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['to' => 'bob'], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['to' => 'bob', 'range' => '30020', 'count' => 2], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['to' => 'bob', 'range' => '30020;rm'], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['user' => 'bob', 'count' => 2, 'public_ok' => true], $this->actions->allocateRules())->passes())->toBeTrue();
});

it('looks up owners and the ports a rule can be linked to', function () {
    $this->fake->on('v-list-sys-ports', ['json'])->returnsJson([
        'ENTRIES' => [
            portEntry('30005', ['USER' => 'bob', 'APP' => 'manual']),
            portEntry('30006-30009', ['USER' => 'bob']),
            portEntry('30010', ['USER' => 'bob']),
        ],
    ]);

    expect($this->actions->ownerOf('bob'))->toBe('hostco')
        ->and($this->actions->userExists('bob'))->toBeTrue()
        ->and($this->actions->userExists('ghost'))->toBeFalse()
        ->and($this->actions->adoptable(30005)?->user)->toBe('bob')
        ->and($this->actions->adoptable(30007))->toBeNull()
        ->and($this->actions->adoptable(30010))->toBeNull()
        ->and($this->actions->adoptable(31000))->toBeNull();
});

it('turns a run into the flash messages of the pages', function () {
    $step = new Step('Release port 30005', 'v-release-user-port', ['bob', '30005']);

    expect($this->actions->flash(new ApplyResult([$step]), 'Released.'))->toBe(['ok' => 'Released.'])
        ->and($this->actions->flash(new ApplyResult([], $step, 'port 30005 is free'), 'Released.'))
        ->toBe(['error' => 'Release port 30005 failed', 'error_detail' => 'port 30005 is free']);
});
