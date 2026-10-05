<?php

use App\Arc\ArcCommand;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\Capabilities;
use App\Policies\NodePolicy;
use App\Policies\Scope;
use App\Services\Node\NodeAccess;
use App\Services\Node\NodeApps;
use App\Services\Node\NodeAppService;
use App\Tenancy\TenancyDirectory;

// NodePolicy app abilities, the app records and NodeAppService (E14 spec
// §6): hostco owns bob (developer package, Node.js), resb owns carol
// (default package, no Node.js).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'developer']]);
    $this->fake->on('v-list-package-tenancy', ['developer', 'json'])->returnsJson(['developer' => ['NODE' => 'yes']]);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['NODE' => 'no']]);
});

function nodeAppPolicy(Principal $principal): NodePolicy
{
    return new NodePolicy(new OwnerScope($principal, new TenancyDirectory(app(ArcCommand::class))), new NodeAccess(app(ArcCommand::class)));
}

it('gives every role Node.js apps on the accounts it can see', function () {
    foreach ([[Role::Admin, Scope::All], [Role::Reseller, Scope::Tree], [Role::User, Scope::Self]] as [$role, $scope]) {
        expect(Capabilities::scopeOf($role, 'node.apps.view'))->toBe($scope)
            ->and(Capabilities::scopeOf($role, 'node.apps.manage'))->toBe($scope);
    }

    $bob = principal('bob', Role::User);
    $hostco = principal('hostco', Role::Reseller);
    $resb = principal('resb', Role::Reseller);
    $admin = principal('arcadmin', Role::Admin);

    expect(nodeAppPolicy($bob)->viewApps($bob, 'bob'))->toBeTrue()
        ->and(nodeAppPolicy($bob)->viewApps($bob, 'carol'))->toBeFalse()
        ->and(nodeAppPolicy($hostco)->manageApps($hostco, 'bob'))->toBeTrue()
        ->and(nodeAppPolicy($resb)->manageApps($resb, 'bob'))->toBeFalse()
        ->and(nodeAppPolicy($resb)->removeApps($resb, 'bob'))->toBeFalse()
        ->and(nodeAppPolicy($resb)->removeApps($resb, 'carol'))->toBeTrue()
        ->and(nodeAppPolicy($admin)->manageApps($admin, 'bob'))->toBeTrue();
});

it('needs NODE=yes to create and change apps but not to stop or delete them', function () {
    $carol = principal('carol', Role::User);
    $admin = principal('arcadmin', Role::Admin);

    expect(nodeAppPolicy($carol)->appAbilities($carol, 'carol'))->toBe(['manage' => false, 'remove' => true])
        ->and(nodeAppPolicy($admin)->manageApps($admin, 'carol'))->toBeFalse();
});

it('allows no app changes in read-only mode', function () {
    $bob = principal('bob', Role::User, readOnly: true);

    expect(nodeAppPolicy($bob)->viewApps($bob, 'bob'))->toBeTrue()
        ->and(nodeAppPolicy($bob)->appAbilities($bob, 'bob'))->toBe(['manage' => false, 'remove' => false]);
});

it('parses the apps with their totals and the room left', function () {
    $apps = NodeApps::from(nodeAppList('bob', [
        nodeApp('a.example'),
        nodeApp('b.example', ['STATUS' => 'stopped', 'STATE' => 'stopped']),
        nodeApp('c.example', ['STATUS' => 'errored', 'REASON' => 'runtime missing', 'RUNTIME' => '']),
    ], ['LIMIT' => '3']));

    expect($apps->hasRoom())->toBeFalse()
        ->and($apps->totals())->toBe(['online' => 1, 'stopped' => 1, 'errored' => 1, 'suspended' => 0])
        ->and($apps->find('a.example')?->online())->toBeTrue()
        ->and($apps->find('c.example')?->reason)->toBe('runtime missing')
        ->and($apps->find('nope.example'))->toBeNull()
        ->and(NodeApps::from(nodeAppList('bob', [nodeApp('a.example', ['SUSPENDED' => 'user'])], ['LIMIT' => 'unlimited']))->totals()['suspended'])->toBe(1)
        ->and(NodeApps::from(nodeAppList('bob', [], ['LIMIT' => 'unlimited']))->hasRoom())->toBeTrue();

    $app = $apps->find('a.example')->toArray();
    expect($app['instances'])->toBe(1)->and($app['memoryMb'])->toBe(48)->and($app['url'])->toBe('http://a.example/')
        ->and(NodeApps::from(nodeAppList('bob', [nodeApp('n.example', ['START' => 'npm:start'])]))->apps[0]->script())->toBe('start');
});

it('proposes the start command and the template from package.json', function () {
    expect(NodeAppService::proposal(null))->toBe(['packageJson' => false, 'start' => 'server.js', 'template' => 'nodejs-proxy', 'scripts' => []])
        ->and(NodeAppService::proposal(['scripts' => ['start' => 'next start', 'build' => 'next build'], 'dependencies' => ['next' => '15']]))
        ->toBe(['packageJson' => true, 'start' => 'npm:start', 'template' => 'nextjs', 'scripts' => ['start', 'build']])
        ->and(NodeAppService::proposal(['main' => './dist/index.js', 'dependencies' => ['socket.io' => '4']])['start'])->toBe('dist/index.js')
        ->and(NodeAppService::proposal(['main' => '../outside.js'])['start'])->toBe('server.js')
        ->and(NodeAppService::proposal(['devDependencies' => ['nuxt' => '3']])['template'])->toBe('nuxt')
        ->and(NodeAppService::proposal(['dependencies' => ['ws' => '8']])['template'])->toBe('nodejs-proxy-ws')
        ->and(NodeAppService::proposal(['scripts' => ['rm -rf' => 'x', 'dev' => 'x']])['scripts'])->toBe(['dev']);
});

it('validates names, paths and environment keys like func/nodeapp.sh', function () {
    foreach (['api', 'shop.example.com', 'a-b_c'] as $name) {
        expect(preg_match(NodeAppService::NAME, $name))->toBe(1, $name);
    }
    foreach (['Api', '-x', 'a b', str_repeat('a', 41)] as $name) {
        expect(preg_match(NodeAppService::NAME, $name))->toBe(0, $name);
    }
    foreach (['.', 'public_html', 'app/server', 'dist/@scope'] as $path) {
        expect(preg_match(NodeAppService::PATH, $path))->toBe(1, $path);
    }
    foreach (['/etc', '../x', 'a b', '.hidden'] as $path) {
        expect(preg_match(NodeAppService::PATH, $path))->toBe(0, $path);
    }
    expect(preg_match(NodeAppService::ENV_KEY, 'API_KEY'))->toBe(1)
        ->and(preg_match(NodeAppService::ENV_KEY, '1KEY'))->toBe(0)
        ->and(NodeAppService::RESERVED_ENV)->toContain('PORT');
});
