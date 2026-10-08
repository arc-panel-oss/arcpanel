<?php

use App\Auth\Role;
use App\Http\Resources\V2\AccessKeyResource;
use App\Http\Resources\V2\AccessKeySetResource;
use App\Http\Resources\V2\NewAccessKeyResource;
use App\Services\Keys\AccessKey;
use App\Services\Keys\AccessKeySets;
use App\Services\Users\UserActions;
use App\Services\Users\UserRecord;
use App\Services\WebDomains\Step;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// The user tab rules that moved from UserEditController into UserActions
// (E34 batch 6 D66) and the access key Resources of §4.6.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-list-sys-php', ['json'])->returnsJson(['8.4']);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['USERS' => '0']]);
    $this->actions = app(UserActions::class);
});

/** @param  list<Step>  $steps */
function stepCalls(array $steps): array
{
    return array_map(fn (Step $step) => [$step->command, ...array_map('strval', $step->args)], $steps);
}

it('drops login disabling and someone else\'s 2FA removal without the security right', function () {
    $bob = UserRecord::from('bob', userRecord(['TWOFA' => 'X', 'LOGIN_USE_IPLIST' => 'yes', 'LOGIN_ALLOW_IPS' => '192.0.2.1']), 'hostco');
    $values = ['use_iplist' => true, 'allowed_ips' => '192.0.2.1', 'login_disabled' => true, 'twofa_off' => true];

    expect(stepCalls($this->actions->planSecurity(principal('hostco', Role::Reseller), $bob, $values, false)))->toBe([])
        ->and(stepCalls($this->actions->planSecurity(principal('bob', Role::User), $bob, $values, false)))->toBe([['v-delete-user-2fa', 'bob']])
        ->and(stepCalls($this->actions->planSecurity(principal('arcadmin', Role::Admin), $bob, $values, true)))
        ->toBe([['v-change-user-config-value', 'bob', 'LOGIN_DISABLED', 'yes'], ['v-delete-user-2fa', 'bob']]);
});

it('keeps the IP allow list when it is not given and drops it with an empty list', function () {
    $bob = UserRecord::from('bob', userRecord(['LOGIN_USE_IPLIST' => 'no', 'LOGIN_ALLOW_IPS' => '192.0.2.1']), 'hostco');
    $keep = ['use_iplist' => false, 'allowed_ips' => null, 'login_disabled' => null, 'twofa_off' => false];

    expect($this->actions->planSecurity(principal('bob', Role::User), $bob, $keep, false))->toBe([])
        ->and(stepCalls($this->actions->planSecurity(principal('bob', Role::User), $bob, [...$keep, 'allowed_ips' => ''], false)))
        ->toBe([['v-change-user-config-value', 'bob', 'LOGIN_ALLOW_IPS', '']]);
});

it('checks the administration fields as the page does', function (array $values, string $field) {
    $resb = UserRecord::from('resb', userRecord(['ROLE' => 'reseller', 'PACKAGE' => 'reseller']));
    $bob = UserRecord::from('bob', userRecord(), 'hostco');
    $record = ($values['role'] ?? null) === 'user' ? $resb : $bob;

    try {
        $this->actions->planAdministration($record, ['role' => null, 'owner' => null, 'shell' => null, 'phpcli' => null, ...$values]);
        $this->fail('No validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([$field]);
    }
})->with([
    'demote a reseller with customers' => [['role' => 'user'], 'role'],
    'a reseller without customers in its package' => [['role' => 'reseller'], 'role'],
    'an owner that is no reseller' => [['owner' => 'carol'], 'owner'],
    'a shell that is not installed' => [['shell' => 'zsh'], 'shell'],
    'a PHP version that is not installed' => [['phpcli' => '7.4'], 'phpcli'],
]);

it('plans the administration fields owner first', function () {
    $bob = UserRecord::from('bob', userRecord(), 'hostco');

    expect(stepCalls($this->actions->planAdministration($bob, ['role' => null, 'owner' => 'resb', 'shell' => 'bash', 'phpcli' => '8.4'])))->toBe([
        ['v-change-user-owner', 'bob', 'resb'],
        ['v-change-user-shell', 'bob', 'bash'],
        ['v-change-user-php-cli', 'bob', '8.4'],
    ])->and(stepCalls($this->actions->planNameservers($bob, ['ns1.a.example', 'ns2.a.example'])))
        ->toBe([['v-change-user-ns', 'bob', 'ns1.a.example', 'ns2.a.example']]);
});

it('types access keys, shows the secret only in a new key and describes every shipped set', function () {
    $key = AccessKey::fromArray([
        'ACCESS_KEY_ID' => 'Key01234567890123456', 'SECRET_ACCESS_KEY' => 'top-secret', 'USER' => 'bob',
        'PERMISSIONS' => ['v2-read'], 'COMMENT' => 'ci', 'DATE' => '2026-10-08', 'TIME' => '12:00:00',
    ], withSecret: true);
    $request = Request::create('/');

    expect((new AccessKeyResource($key))->resolve($request))
        ->toBe(['id' => 'Key01234567890123456', 'permissions' => ['v2-read'], 'comment' => 'ci', 'created_at' => '2026-10-08T12:00:00'])
        ->and((new NewAccessKeyResource($key))->resolve($request)['secret'])->toBe('top-secret')
        ->and((new AccessKeySetResource(['name' => 'v2-read', 'api' => 2, 'description' => 'd']))->resolve($request))
        ->toBe(['name' => 'v2-read', 'api' => 2, 'description' => 'd']);

    foreach (glob(base_path('../install/common/api/*')) ?: [] as $file) {
        expect(AccessKeySets::description(basename($file)))->toBeString(basename($file).' has no description');
    }
    // the page shows a hint under every v2 set
    expect(array_keys(AccessKeySets::hints()))->toEqualCanonicalizing(array_map('basename', glob(base_path('../install/common/api/v2-*')) ?: []))
        ->and(AccessKeySets::description('unknown'))->toBeNull();
});
