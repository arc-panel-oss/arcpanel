<?php

use App\Auth\OwnerScope;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\Scope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('grants admin and user their capabilities from the matrix', function () {
    expect(Capabilities::scopeOf(Role::Admin, 'web.view'))->toBe(Scope::All)
        ->and(Capabilities::scopeOf(Role::User, 'web.view'))->toBe(Scope::Self)
        ->and(Capabilities::allows(Role::Admin, 'server.view'))->toBeTrue()
        ->and(Capabilities::allows(Role::User, 'server.view'))->toBeFalse()
        ->and(Capabilities::allows(Role::User, 'users.view'))->toBeFalse();
});

it('denies unknown capabilities and every capability of resellers and DNS cluster users', function () {
    expect(Capabilities::allows(Role::Admin, 'web.unknown'))->toBeFalse();
    foreach (Capabilities::names() as $capability) {
        expect(Capabilities::allows(Role::Reseller, $capability))->toBeFalse()
            ->and(Capabilities::allows(Role::DnsCluster, $capability))->toBeFalse();
    }
});

it('applies the scope check only after the capability', function () {
    expect(Capabilities::allows(Role::User, 'web.update', fn () => false))->toBeFalse()
        ->and(Capabilities::allows(Role::User, 'web.update', fn () => true))->toBeTrue()
        ->and(Capabilities::allows(Role::User, 'server.view', fn () => true))->toBeFalse();
});

it('lets an admin see every user', function () {
    $scope = new OwnerScope(principal('arcadmin', Role::Admin));

    expect($scope->visibleUsers())->toBeNull()
        ->and($scope->canSee('bob'))->toBeTrue()
        ->and($scope->canManage('carol'))->toBeTrue()
        ->and($scope->canSee(''))->toBeFalse();
});

it('limits a user, and an admin acting as a user, to the effective user', function () {
    $user = new OwnerScope(principal('bob', Role::User));
    $impersonating = new OwnerScope(principal('arcadmin', Role::Admin, look: 'bob'));

    foreach ([$user, $impersonating] as $scope) {
        expect($scope->visibleUsers())->toBe(['bob'])
            ->and($scope->canSee('bob'))->toBeTrue()
            ->and($scope->canSee('carol'))->toBeFalse();
    }
});

it('answers objects outside the scope with 404', function () {
    (new OwnerScope(principal('bob', Role::User)))->assertVisible('carol');
})->throws(NotFoundHttpException::class);

it('has no owner tree before E11', function () {
    (new OwnerScope(principal('arcadmin', Role::Admin)))->tree('hostco');
})->throws(LogicException::class);
