<?php

use App\Arc\ArcCommand;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\PortPolicy;
use App\Policies\Scope;
use App\Services\Ports\PortEntry;
use App\Tenancy\TenancyDirectory;

// PortPolicy (E12 spec §7.2): hostco owns bob, resb owns carol and dave.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
});

function portPolicy(Principal $principal): PortPolicy
{
    $tenancy = new TenancyDirectory(app(ArcCommand::class));

    return new PortPolicy(new OwnerScope($principal, $tenancy), $tenancy);
}

function port(string $user, array $overrides = []): PortEntry
{
    return PortEntry::from(portEntry('30005', $overrides), $user);
}

it('keeps the server page and allocation for administrators', function () {
    expect(Capabilities::scopeOf(Role::Admin, 'server.ports'))->toBe(Scope::All)
        ->and(Capabilities::allows(Role::Reseller, 'server.ports'))->toBeFalse()
        ->and(Capabilities::allows(Role::Reseller, 'ports.allocate'))->toBeFalse()
        ->and(Capabilities::scopeOf(Role::Reseller, 'ports.view'))->toBe(Scope::Tree)
        ->and(Capabilities::scopeOf(Role::User, 'ports.mine'))->toBe(Scope::Self)
        ->and(Capabilities::allows(Role::Admin, 'ports.mine'))->toBeFalse();

    expect(portPolicy(principal('arcadmin', Role::Admin))->allocate(principal('arcadmin', Role::Admin)))->toBeTrue();
    $hostco = principal('hostco', Role::Reseller);
    expect(portPolicy($hostco)->allocate($hostco))->toBeFalse()
        ->and(portPolicy($hostco)->viewServer($hostco))->toBeFalse();
});

it('lets every role reserve its own ports, administrators anyone\'s', function () {
    $bob = principal('bob', Role::User);
    $hostco = principal('hostco', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);

    expect(portPolicy($bob)->reserve($bob, 'bob'))->toBeTrue()
        ->and(portPolicy($bob)->reserve($bob, 'carol'))->toBeFalse()
        ->and(portPolicy($hostco)->reserve($hostco, 'hostco'))->toBeTrue()
        ->and(portPolicy($hostco)->reserve($hostco, 'bob'))->toBeFalse()
        ->and(portPolicy($admin)->reserve($admin, 'carol'))->toBeTrue();
});

it('opens only assigned single ports that may be public, inside the scope', function () {
    $bob = principal('bob', Role::User);
    $hostco = principal('hostco', Role::Reseller);
    $open = port('bob', ['APP' => 'manual', 'PUBLIC_OK' => 'yes']);

    expect(portPolicy($bob)->setPublic($bob, 'bob', $open, true))->toBeTrue()
        ->and(portPolicy($bob)->setPublic($bob, 'bob', port('bob', ['APP' => 'manual']), true))->toBeFalse()
        ->and(portPolicy($bob)->setPublic($bob, 'bob', port('bob', ['APP' => 'manual']), false))->toBeTrue()
        ->and(portPolicy($bob)->setPublic($bob, 'bob', port('bob', ['PUBLIC_OK' => 'yes']), true))->toBeFalse()
        ->and(portPolicy($hostco)->setPublic($hostco, 'bob', $open, true))->toBeTrue()
        ->and(portPolicy($hostco)->setPublic($hostco, 'carol', $open, true))->toBeFalse();
});

it('lets a reseller forbid public ports of its customers but never grant them', function () {
    $hostco = principal('hostco', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);
    $bob = principal('bob', Role::User);

    expect(portPolicy($hostco)->setPublicOk($hostco, 'bob', false))->toBeTrue()
        ->and(portPolicy($hostco)->setPublicOk($hostco, 'bob', true))->toBeFalse()
        ->and(portPolicy($hostco)->setPublicOk($hostco, 'carol', false))->toBeFalse()
        ->and(portPolicy($hostco)->setPublicOk($hostco, 'hostco', false))->toBeFalse()
        ->and(portPolicy($admin)->setPublicOk($admin, 'carol', true))->toBeTrue()
        ->and(portPolicy($bob)->setPublicOk($bob, 'bob', false))->toBeFalse();
});

it('limits giving and taking back ports to the reseller tree', function () {
    $hostco = principal('hostco', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);
    $bob = principal('bob', Role::User);

    expect(portPolicy($hostco)->give($hostco, 'bob'))->toBeTrue()
        ->and(portPolicy($hostco)->give($hostco, 'carol'))->toBeFalse()
        ->and(portPolicy($hostco)->reclaim($hostco, 'bob'))->toBeTrue()
        ->and(portPolicy($admin)->give($admin, 'carol'))->toBeTrue()
        ->and(portPolicy($admin)->give($admin, 'arcadmin'))->toBeFalse()
        ->and(portPolicy($bob)->give($bob, 'bob'))->toBeFalse();
});

it('lets users and resellers request ports from their owner, and nothing in read-only mode', function () {
    $bob = principal('bob', Role::User);
    $admin = principal('arcadmin', Role::Admin);
    $readOnly = principal('bob', Role::User, readOnly: true);

    expect(portPolicy($bob)->request($bob))->toBeTrue()
        ->and(portPolicy($admin)->request($admin))->toBeFalse()
        ->and(portPolicy($readOnly)->request($readOnly))->toBeFalse()
        ->and(portPolicy($readOnly)->reserve($readOnly, 'bob'))->toBeFalse()
        ->and(portPolicy($readOnly)->abilities($readOnly, 'bob'))->each->toBeFalse();
});

it('limits a reseller acting as a customer to that customer', function () {
    $look = principal('hostco', Role::Reseller, look: 'bob', effective: Role::User);

    expect(portPolicy($look)->reserve($look, 'bob'))->toBeTrue()
        ->and(portPolicy($look)->give($look, 'bob'))->toBeFalse()
        ->and(portPolicy($look)->setPublicOk($look, 'bob', false))->toBeFalse();
});

it('lets only administrators link a firewall rule to a port (E39 W4 §10)', function () {
    $admin = principal('arcadmin', Role::Admin);
    $hostco = principal('hostco', Role::Reseller);
    $bob = principal('bob', Role::User);

    expect(portPolicy($admin)->linkRule($admin, 'bob'))->toBeTrue()
        ->and(portPolicy($hostco)->linkRule($hostco, 'bob'))->toBeFalse()
        ->and(portPolicy($bob)->linkRule($bob, 'bob'))->toBeFalse()
        ->and(portPolicy(principal('arcadmin', Role::Admin, look: 'bob'))->linkRule(principal('arcadmin', Role::Admin, look: 'bob'), 'bob'))->toBeFalse()
        ->and(portPolicy(principal('arcadmin', Role::Admin, readOnly: true))->linkRule(principal('arcadmin', Role::Admin, readOnly: true), 'bob'))->toBeFalse();
});
