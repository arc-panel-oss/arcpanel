<?php

use App\Arc\ArcCommand;
use App\Arc\SysConfig;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Policies\AccessKeyPolicy;
use App\Policies\Capabilities;
use App\Policies\FirewallPolicy;
use App\Policies\IpPolicy;
use App\Policies\LogPolicy;
use App\Policies\Scope;
use App\Policies\SshKeyPolicy;
use App\Policies\StatsPolicy;
use App\Policies\TerminalPolicy;
use App\Policies\WebDomainPolicy;
use App\Tenancy\TenancyDirectory;

// E7 batch 2 spec §5: hostco owns bob, resb owns carol and dave.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
});

/**
 * @template T
 *
 * @param  class-string<T>  $class
 * @return T
 */
function batch2Policy(string $class, Principal $principal): object
{
    $scope = new OwnerScope($principal, new TenancyDirectory(app(ArcCommand::class)));
    app()->forgetInstance(SysConfig::class);

    return match ($class) {
        FirewallPolicy::class, IpPolicy::class => new $class,
        StatsPolicy::class, SshKeyPolicy::class => new $class($scope),
        TerminalPolicy::class => new $class(app(SysConfig::class)),
        default => new $class($scope, app(SysConfig::class)),
    };
}

it('adds the batch 2 capabilities with their scopes', function () {
    foreach (['server.firewall', 'server.ips', 'server.rrd'] as $capability) {
        expect(Capabilities::scopeOf(Role::Admin, $capability))->toBe(Scope::All)
            ->and(Capabilities::allows(Role::Reseller, $capability))->toBeFalse()
            ->and(Capabilities::allows(Role::User, $capability))->toBeFalse();
    }
    foreach (['logs.view', 'logs.delete', 'stats.view', 'terminal.use', 'web.apps.install'] as $capability) {
        expect(Capabilities::scopeOf(Role::Admin, $capability))->toBe(Scope::All)
            ->and(Capabilities::scopeOf(Role::Reseller, $capability))->toBe(Scope::Tree)
            ->and(Capabilities::scopeOf(Role::User, $capability))->toBe(Scope::Self);
    }
    foreach (['access-keys.view', 'access-keys.manage', 'ssh-keys.view', 'ssh-keys.manage'] as $capability) {
        expect(Capabilities::scopeOf(Role::Admin, $capability))->toBe(Scope::All)
            ->and(Capabilities::scopeOf(Role::Reseller, $capability))->toBe(Scope::Self)
            ->and(Capabilities::scopeOf(Role::User, $capability))->toBe(Scope::Self);
    }
});

it('keeps the firewall and the IPs for administrators and locks managed rules', function () {
    $admin = principal('arcadmin', Role::Admin);
    $hostco = principal('hostco', Role::Reseller);

    expect(batch2Policy(FirewallPolicy::class, $admin)->manage($admin))->toBeTrue()
        ->and(batch2Policy(FirewallPolicy::class, $admin)->change($admin, 'ARCPANEL'))->toBeTrue()
        ->and(batch2Policy(FirewallPolicy::class, $admin)->change($admin, 'arcpanel-port-30005-bob'))->toBeFalse()
        ->and(FirewallPolicy::isManaged('arcpanel-port-30005-bob'))->toBeTrue()
        ->and(FirewallPolicy::isManaged('arcpanel-port-x-bob'))->toBeFalse()
        ->and(batch2Policy(FirewallPolicy::class, $hostco)->viewAny($hostco))->toBeFalse()
        ->and(batch2Policy(IpPolicy::class, $admin)->manage($admin))->toBeTrue()
        ->and(batch2Policy(IpPolicy::class, $hostco)->viewAny($hostco))->toBeFalse();

    $readOnly = principal('arcadmin', Role::Admin, readOnly: true);
    expect(batch2Policy(FirewallPolicy::class, $readOnly)->viewAny($readOnly))->toBeTrue()
        ->and(batch2Policy(FirewallPolicy::class, $readOnly)->manage($readOnly))->toBeFalse()
        ->and(batch2Policy(IpPolicy::class, $readOnly)->manage($readOnly))->toBeFalse();
});

it('lets resellers read the logs of their tree and delete only their own', function () {
    $admin = principal('arcadmin', Role::Admin);
    $hostco = principal('hostco', Role::Reseller);
    $bob = principal('bob', Role::User);

    expect(batch2Policy(LogPolicy::class, $admin)->view($admin, 'carol'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $admin)->delete($admin, 'carol'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $hostco)->view($hostco, 'bob'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $hostco)->delete($hostco, 'bob'))->toBeFalse()
        ->and(batch2Policy(LogPolicy::class, $hostco)->delete($hostco, 'hostco'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $hostco)->view($hostco, 'carol'))->toBeFalse()
        ->and(batch2Policy(LogPolicy::class, $bob)->view($bob, 'bob'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $bob)->delete($bob, 'bob'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $bob)->view($bob, 'carol'))->toBeFalse();
});

it('applies the log policies of arcpanel.conf to everyone but administrators', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_LOGS' => 'no', 'POLICY_USER_DELETE_LOGS' => 'no']);
    $admin = principal('arcadmin', Role::Admin);
    $adminAsBob = principal('arcadmin', Role::Admin, look: 'bob');
    $hostco = principal('hostco', Role::Reseller);
    $bob = principal('bob', Role::User);

    expect(batch2Policy(LogPolicy::class, $bob)->view($bob, 'bob'))->toBeFalse()
        ->and(batch2Policy(LogPolicy::class, $bob)->delete($bob, 'bob'))->toBeFalse()
        ->and(batch2Policy(LogPolicy::class, $hostco)->view($hostco, 'bob'))->toBeFalse()
        ->and(batch2Policy(LogPolicy::class, $admin)->view($admin, 'bob'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $adminAsBob)->view($adminAsBob, 'bob'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $adminAsBob)->delete($adminAsBob, 'bob'))->toBeTrue();
});

it('hides the login history in demo mode and protects the admin history', function () {
    $admin = principal('arcadmin', Role::Admin);
    $bob = principal('bob', Role::User);
    expect(batch2Policy(LogPolicy::class, $bob)->viewAuth($bob, 'bob'))->toBeTrue();

    withSysConfig($this->fake, ['DEMO_MODE' => 'yes']);
    expect(batch2Policy(LogPolicy::class, $bob)->viewAuth($bob, 'bob'))->toBeFalse();

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    $other = principal('otheradmin', Role::Admin);
    expect(batch2Policy(LogPolicy::class, $admin)->viewAuth($admin, 'arcadmin'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $other)->viewAuth($other, 'arcadmin'))->toBeFalse()
        ->and(batch2Policy(LogPolicy::class, $other)->viewAuth($other, 'bob'))->toBeTrue();
});

it('refuses log deletion in read-only mode', function () {
    $bob = principal('bob', Role::User, readOnly: true);

    expect(batch2Policy(LogPolicy::class, $bob)->view($bob, 'bob'))->toBeTrue()
        ->and(batch2Policy(LogPolicy::class, $bob)->delete($bob, 'bob'))->toBeFalse();
});

it('shows server totals to administrators and tree totals to resellers', function () {
    $admin = principal('arcadmin', Role::Admin);
    $hostco = principal('hostco', Role::Reseller);
    $hostcoAsBob = principal('hostco', Role::Reseller, look: 'bob');
    $bob = principal('bob', Role::User);

    expect(batch2Policy(StatsPolicy::class, $admin)->overall($admin))->toBeTrue()
        ->and(batch2Policy(StatsPolicy::class, $admin)->view($admin, 'carol'))->toBeTrue()
        ->and(batch2Policy(StatsPolicy::class, $hostco)->overall($hostco))->toBeFalse()
        ->and(batch2Policy(StatsPolicy::class, $hostco)->tree($hostco))->toBeTrue()
        ->and(batch2Policy(StatsPolicy::class, $hostco)->view($hostco, 'bob'))->toBeTrue()
        ->and(batch2Policy(StatsPolicy::class, $hostco)->view($hostco, 'carol'))->toBeFalse()
        ->and(batch2Policy(StatsPolicy::class, $hostcoAsBob)->tree($hostcoAsBob))->toBeFalse()
        ->and(batch2Policy(StatsPolicy::class, $bob)->tree($bob))->toBeFalse()
        ->and(batch2Policy(StatsPolicy::class, $bob)->view($bob, 'bob'))->toBeTrue()
        ->and(batch2Policy(StatsPolicy::class, $bob)->view($bob, 'carol'))->toBeFalse();
});

it('follows API_SYSTEM for access keys', function () {
    $admin = principal('arcadmin', Role::Admin);
    $bob = principal('bob', Role::User);

    withSysConfig($this->fake, ['API_SYSTEM' => '0']);
    expect(batch2Policy(AccessKeyPolicy::class, $admin)->view($admin, 'arcadmin'))->toBeFalse();

    withSysConfig($this->fake, ['API_SYSTEM' => '1']);
    expect(batch2Policy(AccessKeyPolicy::class, $admin)->view($admin, 'arcadmin'))->toBeTrue()
        ->and(batch2Policy(AccessKeyPolicy::class, $admin)->view($admin, 'bob'))->toBeFalse()
        ->and(batch2Policy(AccessKeyPolicy::class, $bob)->view($bob, 'bob'))->toBeFalse();

    withSysConfig($this->fake, ['API_SYSTEM' => '2']);
    expect(batch2Policy(AccessKeyPolicy::class, $admin)->view($admin, 'bob'))->toBeTrue()
        ->and(batch2Policy(AccessKeyPolicy::class, $admin)->manage($admin, 'bob'))->toBeTrue()
        ->and(batch2Policy(AccessKeyPolicy::class, $bob)->manage($bob, 'bob'))->toBeTrue()
        ->and(batch2Policy(AccessKeyPolicy::class, $bob)->view($bob, 'carol'))->toBeFalse();
});

it('keeps resellers to their own access keys and limits permission sets by owner', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2']);
    $hostco = principal('hostco', Role::Reseller);
    $policy = batch2Policy(AccessKeyPolicy::class, $hostco);
    $sets = ['billing' => ['ROLE' => 'admin'], 'purge-nginx-cache' => ['ROLE' => 'user'], 'update-dns-records' => ['ROLE' => 'user']];

    expect($policy->manage($hostco, 'hostco'))->toBeTrue()
        ->and($policy->view($hostco, 'bob'))->toBeFalse()
        ->and($policy->allowedSets('hostco', $sets))->toBe(['purge-nginx-cache', 'update-dns-records'])
        ->and($policy->allowedSets('arcadmin', $sets))->toBe(['billing', 'purge-nginx-cache', 'update-dns-records']);

    $readOnly = principal('bob', Role::User, readOnly: true);
    expect(batch2Policy(AccessKeyPolicy::class, $readOnly)->manage($readOnly, 'bob'))->toBeFalse();
});

it('lets every account manage its own SSH keys and administrators any', function () {
    $admin = principal('arcadmin', Role::Admin);
    $hostco = principal('hostco', Role::Reseller);
    $bob = principal('bob', Role::User);

    expect(batch2Policy(SshKeyPolicy::class, $admin)->manage($admin, 'carol'))->toBeTrue()
        ->and(batch2Policy(SshKeyPolicy::class, $hostco)->manage($hostco, 'hostco'))->toBeTrue()
        ->and(batch2Policy(SshKeyPolicy::class, $hostco)->view($hostco, 'bob'))->toBeFalse()
        ->and(batch2Policy(SshKeyPolicy::class, $bob)->manage($bob, 'bob'))->toBeTrue()
        ->and(batch2Policy(SshKeyPolicy::class, $bob)->view($bob, 'carol'))->toBeFalse();
});

it('opens the terminal only with a shell and not for a reseller acting as a customer', function () {
    $bob = principal('bob', Role::User);
    expect(batch2Policy(TerminalPolicy::class, $bob)->use($bob))->toBeFalse(); // WEB_TERMINAL='false'

    withSysConfig($this->fake, ['WEB_TERMINAL' => 'true']);
    $withShell = fn (string $user, Role $role, ?string $look = null, bool $readOnly = false) => new Principal(
        $user, $look, $role, $look !== null ? Role::User : $role, 'bash', 'en', null, str_repeat('e', 32), $readOnly);

    expect(batch2Policy(TerminalPolicy::class, $bob)->use($bob))->toBeFalse() // principal() has nologin
        ->and(batch2Policy(TerminalPolicy::class, $withShell('bob', Role::User))->use($withShell('bob', Role::User)))->toBeTrue()
        ->and(batch2Policy(TerminalPolicy::class, $withShell('arcadmin', Role::Admin, 'bob'))->use($withShell('arcadmin', Role::Admin, 'bob')))->toBeTrue()
        ->and(batch2Policy(TerminalPolicy::class, $withShell('hostco', Role::Reseller, 'bob'))->use($withShell('hostco', Role::Reseller, 'bob')))->toBeFalse()
        ->and(batch2Policy(TerminalPolicy::class, $withShell('bob', Role::User, null, true))->use($withShell('bob', Role::User, null, true)))->toBeFalse();
});

it('lets owners and managers install apps on web domains', function () {
    $hostco = principal('hostco', Role::Reseller);
    $bob = principal('bob', Role::User);

    expect(batch2Policy(WebDomainPolicy::class, $hostco)->installApp($hostco, 'bob'))->toBeTrue()
        ->and(batch2Policy(WebDomainPolicy::class, $hostco)->installApp($hostco, 'carol'))->toBeFalse()
        ->and(batch2Policy(WebDomainPolicy::class, $bob)->installApp($bob, 'bob'))->toBeTrue();
});
