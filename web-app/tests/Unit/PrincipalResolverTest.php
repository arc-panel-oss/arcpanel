<?php

use App\Arc\SysConfig;
use App\Auth\ArrayLegacySession;
use App\Auth\PrincipalResolver;
use App\Auth\Role;

const NOW = 1_800_000_000;

/** A legacy session of a logged-in user, as web/login/index.php leaves it. */
function loggedInSession(array $overrides = []): array
{
    return array_merge([
        'user' => 'bob',
        'userContext' => 'user',
        'user_combined_ip' => '203.0.113.7',
        'token' => str_repeat('cd', 16),
        'LAST_ACTIVITY' => NOW - 60,
        'language' => 'de',
        'look' => '',
    ], $overrides);
}

function resolver(): PrincipalResolver
{
    return app(PrincipalResolver::class);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeUsers($this->fake, [
        'bob' => userRecord(['SHELL' => 'bash']),
        'arcadmin' => userRecord(['ROLE' => 'admin']),
        'carol' => userRecord(['SHELL' => 'nologin', 'SUSPENDED' => 'yes']),
    ]);
    $this->fake->on('v-log-user-logout')->returns();
});

it('returns nobody without a session cookie', function () {
    ArrayLegacySession::install(null);

    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
    expect($this->fake->calls())->toBe([]);
});

it('returns nobody for a guest session without touching it', function () {
    $session = ArrayLegacySession::install(['token' => 'x']);

    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
    expect($session->data())->toBe(['token' => 'x']);
});

it('builds the principal and refreshes the session like main.php', function () {
    $session = ArrayLegacySession::install(loggedInSession());

    $principal = resolver()->resolve('203.0.113.7', NOW);

    expect($principal->loginUser)->toBe('bob')
        ->and($principal->effectiveUser())->toBe('bob')
        ->and($principal->loginRole)->toBe(Role::User)
        ->and($principal->loginShell)->toBe('bash')
        ->and($principal->language)->toBe('de')
        ->and($principal->readOnly)->toBeFalse();
    expect($session->data())->toMatchArray([
        'LAST_ACTIVITY' => NOW,
        'login_shell' => 'bash',
        'role' => 'user',
    ]);
    expect($session->isOpen())->toBeFalse();
});

it('ends a session used from another IP and logs the logout', function () {
    $session = ArrayLegacySession::install(loggedInSession());

    expect(resolver()->resolve('198.51.100.9', NOW))->toBeNull();
    $session->assertDestroyed();
    $this->fake->assertRan('v-log-user-logout', fn ($args) => $args === ['bob', str_repeat('cd', 16)]);
});

it('ignores the IP when DISABLE_IP_CHECK is yes', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['DISABLE_IP_CHECK' => 'yes']));
    ArrayLegacySession::install(loggedInSession());

    expect(resolver()->resolve('198.51.100.9', NOW))->not->toBeNull();
});

it('ends the session of a deleted user with a message', function () {
    $session = ArrayLegacySession::install(loggedInSession(['user' => 'ghost']));
    $resolver = resolver();

    expect($resolver->resolve('203.0.113.7', NOW))->toBeNull();
    expect($resolver->logoutMessage)->toBe(PrincipalResolver::LOGGED_OUT);
    $session->assertDestroyed();
});

it('ends a session that logged in before the last password change (M8)', function () {
    fakeUsers($this->fake, ['bob' => userRecord(['SHELL' => 'bash', 'SESSIONS_VALID_AFTER' => (string) (NOW - 100)])]);
    $session = ArrayLegacySession::install(loggedInSession(['LOGIN_TIME' => NOW - 3600]));
    $resolver = resolver();

    expect($resolver->resolve('203.0.113.7', NOW))->toBeNull();
    expect($resolver->logoutMessage)->toBe(PrincipalResolver::LOGGED_OUT);
    $session->assertDestroyed();
});

it('ends a session from before the upgrade once the password changed', function () {
    fakeUsers($this->fake, ['bob' => userRecord(['SHELL' => 'bash', 'SESSIONS_VALID_AFTER' => (string) (NOW - 100)])]);
    ArrayLegacySession::install(loggedInSession());

    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
});

it('keeps a session that logged in after the last password change, and any session without one', function () {
    fakeUsers($this->fake, ['bob' => userRecord(['SHELL' => 'bash', 'SESSIONS_VALID_AFTER' => (string) (NOW - 100)])]);
    ArrayLegacySession::install(loggedInSession(['LOGIN_TIME' => NOW - 100]));
    expect(resolver()->resolve('203.0.113.7', NOW))->not->toBeNull();

    fakeUsers($this->fake, ['bob' => userRecord(['SHELL' => 'bash'])]);
    ArrayLegacySession::install(loggedInSession());
    expect(resolver()->resolve('203.0.113.7', NOW))->not->toBeNull();
});

it('ends the session of an admin who is no longer admin', function () {
    $session = ArrayLegacySession::install(loggedInSession(['userContext' => 'admin']));

    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
    $session->assertDestroyed();
});

it('ends the session of a suspended user unless suspended users may look', function () {
    $session = ArrayLegacySession::install(loggedInSession(['user' => 'carol']));
    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
    $session->assertDestroyed();

    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes']));
    app()->forgetInstance(SysConfig::class);
    cache()->flush();
    ArrayLegacySession::install(loggedInSession(['user' => 'carol']));

    $principal = resolver()->resolve('203.0.113.7', NOW);
    expect($principal->readOnly)->toBeTrue();
});

it('treats a suspended reseller or administrator like a suspended user, also while it acts as someone else', function (bool $viewSuspended) {
    // E39 W4 spec §4: hostco (reseller of bob) and otheradmin are suspended
    fakeResellers($this->fake, ['hostco' => ['SUSPENDED' => 'yes'], 'otheradmin' => ['ROLE' => 'admin', 'SUSPENDED' => 'yes']]);
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => $viewSuspended ? 'yes' : 'no']));
    app()->forgetInstance(SysConfig::class);
    cache()->flush();

    foreach ([
        ['user' => 'hostco', 'userContext' => 'reseller'],
        ['user' => 'hostco', 'userContext' => 'reseller', PrincipalResolver::RESELLER_LOOK => 'bob'],
        ['user' => 'otheradmin', 'userContext' => 'admin'],
        ['user' => 'otheradmin', 'userContext' => 'admin', 'look' => 'bob'],
    ] as $session) {
        $legacy = ArrayLegacySession::install(loggedInSession($session));
        $principal = resolver()->resolve('203.0.113.7', NOW);

        if ($viewSuspended) {
            expect($principal->readOnly)->toBeTrue()
                ->and($principal->effectiveUser())->toBe($session['look'] ?? $session[PrincipalResolver::RESELLER_LOOK] ?? $session['user']);
        } else {
            expect($principal)->toBeNull();
            $legacy->assertDestroyed();
        }
    }

    // A reseller that is not suspended may act as a suspended customer and change it, as before
    fakeResellers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    ArrayLegacySession::install(loggedInSession(['user' => 'hostco', 'userContext' => 'reseller', PrincipalResolver::RESELLER_LOOK => 'bob']));
    expect(resolver()->resolve('203.0.113.7', NOW)->readOnly)->toBeFalse();
})->with(['policy on' => true, 'policy off' => false]);

it('ends an inactive session and logs the logout', function () {
    $session = ArrayLegacySession::install(loggedInSession(['LAST_ACTIVITY' => NOW - 61 * 60]));

    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
    $session->assertDestroyed();
    $this->fake->assertRan('v-log-user-logout');
});

it('ends a session without LAST_ACTIVITY', function () {
    $session = ArrayLegacySession::install(loggedInSession(['LAST_ACTIVITY' => null]));

    expect(resolver()->resolve('203.0.113.7', NOW))->toBeNull();
    $session->assertDestroyed();
    $this->fake->assertNotRan('v-log-user-logout');
});

it('takes shell and role from the impersonated user', function () {
    ArrayLegacySession::install(loggedInSession([
        'user' => 'arcadmin', 'userContext' => 'admin', 'look' => 'bob',
    ]));

    $principal = resolver()->resolve('203.0.113.7', NOW);

    expect($principal->isImpersonating())->toBeTrue()
        ->and($principal->effectiveUser())->toBe('bob')
        ->and($principal->loginRole)->toBe(Role::Admin)
        ->and($principal->effectiveRole)->toBe(Role::User)
        ->and($principal->loginShell)->toBe('bash');
});

it('is read-only when an admin looks at the protected system administrator', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']));
    $this->fake->on('v-list-user', ['otheradmin', 'json'])->returnsJson(['otheradmin' => userRecord(['ROLE' => 'admin'])]);
    ArrayLegacySession::install(loggedInSession([
        'user' => 'otheradmin', 'userContext' => 'admin', 'look' => 'arcadmin',
    ]));

    expect(resolver()->resolve('203.0.113.7', NOW)->readOnly)->toBeTrue();
});

it('creates a legacy token when the session has none', function () {
    $session = ArrayLegacySession::install(loggedInSession(['token' => null]));

    $principal = resolver()->resolve('203.0.113.7', NOW);

    expect($principal->legacyToken)->toMatch('/^[0-9a-f]{32}$/')
        ->and($session->data()['token'])->toBe($principal->legacyToken);
});
