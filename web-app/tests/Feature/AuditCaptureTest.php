<?php

use App\Arc\ExitCode;
use App\Audit\AuditRecorder;
use App\Audit\AuditWriteFailed;
use App\Audit\AuditWriter;
use App\Audit\CommandAuditWriter;
use App\Audit\ObjectMap;
use App\Audit\RouteActions;
use App\Auth\ArrayLegacySession;
use App\Auth\Role;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// E23 audit log, capture (docs/features/specs/2026-10-23-002-e23-audit-log-design.md §5)

beforeEach(function () {
    $this->fake = fakeArc();
    fakeUsers($this->fake, [
        'arcadmin' => userRecord(['ROLE' => 'admin']),
        'bob' => userRecord(),
        'carol' => userRecord(),
    ]);
    $this->fake->on('v-log-action')->returns();
    $this->fake->on('v-log-user-logout')->returns();
    $this->fake->on('v-acknowledge-user-notification')->returns();
    $this->audit = app(AuditWriter::class);
});

/** @return list<RoutingRoute> */
function stateChangingRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route) => array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== []));
}

it('names every state-changing route and maps its parameters to an object type', function () {
    $routes = stateChangingRoutes();
    expect(count($routes))->toBeGreaterThan(400);
    foreach ($routes as $route) {
        $name = $route->getName();
        expect($name)->not->toBeNull('unnamed route '.$route->uri());
        expect(RouteActions::action($name))->toMatch('/^[a-z0-9][a-z0-9._-]{0,127}$/');
        foreach (ObjectMap::objectParameters($route) as $parameter) {
            expect(ObjectMap::typeOf($name, $parameter))->not->toBeNull("{$name} {{$parameter}}");
        }
    }
});

it('knows every route of the sensitive reads and the explicit actions', function () {
    $names = array_map(fn (RoutingRoute $route) => $route->getName(), Route::getRoutes()->getRoutes());
    foreach (array_merge(RouteActions::READS, array_keys(RouteActions::ACTIONS)) as $name) {
        expect($names)->toContain($name);
    }
});

it('records one event for a state-changing request of the panel', function () {
    ArrayLegacySession::install(sessionOf('bob', 'user'));

    $this->postJson('/_notifications/acknowledge', ['note' => 'top secret value', 'password' => 'hunter2'], ['User-Agent' => 'Pest'])->assertSuccessful();

    $events = $this->audit->events();
    expect($events)->toHaveCount(1);
    $event = $events[0];
    expect($event)->toMatchArray([
        'v' => 1,
        'channel' => 'web',
        'effective_user' => 'bob',
        'action' => 'shell.notifications.acknowledge-all',
        'object' => null,
        'result' => 'ok',
        'fields' => ['note', 'password'],
    ])->and($event['actor'])->toBe([
        'user' => 'bob',
        'role' => 'user',
        'ip' => '127.0.0.1',
        'key' => null,
        'ua' => substr(hash('sha256', 'Pest'), 0, 16),
    ])->and($event['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($event['request_id'])->not->toBe('');
    // Names of the fields, never their values (D5)
    expect(json_encode($event))->not->toContain('top secret value')->not->toContain('hunter2');
});

it('records nothing for a read', function () {
    ArrayLegacySession::install(sessionOf('bob', 'user'));
    $this->fake->on('v-list-user-notifications')->returnsJson([]);

    $this->getJson('/_notifications');

    expect($this->audit->events())->toBe([]);
});

it('records the object of the route, refusals and invalid input', function () {
    ArrayLegacySession::install(sessionOf('bob', 'user'));

    $this->post('/impersonate/carol')->assertForbidden();
    $this->post('/impersonate', ['user' => 'not a user!'])->assertRedirect();

    [$denied, $invalid] = $this->audit->events();
    expect($denied)->toMatchArray([
        'action' => 'impersonate.start',
        'object' => ['type' => 'user', 'user' => 'carol', 'name' => 'carol'],
        'result' => 'denied',
        'status' => 403,
    ])->and($invalid)->toMatchArray(['action' => 'impersonate.form', 'result' => 'invalid', 'status' => 302]);
});

it('records a guest refused by the login wall as denied', function () {
    ArrayLegacySession::install(null);

    $this->post('/impersonate/bob')->assertRedirect('/login');

    expect($this->audit->events()[0])->toMatchArray(['action' => 'impersonate.start', 'result' => 'denied'])
        ->and($this->audit->events()[0]['actor']['user'])->toBe('');
});

it('records a login-as pair with the administrator as actor and the user as effective user', function () {
    $session = ArrayLegacySession::install(sessionOf('arcadmin', 'admin'));
    $this->post('/impersonate/bob')->assertRedirect('/');
    ArrayLegacySession::install(sessionOf('arcadmin', 'admin', ['look' => 'bob']));
    $this->post('/impersonate/stop')->assertRedirect('/');

    [$start, $stop] = $this->audit->events();
    expect($start)->toMatchArray(['action' => 'impersonate.start', 'effective_user' => 'bob', 'result' => 'ok'])
        ->and($start['actor']['user'])->toBe('arcadmin')
        ->and($start['actor']['role'])->toBe('admin')
        ->and($stop)->toMatchArray(['action' => 'impersonate.stop', 'effective_user' => 'bob', 'object' => ['type' => 'user', 'user' => 'bob', 'name' => 'bob']])
        ->and($stop['actor']['user'])->toBe('arcadmin');
    expect($this->audit->events())->toHaveCount(2);
});

it('records a sign-out as auth.logout', function () {
    ArrayLegacySession::install(sessionOf('bob', 'user'));

    $this->post('/logout')->assertRedirect('/login');

    expect($this->audit->events())->toHaveCount(1)
        ->and($this->audit->events()[0])->toMatchArray(['action' => 'auth.logout', 'result' => 'ok']);
});

it('records sign-ins with the name typed, ok or denied, once', function () {
    ArrayLegacySession::install(null);
    $this->fake->on('v-get-user-salt')->returnsJson(['bob' => ['METHOD' => 'des', 'SALT' => 'xy']]);
    $this->fake->on('v-check-user-hash')->using(fn ($args) => $args[1] === crypt('pw', 'xy') ? [0, ''] : [9, 'Error']);
    $this->fake->on('v-log-user-login')->returns();
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en']);

    $this->post('/login', ['user' => 'bob', 'password' => 'nope'])->assertRedirect('/login');
    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/');

    [$failed, $ok] = $this->audit->events();
    expect($this->audit->events())->toHaveCount(2)
        ->and($failed)->toMatchArray(['action' => 'auth.login', 'result' => 'denied', 'effective_user' => 'bob', 'fields' => ['user', 'password']])
        ->and($failed['actor'])->toMatchArray(['user' => 'bob', 'role' => ''])
        ->and($ok)->toMatchArray(['action' => 'auth.login', 'result' => 'ok'])
        ->and($ok['actor'])->toMatchArray(['user' => 'bob', 'role' => 'user']);
});

it('records the second factor of a sign-in', function () {
    ArrayLegacySession::install(null);
    $this->fake->on('v-get-user-salt')->returnsJson(['bob' => ['METHOD' => 'des', 'SALT' => 'xy']]);
    $this->fake->on('v-check-user-hash')->returns();
    fakeUsers($this->fake, ['bob' => userRecord(['TWOFA' => 'SECRET'])]);
    $this->fake->on('v-log-user-login')->returns();
    $this->fake->on('v-list-sys-languages', ['json'])->returnsJson(['en']);
    $this->fake->on('v-check-user-2fa')->using(fn ($args) => $args[1] === '123456' ? [0, ''] : [9, 'Error']);

    $this->post('/login', ['user' => 'bob', 'password' => 'pw'])->assertRedirect('/login/2fa');
    $this->post('/login/2fa', ['twofa' => '000000'])->assertRedirect();
    $this->post('/login/2fa', ['twofa' => '123456'])->assertRedirect('/');

    expect(array_map(fn ($event) => $event['action'].' '.$event['result'], $this->audit->events()))
        ->toBe(['auth.login ok', 'auth.2fa.verify denied', 'auth.2fa.verify ok']);
});

it('records a REST API v2 write with the access key and skips dry runs', function () {
    $fake = fakeApi();
    fakeResellers($fake);
    $fake->on('v-acknowledge-user-notification')->returns();
    $headers = apiAs($fake, 'bob', 'user', area: 'accounts');

    $this->postJson('/api/v2/notifications/acknowledge?dry_run=1', [], $headers)->assertOk();
    expect($this->audit->events())->toBe([]);

    $this->postJson('/api/v2/notifications/acknowledge', [], $headers + ['X-Request-Id' => 'fleet-42'])->assertNoContent();

    expect($this->audit->events())->toHaveCount(1);
    $event = $this->audit->events()[0];
    expect($event)->toMatchArray([
        'request_id' => 'fleet-42',
        'channel' => 'api_v2',
        'action' => 'api.v2.notifications.acknowledge-all',
        'result' => 'ok',
        'status' => 204,
        'effective_user' => 'bob',
    ])->and($event['actor'])->toMatchArray(['user' => 'bob', 'role' => 'user', 'key' => API_KEY_ID]);
});

it('records a refused access key and an unknown path of API v2', function () {
    $fake = fakeApi();
    apiKey($fake, 'bob', 'user');

    $this->postJson('/api/v2/notifications/acknowledge', [], ['Authorization' => 'Bearer '.API_KEY_ID.':'.str_repeat('x', 40)])->assertUnauthorized();
    $this->postJson('/api/v2/no-such-thing', [])->assertNotFound();

    [$refused, $unknown] = $this->audit->events();
    expect($refused)->toMatchArray(['channel' => 'api_v2', 'action' => 'api.v2.notifications.acknowledge-all', 'result' => 'denied', 'status' => 401])
        ->and($refused['actor']['user'])->toBe('')
        ->and($unknown)->toMatchArray(['channel' => 'api_v2', 'action' => 'http.unrouted', 'result' => 'denied', 'status' => 404]);
});

it('records a sensitive read of a GET request', function () {
    $fake = fakeApi();
    fakeResellers($fake);
    $headers = apiAs($fake, 'arcadmin', 'admin');
    $fake->on('v-list-node-apps')->using(fn (array $args) => [0, (string) json_encode(nodeAppList($args[0], [nodeApp('example.com')]))]);
    $fake->on('v-list-node-app-env')->returnsJson(['TOKEN' => 'abc']);

    $this->getJson('/api/v2/users/bob/node-apps/example.com/env', $headers);
    expect($this->audit->events())->toBe([]);

    $this->getJson('/api/v2/users/bob/node-apps/example.com/env?reveal=1', $headers);
    expect($this->audit->events())->toHaveCount(1)
        ->and($this->audit->events()[0])->toMatchArray([
            'action' => 'secrets.reveal',
            'object' => ['type' => 'node_app', 'user' => 'bob', 'name' => 'example.com'],
        ]);
});

it('keeps the answer when the event cannot be written, logs it and tells the administrator once a day', function () {
    ArrayLegacySession::install(sessionOf('bob', 'user'));
    $this->audit->failWith('Error: disk full');
    $this->fake->on('v-add-user-notification')->returns();
    Cache::forget('audit:failure-notice:'.gmdate('Y-m-d'));
    $log = sys_get_temp_dir().'/arc-pest-audit-'.bin2hex(random_bytes(4)).'.log';
    config(['logging.channels.audit' => ['driver' => 'single', 'path' => $log]]);

    $this->postJson('/_notifications/acknowledge')->assertSuccessful();
    $this->postJson('/_notifications/acknowledge')->assertSuccessful();

    $text = (string) file_get_contents($log);
    unlink($log);
    expect(substr_count($text, 'The audit event could not be written.'))->toBe(2)
        ->and($text)->toContain('Error: disk full')->toContain('shell.notifications.acknowledge-all');

    $this->fake->assertRan('v-add-user-notification', fn ($args) => $args[0] === 'arcadmin' && $args[1] === 'Audit log', times: 1);
});

it('writes the event through v-add-sys-audit-event in a file', function () {
    $this->fake->on('v-add-sys-audit-event')->returns();
    $event = ['v' => 1, 'id' => '01JBX6W3Q2Z8K4N7P5R9T0V1C2', 'action' => 'users.store'];

    (new CommandAuditWriter)->write($event);

    $this->fake->assertRan('v-add-sys-audit-event', fn ($args) => json_decode($args[0], true) === $event);
});

it('fails the writer when the command fails', function () {
    $this->fake->on('v-add-sys-audit-event')->returnsExit(ExitCode::Disk, 'Error: no space');

    expect(fn () => (new CommandAuditWriter)->write(['v' => 1]))->toThrow(AuditWriteFailed::class);
});

it('maps statuses and flashed messages to results', function () {
    $request = Request::create('/x', 'POST');
    expect(AuditRecorder::result($request, 200, principal('bob', Role::User)))->toBe('ok')
        ->and(AuditRecorder::result($request, 403, null))->toBe('denied')
        ->and(AuditRecorder::result($request, 404, null))->toBe('denied')
        ->and(AuditRecorder::result($request, 422, null))->toBe('invalid')
        ->and(AuditRecorder::result($request, 409, null))->toBe('invalid')
        ->and(AuditRecorder::result($request, 500, null))->toBe('failed');

    $session = app('session')->driver();
    $request->setLaravelSession($session);
    $session->flash('error', 'Error: the command failed');
    expect(AuditRecorder::result($request, 302, principal('bob', Role::User)))->toBe('failed');
    $session->flush();
    $session->flash('errors', 'bag');
    expect(AuditRecorder::result($request, 302, principal('bob', Role::User)))->toBe('invalid');
});

it('builds objects from route parameters', function () {
    $route = Route::getRoutes()->getByName('mail.accounts.destroy');
    $route->bind(Request::create('/mail/bob/example.com/accounts/info', 'DELETE'));
    expect(ObjectMap::of($route))->toBe(['type' => 'mail_account', 'user' => 'bob', 'name' => 'example.com/info']);

    $route = Route::getRoutes()->getByName('api.v2.system.php.ini.update');
    $route->bind(Request::create('/api/v2/system/php/versions/8.3/ini/fpm', 'PATCH'));
    expect(ObjectMap::of($route))->toBe(['type' => 'php_ini', 'user' => null, 'name' => '8.3/fpm']);

    $route = Route::getRoutes()->getByName('server.firewall.move');
    $route->bind(Request::create('/server/firewall/7/move/up', 'POST'));
    expect(ObjectMap::of($route))->toBe(['type' => 'firewall_rule', 'user' => null, 'name' => '7']);
});
