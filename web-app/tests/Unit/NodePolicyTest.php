<?php

use App\Arc\ArcCommand;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\NodePolicy;
use App\Policies\Scope;
use App\Services\Node\NodeAccess;
use App\Services\Node\NodeAccount;
use App\Services\Node\NodeService;
use App\Tenancy\TenancyDirectory;

// NodePolicy and NodeService (E13 spec §6): hostco owns bob (developer
// package, Node.js), resb owns carol (default package, no Node.js).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
});

function nodePolicy(Principal $principal): NodePolicy
{
    $tenancy = new TenancyDirectory(app(ArcCommand::class));

    return new NodePolicy(new OwnerScope($principal, $tenancy), new NodeAccess(app(ArcCommand::class)));
}

it('gives every role Node.js on the accounts it can see', function () {
    foreach ([[Role::Admin, Scope::All], [Role::Reseller, Scope::Tree], [Role::User, Scope::Self]] as [$role, $scope]) {
        expect(Capabilities::scopeOf($role, 'node.view'))->toBe($scope)
            ->and(Capabilities::scopeOf($role, 'node.manage'))->toBe($scope);
    }

    $bob = principal('bob', Role::User);
    $hostco = principal('hostco', Role::Reseller);
    $resb = principal('resb', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);

    expect(nodePolicy($bob)->view($bob, 'bob'))->toBeTrue()
        ->and(nodePolicy($bob)->view($bob, 'carol'))->toBeFalse()
        ->and(nodePolicy($hostco)->manage($hostco, 'bob'))->toBeTrue()
        ->and(nodePolicy($resb)->manage($resb, 'bob'))->toBeFalse()
        ->and(nodePolicy($resb)->remove($resb, 'bob'))->toBeFalse()
        ->and(nodePolicy($admin)->manage($admin, 'bob'))->toBeTrue();
});

it('needs NODE=yes to install but not to remove', function () {
    $carol = principal('carol', Role::User);
    $admin = principal('arcadmin', Role::Admin);

    expect(nodePolicy($carol)->manage($carol, 'carol'))->toBeFalse()
        ->and(nodePolicy($carol)->remove($carol, 'carol'))->toBeTrue()
        ->and(nodePolicy($admin)->manage($admin, 'carol'))->toBeFalse()
        ->and(nodePolicy($carol)->abilities($carol, 'carol'))->toBe(['manage' => false, 'remove' => true]);
});

it('allows nothing in read-only mode', function () {
    $bob = principal('bob', Role::User, readOnly: true);

    expect(nodePolicy($bob)->view($bob, 'bob'))->toBeTrue()
        ->and(nodePolicy($bob)->abilities($bob, 'bob'))->toBe(['manage' => false, 'remove' => false]);
});

it('reads the package key once per package', function () {
    $access = new NodeAccess(app(ArcCommand::class));

    expect($access->allowed('bob'))->toBeTrue()
        ->and($access->allowed('bob'))->toBeTrue()
        ->and($access->allowed('carol'))->toBeFalse()
        ->and($access->allowed('nobody'))->toBeFalse();
    $this->fake->assertRan('v-list-package-tenancy', fn ($a) => $a === ['developer', 'json'], 1);
});

it('applies NODE_ALLOWED as the commands do', function () {
    expect(NodeService::majorAllowed('lts', '24', 'active'))->toBeTrue()
        ->and(NodeService::majorAllowed('lts', '22', 'maintenance'))->toBeTrue()
        ->and(NodeService::majorAllowed('lts', '26', 'current'))->toBeFalse()
        ->and(NodeService::majorAllowed('lts', '20', 'eol'))->toBeFalse()
        ->and(NodeService::majorAllowed('*', '18', 'eol'))->toBeTrue()
        ->and(NodeService::majorAllowed('20,22', '20', 'eol'))->toBeTrue()
        ->and(NodeService::majorAllowed('20,22', '24', 'active'))->toBeFalse();
});

it('validates specs and package names like func/node.sh', function () {
    foreach (['lts', '24', '24.3.0'] as $spec) {
        expect(preg_match(NodeService::SPEC, $spec))->toBe(1);
    }
    foreach (['lts/*', 'v24', '24.3', 'latest', '24;id'] as $spec) {
        expect(preg_match(NodeService::SPEC, $spec))->toBe(0);
    }
    foreach (['pm2', 'yarn@1.22.22', '@nestjs/cli', '@nestjs/cli@^11', 'is-number@>=6', 'next@canary'] as $spec) {
        expect(preg_match(NodeService::PACKAGE_SPEC, $spec))->toBe(1, $spec);
    }
    foreach (['https://example.invalid/x.tgz', 'git+ssh://x', 'file:x', '../x', 'a b', 'UPPER', '@scope', 'x@', '.hidden'] as $spec) {
        expect(preg_match(NodeService::PACKAGE_SPEC, $spec))->toBe(0, $spec);
    }
});

it('parses the account and knows when the package is full', function () {
    $account = NodeAccount::from(nodeAccount('bob', ['LIMIT' => '2'], [nodeRuntime('24.21.0', ['DEFAULT' => 'yes']), nodeRuntime('22.23.3')]));

    expect($account->allowed)->toBeTrue()
        ->and($account->hasRoom())->toBeFalse()
        ->and($account->has('22.23.3'))->toBeTrue()
        ->and($account->runtimes[0]->default)->toBeTrue()
        ->and($account->runtimes[0]->sizeMb)->toBe(208)
        ->and(NodeAccount::from(nodeAccount('bob', ['LIMIT' => 'unlimited']))->hasRoom())->toBeTrue();
});

it('runs the commands with the arguments of the spec', function () {
    $this->fake->on('v-start-user-node-task')->returns("17286400001234\n");
    $this->fake->on('v-delete-user-npm-package')->returns();
    $service = app(NodeService::class);

    expect($service->startTask('bob', 'add-package', ['24.21.0', 'pm2']))->toBe('17286400001234');
    $this->fake->assertRan('v-start-user-node-task', fn ($a) => $a === ['bob', 'add-package', '24.21.0', 'pm2']);
    $service->deletePackage('bob', '24.21.0', '@nestjs/cli');
    $this->fake->assertRan('v-delete-user-npm-package', fn ($a) => $a === ['bob', '24.21.0', '@nestjs/cli']);
});
