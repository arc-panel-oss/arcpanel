<?php

use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\ProtectedAdmin;
use App\Auth\Role;
use App\Policies\AccessKeyPolicy;
use App\Policies\BackupPolicy;
use App\Policies\CronPolicy;
use App\Policies\DatabasePolicy;
use App\Policies\DnsPolicy;
use App\Policies\DockerPolicy;
use App\Policies\FilePolicy;
use App\Policies\LogPolicy;
use App\Policies\MailPolicy;
use App\Policies\MalwarePolicy;
use App\Policies\NodePolicy;
use App\Policies\PortPolicy;
use App\Policies\SshKeyPolicy;
use App\Policies\UserPolicy;
use App\Policies\UserSecretPolicy;
use App\Policies\WebDomainPolicy;
use App\Services\Ports\PortEntry;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// POLICY_SYSTEM_PROTECTED_ADMIN (E39 W4 spec §5): arcadmin is the ROOT_USER,
// otheradmin a second administrator.

beforeEach(function () {
    $this->fake = fakeArc(['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes', 'API_SYSTEM' => '2']);
    fakeResellers($this->fake, ['otheradmin' => ['ROLE' => 'admin']]);
});

/** Server configuration from now on, with a fresh guard. */
function protectedConfig(array $config): void
{
    withSysConfig(test()->fake, $config);
    app()->forgetInstance(ProtectedAdmin::class);
}

/**
 * The policy as the container builds it for $principal.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return T
 */
function policyFor(string $class, Principal $principal): object
{
    app()->instance(Principal::class, $principal);
    app()->forgetInstance(SysConfig::class);

    return app($class);
}

it('blocks changes of other administrators to the root administrator only', function () {
    $guard = app(ProtectedAdmin::class);
    $other = principal('otheradmin', Role::Admin);

    expect($guard->blocks($other, 'arcadmin'))->toBeTrue()
        ->and($guard->blocks($other, 'bob'))->toBeFalse()
        ->and($guard->blocks($other, ''))->toBeFalse()
        ->and($guard->blocks(principal('arcadmin', Role::Admin), 'arcadmin'))->toBeFalse()
        // The login account decides, not the impersonated one
        ->and($guard->blocks(principal('otheradmin', Role::Admin, look: 'arcadmin', effective: Role::Admin), 'arcadmin'))->toBeTrue()
        ->and($guard->blocks(principal('arcadmin', Role::Admin, look: 'bob'), 'arcadmin'))->toBeFalse();

    protectedConfig(['POLICY_SYSTEM_PROTECTED_ADMIN' => 'no']);
    expect(app(ProtectedAdmin::class)->blocks($other, 'arcadmin'))->toBeFalse();
});

it('keeps the reach of a scope for reading and drops the root administrator for changes', function () {
    app()->instance(Principal::class, principal('otheradmin', Role::Admin));
    $scope = app(OwnerScope::class);

    expect($scope->canSee('arcadmin'))->toBeTrue()
        ->and($scope->canSeeAccount('arcadmin'))->toBeTrue()
        ->and($scope->canManage('arcadmin'))->toBeFalse()
        ->and($scope->canManageAccount('arcadmin'))->toBeFalse()
        ->and($scope->canManage('bob'))->toBeTrue()
        ->and($scope->canManageAccount('bob'))->toBeTrue();
});

it('lets a secondary administrator read and never change the objects of the root administrator', function (string $class, array $reads, array $changes) {
    $other = principal('otheradmin', Role::Admin);
    $policy = policyFor($class, $other);
    foreach ($reads as $method) {
        expect($policy->{$method}($other, 'arcadmin'))->toBeTrue("{$method} reads");
    }
    foreach ($changes as $method) {
        expect($policy->{$method}($other, 'arcadmin'))->toBeFalse("{$method} is refused");
    }

    // The refusal is the guard's: with the policy off, or for the root
    // administrator, the same changes are allowed.
    $root = principal('arcadmin', Role::Admin);
    $asRoot = array_map(fn (string $method) => policyFor($class, $root)->{$method}($root, 'arcadmin'), $changes);
    protectedConfig(['POLICY_SYSTEM_PROTECTED_ADMIN' => 'no', 'API_SYSTEM' => '2']);
    $policyOff = array_map(fn (string $method) => policyFor($class, $other)->{$method}($other, 'arcadmin'), $changes);
    expect($policyOff)->toBe($asRoot)->and($asRoot)->toContain(true);
})->with([
    'access keys' => [AccessKeyPolicy::class, ['view'], ['manage']],
    'SSH keys' => [SshKeyPolicy::class, ['view'], ['manage']],
    'files' => [FilePolicy::class, ['view'], ['manage']],
    'cron' => [CronPolicy::class, ['view'], ['create', 'update', 'delete', 'suspend', 'reports']],
    'web domains' => [WebDomainPolicy::class, ['view', 'viewPhp'], ['create', 'update', 'updateTemplates', 'changePhp', 'installApp', 'generateCsr', 'delete', 'suspend', 'rebuild']],
    'DNS' => [DnsPolicy::class, ['view'], ['create', 'update', 'updateTemplates', 'delete', 'suspend', 'rebuild']],
    'mail' => [MailPolicy::class, ['view'], ['create', 'update', 'updateRateLimit', 'delete', 'suspend', 'rebuild']],
    'databases' => [DatabasePolicy::class, ['view'], ['create', 'update', 'delete', 'suspend', 'rebuild']],
    'backups' => [BackupPolicy::class, ['view', 'download'], ['create', 'restore', 'delete', 'editExclusions']],
    'Node.js' => [NodePolicy::class, ['view', 'viewApps'], ['remove', 'removeApps']],
    'Docker apps' => [DockerPolicy::class, ['viewApps'], ['removeApps']],
    'secrets' => [UserSecretPolicy::class, ['view'], ['manage']],
    'malware' => [MalwarePolicy::class, ['view'], ['manage']],
    'logs' => [LogPolicy::class, ['view'], ['delete']],
    'ports' => [PortPolicy::class, ['view'], ['reserve', 'give']],
]);

it('keeps the root administrator\'s account to itself', function () {
    $other = principal('otheradmin', Role::Admin);
    $policy = policyFor(UserPolicy::class, $other);

    expect($policy->view($other, 'arcadmin'))->toBeTrue();
    foreach (['update', 'changePackage', 'changeNameservers', 'manageSecurity', 'administer', 'changeRole', 'changeOwner', 'suspend', 'delete'] as $method) {
        expect($policy->{$method}($other, 'arcadmin'))->toBeFalse("{$method} is refused");
    }
    expect($policy->update($other, 'bob'))->toBeTrue();

    $root = principal('arcadmin', Role::Admin);
    expect(policyFor(UserPolicy::class, $root)->update($root, 'arcadmin'))->toBeTrue()
        ->and(policyFor(UserPolicy::class, $root)->administer($root, 'arcadmin'))->toBeTrue();
});

it('offers no permission sets for keys of the root administrator to other administrators', function () {
    $sets = ['billing' => ['ROLE' => 'admin'], 'purge-nginx-cache' => ['ROLE' => 'user']];
    $other = principal('otheradmin', Role::Admin);
    $root = principal('arcadmin', Role::Admin);

    expect(policyFor(AccessKeyPolicy::class, $other)->allowedSets('arcadmin', $sets))->toBe([])
        ->and(policyFor(AccessKeyPolicy::class, $other)->allowedSets('bob', $sets))->toBe(['purge-nginx-cache'])
        ->and(policyFor(AccessKeyPolicy::class, $root)->allowedSets('arcadmin', $sets))->toBe(['billing', 'purge-nginx-cache']);
});

it('opens no port of the root administrator for other administrators', function () {
    $other = principal('otheradmin', Role::Admin);
    $entry = PortEntry::from(portEntry('30005', ['APP' => 'web', 'PUBLIC_OK' => 'yes']), 'arcadmin');
    $policy = policyFor(PortPolicy::class, $other);

    expect($policy->setPublic($other, 'arcadmin', $entry, true))->toBeFalse()
        ->and($policy->setPublicOk($other, 'arcadmin', true))->toBeFalse()
        ->and($policy->setPublicOk($other, 'bob', true))->toBeTrue();
});

it('answers 404 for a refusal under POLICY_SYSTEM_HIDE_ADMIN once the guard blocked', function () {
    $forbidden = new HttpException(403);
    $guard = app(ProtectedAdmin::class);
    expect($guard->hide($forbidden))->toBe($forbidden);

    $guard->blocks(principal('otheradmin', Role::Admin), 'arcadmin');
    expect($guard->hide($forbidden))->toBe($forbidden);

    protectedConfig(['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes', 'POLICY_SYSTEM_HIDE_ADMIN' => 'yes']);
    $guard = app(ProtectedAdmin::class);
    expect($guard->hide($forbidden))->toBe($forbidden);
    $guard->blocks(principal('otheradmin', Role::Admin), 'arcadmin');
    expect($guard->hide($forbidden))->toBeInstanceOf(NotFoundHttpException::class)
        ->and($guard->hide($notFound = new NotFoundHttpException))->toBe($notFound);
});

/*
 * §5 Completeness: every policy method that is not a read refuses a
 * secondary administrator on the root administrator. A new method without
 * the guard fails here; a method this test cannot call fails too, so its
 * arguments are added below.
 */
it('guards every change method of every policy', function () {
    // Reads that are not named view*, list* or see*: a download changes nothing.
    $reads = ['download'];
    $other = principal('otheradmin', Role::Admin);
    $checked = 0;

    foreach (glob(app_path('Policies/*Policy.php')) as $file) {
        $class = 'App\\Policies\\'.basename($file, '.php');
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $params = $method->getParameters();
            $names = array_map(fn (ReflectionParameter $p) => $p->getName(), $params);
            if ($method->isStatic() || $method->isConstructor() || preg_match('/^(view|list|see)/', $name) === 1
                || in_array($name, $reads, true) || (string) $method->getReturnType() !== 'bool'
                || array_intersect($names, ['owner', 'user']) === []
                || ! in_array(Principal::class, array_map(fn (ReflectionParameter $p) => (string) $p->getType(), $params), true)) {
                continue;
            }

            $args = array_map(fn (ReflectionParameter $p) => match (true) {
                (string) $p->getType() === Principal::class => $other,
                in_array($p->getName(), ['owner', 'user'], true) => 'arcadmin',
                $p->getName() === 'action' => 'delete',
                (string) $p->getType() === 'bool' => true,
                (string) $p->getType() === PortEntry::class => PortEntry::from(portEntry('30005', ['APP' => 'web', 'PUBLIC_OK' => 'yes']), 'arcadmin'),
                default => throw new LogicException("Add an argument for {$class}::{$name}(\${$p->getName()})"),
            }, $params);

            expect(policyFor($class, $other)->{$name}(...$args))->toBeFalse("{$class}::{$name} refuses the root administrator's objects");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(50);
});
