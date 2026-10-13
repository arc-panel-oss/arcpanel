<?php

use App\Arc\ArcCommand;
use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\Scope;
use App\Policies\SearchPolicy;
use App\Policies\ServerPolicy;
use App\Policies\WebDomainPolicy;
use App\Tenancy\TenancyDirectory;

// E7 batch 3 spec §5: hostco owns bob, resb owns carol and dave.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
});

function serverPolicy(): ServerPolicy
{
    app()->forgetInstance(SysConfig::class);

    return new ServerPolicy(app(SysConfig::class));
}

it('adds the batch 3 capabilities with their scopes', function () {
    foreach (['server.services', 'server.settings', 'server.config', 'server.updates'] as $capability) {
        expect(Capabilities::scopeOf(Role::Admin, $capability))->toBe(Scope::All)
            ->and(Capabilities::allows(Role::Reseller, $capability))->toBeFalse()
            ->and(Capabilities::allows(Role::User, $capability))->toBeFalse();
    }
    foreach (['search.use', 'web.ssl.generate'] as $capability) {
        expect(Capabilities::scopeOf(Role::Admin, $capability))->toBe(Scope::All)
            ->and(Capabilities::scopeOf(Role::Reseller, $capability))->toBe(Scope::Tree)
            ->and(Capabilities::scopeOf(Role::User, $capability))->toBe(Scope::Self);
    }
});

it('keeps the server pages for administrators and honours read-only mode', function () {
    $admin = principal('arcadmin', Role::Admin);
    $readOnly = principal('arcadmin', Role::Admin, readOnly: true);
    $policy = serverPolicy();

    expect($policy->viewServices($admin))->toBeTrue()
        ->and($policy->manageServices($admin))->toBeTrue()
        ->and($policy->viewSettings($admin))->toBeTrue()
        ->and($policy->editSettings($admin))->toBeTrue()
        ->and($policy->viewConfig($admin))->toBeTrue()
        ->and($policy->editConfig($admin))->toBeTrue()
        ->and($policy->viewUpdates($admin))->toBeTrue()
        ->and($policy->manageUpdates($admin))->toBeTrue()
        ->and($policy->viewServices($readOnly))->toBeTrue()
        ->and($policy->manageServices($readOnly))->toBeFalse()
        ->and($policy->viewSettings($readOnly))->toBeTrue()
        ->and($policy->editSettings($readOnly))->toBeFalse()
        ->and($policy->editConfig($readOnly))->toBeFalse()
        ->and($policy->manageUpdates($readOnly))->toBeFalse();

    foreach ([principal('hostco', Role::Reseller), principal('bob', Role::User), principal('arcadmin', Role::Admin, look: 'bob')] as $other) {
        expect($policy->viewServices($other))->toBeFalse()
            ->and($policy->viewSettings($other))->toBeFalse()
            ->and($policy->viewConfig($other))->toBeFalse()
            ->and($policy->viewUpdates($other))->toBeFalse();
    }
});

it('never stops the services the panel needs', function () {
    $admin = principal('arcadmin', Role::Admin);
    $policy = serverPolicy();

    expect($policy->stopService($admin, 'nginx'))->toBeTrue()
        ->and($policy->stopService($admin, 'arcpanel'))->toBeFalse()
        ->and(ServerPolicy::isProtectedService('arcpanel'))->toBeTrue()
        ->and(ServerPolicy::isProtectedService('exim4'))->toBeFalse();
});

it('hides the services from other administrators when POLICY_SYSTEM_HIDE_SERVICES is on', function () {
    withSysConfig($this->fake, ['POLICY_SYSTEM_HIDE_SERVICES' => 'yes']);
    $policy = serverPolicy();

    expect($policy->viewServices(principal('arcadmin', Role::Admin)))->toBeTrue()
        ->and($policy->viewServices(principal('admin2', Role::Admin)))->toBeFalse();
});

it('gives System Protection to the ROOT_USER only', function () {
    $policy = serverPolicy();

    expect($policy->protectAdmin(principal('arcadmin', Role::Admin)))->toBeTrue()
        ->and($policy->protectAdmin(principal('admin2', Role::Admin)))->toBeFalse()
        ->and($policy->protectAdmin(principal('arcadmin', Role::Admin, readOnly: true)))->toBeFalse();
});

it('lets every role search and generate a CSR for domains it manages', function () {
    $search = new SearchPolicy;
    expect($search->use(principal('bob', Role::User)))->toBeTrue()
        ->and($search->use(principal('hostco', Role::Reseller)))->toBeTrue();

    $web = fn (Principal $principal) => new WebDomainPolicy(
        new OwnerScope($principal, new TenancyDirectory(app(ArcCommand::class))), app(SysConfig::class));
    $hostco = principal('hostco', Role::Reseller);
    $bob = principal('bob', Role::User);
    expect($web($hostco)->generateCsr($hostco, 'bob'))->toBeTrue()
        ->and($web($hostco)->generateCsr($hostco, 'carol'))->toBeFalse()
        ->and($web($bob)->generateCsr($bob, 'bob'))->toBeTrue()
        ->and($web($bob)->generateCsr($bob, 'carol'))->toBeFalse();
    $readOnly = principal('bob', Role::User, readOnly: true);
    expect($web($readOnly)->generateCsr($readOnly, 'bob'))->toBeFalse();
});

it('links the batch 3 server pages for administrators only', function () {
    $items = fn (Principal $principal) => navItems($principal);

    expect($items(principal('arcadmin', Role::Admin)))->toHaveKeys(['server.settings', 'server.services', 'server.updates']);
    foreach ([principal('hostco', Role::Reseller), principal('bob', Role::User)] as $other) {
        expect($items($other))->not->toHaveKeys(['server.settings', 'server.services', 'server.updates']);
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_HIDE_SERVICES' => 'yes']);
    expect($items(principal('admin2', Role::Admin)))->not->toHaveKey('server.services')
        ->and($items(principal('arcadmin', Role::Admin)))->toHaveKey('server.services');
});
