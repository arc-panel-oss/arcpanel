<?php

use App\Arc\ArcCommand;
use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\ImpersonationPolicy;
use App\Policies\Scope;
use App\Tenancy\TenancyDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
});

function tenancy(): TenancyDirectory
{
    return new TenancyDirectory(app(ArcCommand::class));
}

function scopeOf(Principal $principal): OwnerScope
{
    return new OwnerScope($principal, tenancy());
}

it('grants each role its capabilities from the matrix', function () {
    expect(Capabilities::scopeOf(Role::Admin, 'web.view'))->toBe(Scope::All)
        ->and(Capabilities::scopeOf(Role::Reseller, 'web.view'))->toBe(Scope::Tree)
        ->and(Capabilities::scopeOf(Role::User, 'web.view'))->toBe(Scope::Self)
        ->and(Capabilities::scopeOf(Role::Reseller, 'users.view'))->toBe(Scope::Tree)
        ->and(Capabilities::scopeOf(Role::Reseller, 'dns.view'))->toBe(Scope::Tree)
        ->and(Capabilities::scopeOf(Role::Reseller, 'logs.view'))->toBe(Scope::Self)
        ->and(Capabilities::allows(Role::Admin, 'server.view'))->toBeTrue()
        ->and(Capabilities::allows(Role::User, 'server.view'))->toBeFalse()
        ->and(Capabilities::allows(Role::User, 'users.view'))->toBeFalse();
});

it('keeps server, role and owner changes for administrators', function () {
    foreach (['server.view', 'users.change-owner', 'users.change-role', 'users.admin-fields'] as $capability) {
        expect(Capabilities::allows(Role::Admin, $capability))->toBeTrue()
            ->and(Capabilities::allows(Role::Reseller, $capability))->toBeFalse()
            ->and(Capabilities::allows(Role::User, $capability))->toBeFalse();
    }
});

it('denies unknown capabilities and every capability of DNS cluster users', function () {
    expect(Capabilities::allows(Role::Admin, 'web.unknown'))->toBeFalse();
    foreach (Capabilities::names() as $capability) {
        expect(Capabilities::allows(Role::DnsCluster, $capability))->toBeFalse();
    }
});

it('applies the scope check only after the capability', function () {
    expect(Capabilities::allows(Role::User, 'web.update', fn () => false))->toBeFalse()
        ->and(Capabilities::allows(Role::User, 'web.update', fn () => true))->toBeTrue()
        ->and(Capabilities::allows(Role::User, 'server.view', fn () => true))->toBeFalse();
});

it('lets an admin see every user and manage every other account', function () {
    $scope = scopeOf(principal('arcadmin', Role::Admin));

    expect($scope->visibleUsers())->toBeNull()
        ->and($scope->canSee('bob'))->toBeTrue()
        ->and($scope->canManage('carol'))->toBeTrue()
        ->and($scope->canManageAccount('hostco'))->toBeTrue()
        ->and($scope->canManageAccount('arcadmin'))->toBeFalse()
        ->and($scope->canManageAccount('ghost'))->toBeFalse()
        ->and($scope->canSee(''))->toBeFalse();
});

it('limits a user, and an admin acting as a user, to the effective user', function () {
    $user = scopeOf(principal('bob', Role::User));
    $impersonating = scopeOf(principal('arcadmin', Role::Admin, look: 'bob'));

    foreach ([$user, $impersonating] as $scope) {
        expect($scope->visibleUsers())->toBe(['bob'])
            ->and($scope->canSee('bob'))->toBeTrue()
            ->and($scope->canSee('carol'))->toBeFalse()
            ->and($scope->canManageAccount('bob'))->toBeFalse();
    }
});

it('limits a reseller to itself and its customers', function () {
    $hostco = scopeOf(principal('hostco', Role::Reseller));
    $resb = scopeOf(principal('resb', Role::Reseller));

    expect($hostco->visibleUsers())->toBe(['hostco', 'bob'])
        ->and($hostco->canSee('bob'))->toBeTrue()
        ->and($hostco->canSee('carol'))->toBeFalse()
        ->and($hostco->canSee('resb'))->toBeFalse()
        ->and($hostco->canManage('hostco'))->toBeTrue()
        ->and($hostco->canManageAccount('bob'))->toBeTrue()
        ->and($hostco->canManageAccount('hostco'))->toBeFalse()
        ->and($hostco->canManageAccount('carol'))->toBeFalse()
        ->and($resb->visibleUsers())->toBe(['resb', 'carol', 'dave'])
        ->and($resb->tree('resb'))->toBe(['resb', 'carol', 'dave']);
});

it('limits a reseller acting as a customer to that customer', function () {
    $scope = scopeOf(principal('hostco', Role::Reseller, look: 'bob', effective: Role::User));

    expect($scope->visibleUsers())->toBe(['bob'])
        ->and($scope->canSee('hostco'))->toBeFalse();
});

it('answers objects outside the scope with 404', function () {
    scopeOf(principal('hostco', Role::Reseller))->assertVisible('carol');
})->throws(NotFoundHttpException::class);

it('resolves owners like the command', function () {
    $tenancy = tenancy();

    expect($tenancy->ownerOf('bob'))->toBe('hostco')
        ->and($tenancy->ownerOf('hostco'))->toBe('arcadmin')
        ->and($tenancy->ownerOf('arcadmin'))->toBe('')
        ->and($tenancy->customersOf('resb'))->toBe(['carol', 'dave'])
        ->and($tenancy->customersOf('bob'))->toBe([])
        ->and($tenancy->resellers())->toBe(['hostco', 'resb']);
    $this->fake->assertRan('v-list-user-owners', times: 1);
});

it('lets resellers log in as their own customers only', function () {
    $policy = new ImpersonationPolicy(tenancy(), app(SysConfig::class));

    expect($policy->start(principal('hostco', Role::Reseller), 'bob'))->toBeTrue()
        ->and($policy->start(principal('hostco', Role::Reseller), 'carol'))->toBeFalse()
        ->and($policy->start(principal('hostco', Role::Reseller), 'resb'))->toBeFalse()
        ->and($policy->start(principal('hostco', Role::Reseller), 'arcadmin'))->toBeFalse()
        ->and($policy->start(principal('arcadmin', Role::Admin), 'carol'))->toBeTrue()
        ->and($policy->start(principal('arcadmin', Role::Admin), 'arcadmin'))->toBeFalse()
        ->and($policy->start(principal('bob', Role::User), 'carol'))->toBeFalse();
});

it('follows POLICY_RESELLER_LOGIN_AS', function () {
    $this->fake = fakeArc(['POLICY_RESELLER_LOGIN_AS' => 'no']);
    fakeResellers($this->fake);
    $policy = new ImpersonationPolicy(tenancy(), app(SysConfig::class));

    expect($policy->start(principal('hostco', Role::Reseller), 'bob'))->toBeFalse()
        ->and($policy->start(principal('arcadmin', Role::Admin), 'bob'))->toBeTrue();
});
