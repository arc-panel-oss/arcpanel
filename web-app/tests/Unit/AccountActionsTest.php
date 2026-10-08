<?php

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Resources\V2\AuthLogEntryResource;
use App\Http\Resources\V2\LogEntryResource;
use App\Http\Resources\V2\NotificationResource;
use App\Http\Resources\V2\SshKeyResource;
use App\Http\Resources\V2\StatsMonthResource;
use App\Services\Keys\SshKeyService;
use App\Services\Notifications\NotificationService;
use App\Services\Stats\StatsActions;
use App\Services\Stats\StatsMonth;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The account classes of E34 batch 6 (§4.1–§4.4, D66): StatsActions, which
// the Statistics page and API v2 share, and the Resources of the account
// endpoints.

function statsActionsAs(string $user, Role $role): StatsActions
{
    app()->instance(Principal::class, principal($user, $role));

    return app(StatsActions::class);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    $this->fake->on('v-list-user-stats')->using(fn (array $args) => [0, (string) json_encode(['2026-10-01' => ['U_DISK' => $args[0] === 'bob' ? '7' : '1']])]);
    $this->fake->on('v-list-users-stats')->returnsJson(['2026-10-01' => ['U_USERS' => '6']]);
    $this->fake->on('v-list-reseller-users-stats')->returnsJson(['2026-10-01' => ['U_USERS' => '2']]);
});

it('resolves the statistics scope of each role', function () {
    $admin = statsActionsAs('arcadmin', Role::Admin)->resolve(principal('arcadmin', Role::Admin), '');
    expect($admin['scope'])->toBe('server')->and($admin['months'][0]->value('U_USERS'))->toBe('6');

    $reseller = statsActionsAs('hostco', Role::Reseller)->resolve(principal('hostco', Role::Reseller), '');
    expect($reseller['scope'])->toBe('tree');
    $this->fake->assertRan('v-list-reseller-users-stats', fn ($a) => $a === ['hostco', 'json']);

    $customer = statsActionsAs('hostco', Role::Reseller)->resolve(principal('hostco', Role::Reseller), 'bob');
    expect($customer)->toMatchArray(['scope' => 'user', 'owner' => 'bob'])->and($customer['months'][0]->value('U_DISK'))->toBe('7');

    $user = statsActionsAs('bob', Role::User)->resolve(principal('bob', Role::User), '');
    expect($user)->toMatchArray(['scope' => 'user', 'owner' => 'bob']);
});

it('answers 404 outside the scope and for unknown users', function () {
    expect(fn () => statsActionsAs('hostco', Role::Reseller)->resolve(principal('hostco', Role::Reseller), 'carol'))->toThrow(NotFoundHttpException::class);
    expect(fn () => statsActionsAs('arcadmin', Role::Admin)->resolve(principal('arcadmin', Role::Admin), 'ghost'))->toThrow(HttpException::class);
});

it('offers the owners an administrator or a reseller may pick', function () {
    expect(statsActionsAs('arcadmin', Role::Admin)->owners(principal('arcadmin', Role::Admin)))->toContain('arcadmin', 'bob', 'carol')
        ->and(statsActionsAs('hostco', Role::Reseller)->owners(principal('hostco', Role::Reseller)))->toEqualCanonicalizing(['hostco', 'bob'])
        ->and(statsActionsAs('bob', Role::User)->owners(principal('bob', Role::User)))->toBeNull();

    withSysConfig($this->fake, ['POLICY_SYSTEM_HIDE_ADMIN' => 'yes']);
    expect(statsActionsAs('arcadmin', Role::Admin)->owners(principal('arcadmin', Role::Admin)))->not->toContain('arcadmin');
});

it('names every counter of the Statistics page in StatsMonth', function () {
    $row = ['DISK_QUOTA' => 'unlimited', 'U_DISK' => '5', 'BANDWIDTH' => '100', 'U_BANDWIDTH' => '3'];
    $i = 1;
    foreach (array_keys(StatsMonth::COUNTERS) as $key) {
        $row[$key] = (string) $i++;
    }
    $month = (new StatsMonthResource(StatsMonth::fromArray('2026-10-01', $row)))->resolve(Request::create('/'));

    $i = 1;
    foreach (array_keys(StatsMonth::COUNTERS) as $key) {
        expect($month[StatsMonthResource::counterName($key)])->toBe($i++, $key);
    }
    expect($month['month'])->toBe('2026-10')
        ->and($month['disk_mb']['limit'])->toBeNull()
        ->and($month['bandwidth_mb'])->toBe(['used' => 3, 'limit' => 100])
        ->and($month['users'])->toBeNull();
});

it('types log entries, notifications and SSH keys', function () {
    $request = Request::create('/');

    expect((new LogEntryResource(['date' => '2026-10-08', 'time' => '10:00:00', 'level' => 'Info', 'category' => 'Web', 'message' => 'Hi.']))->resolve($request))
        ->toBe(['time' => '2026-10-08T10:00:00', 'level' => 'Info', 'category' => 'Web', 'message' => 'Hi.'])
        ->and((new AuthLogEntryResource(['date' => '', 'time' => '', 'ip' => '192.0.2.1', 'success' => true, 'browser' => 'curl']))->resolve($request))
        ->toBe(['time' => null, 'ip' => '192.0.2.1', 'success' => true, 'user_agent' => 'curl'])
        ->and((new NotificationResource(['id' => '4', 'title' => 'T', 'body' => 'B', 'acknowledged' => true, 'date' => '2026-10-01', 'time' => '09:00:00']))->resolve($request))
        ->toBe(['id' => 4, 'title' => 'T', 'body' => 'B', 'acknowledged' => true, 'created_at' => '2026-10-01T09:00:00'])
        ->and((new SshKeyResource(['id' => 'bob@laptop', 'fingerprint' => 'SHA256:x']))->resolve($request))
        ->toBe(['id' => 'bob@laptop', 'fingerprint' => 'SHA256:x']);
});

it('reads notifications as plain text, newest first, and plans their changes', function () {
    $this->fake->on('v-list-user-notifications', ['bob', 'json'])->returnsJson([
        '1' => ['TOPIC' => '<b>Old</b>', 'NOTICE' => 'a &amp; b', 'ACK' => 'yes', 'DATE' => '2026-10-01', 'TIME' => '09:00:00'],
        '2' => ['TOPIC' => 'New', 'NOTICE' => '<a href="/x">link</a>', 'ACK' => 'no', 'DATE' => '2026-10-02', 'TIME' => '09:00:00'],
    ]);

    $rows = app(NotificationService::class)->forUser('bob');
    expect(array_column($rows, 'id'))->toBe(['2', '1'])
        ->and($rows[1]['title'])->toBe('Old')
        ->and($rows[1]['body'])->toBe('a & b')
        ->and($rows[0]['body'])->toBe('link')
        ->and(NotificationService::acknowledge('bob', 'all')->args)->toBe(['bob', 'all'])
        ->and(NotificationService::delete('bob', '2')->command)->toBe('v-delete-user-notification');
});

it('computes the fingerprint of a public key line as ssh-keygen prints it', function () {
    // `ssh-keygen -l` printed this fingerprint for the key (OpenSSH 9.6)
    $line = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJOoo+WKdBVdvmwFmv+yskige6TmkXGHox6GQbX9mgc5 test@example';

    expect(SshKeyService::fingerprint($line))->toBe('SHA256:0JLdez5qwwR2kREW6Ml2ihrt1HIqp9mU/nRyxb5O5+E')
        ->and(SshKeyService::fingerprint('ssh-ed25519 !!!'))->toBeNull()
        ->and(SshKeyService::fingerprint('ssh-ed25519'))->toBeNull();
});
