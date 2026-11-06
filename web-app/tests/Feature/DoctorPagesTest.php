<?php

use App\Arc\ExitCode;
use App\Auth\Role;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// The Doctor pages (E17 spec §5). hostco owns bob, resb owns carol and dave;
// arcadmin is the root administrator.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeDoctor($this->fake);
});

it('shows a user the checks of its domains with the Fix of a failing check', function () {
    signIn('bob', 'user');

    $this->get('/doctor')->assertRedirect('/doctor/bob');
    $this->get('/doctor/bob')->assertOk()->assertSee('bob.example')->assertSee('web.ssl-aliases')->assertDontSee('carol.example');
    $this->fake->assertRan('v-list-sys-doctor', fn ($a) => $a === ['bob', 'json']);

    $this->get('/doctor/bob/bob.example')->assertOk()
        ->assertSee('www.bob.example is not in the certificate.')
        ->assertSee('Score 80')
        ->assertSee('Run v-add-letsencrypt-domain bob bob.example www.bob.example? The check runs again afterwards.')
        ->assertSee('https://arcpanel.org/docs/guides/doctor#web-ssl-aliases', false)
        ->assertSee(route('doctor.domain.fix', ['user' => 'bob', 'domain' => 'bob.example', 'check' => 'web.ssl-aliases']), false);
    $this->get('/doctor/bob/other.example')->assertNotFound();
    $this->get('/doctor/carol')->assertNotFound();
    $this->get('/doctor/carol/carol.example')->assertNotFound();
    $this->get('/server/doctor')->assertForbidden();
});

it('counts one warning in the singular', function () {
    signIn('bob', 'user');
    $this->get('/doctor/bob')->assertOk()->assertSee('1 failed, 1 warning')->assertDontSee('1 warnings');
    $this->get('/doctor/bob/bob.example')->assertOk()->assertSee('1 failed, 1 warning, 1 passed, 0 skipped');

    signIn('arcadmin', 'admin');
    $this->get('/server/doctor')->assertOk()->assertSee('1 failed, 1 warning, 1 passed, 1 skipped');
});

it('lists the domains of a reseller\'s tree and keeps other trees out', function () {
    signIn('hostco', 'reseller');
    $this->get('/doctor/hostco')->assertOk()->assertSee('bob.example')->assertDontSee('carol.example');
    $this->get('/doctor/bob/bob.example')->assertOk();
    $this->get('/doctor/carol')->assertNotFound();
    $this->get('/doctor/carol/carol.example')->assertNotFound();
    $this->post('/doctor/carol/carol.example/run')->assertNotFound();
    $this->get('/server/doctor')->assertForbidden();

    signIn('resb', 'reseller');
    $this->get('/doctor/resb')->assertOk()->assertSee('carol.example')->assertDontSee('bob.example');
});

it('shows administrators the server checks and every domain, worst first', function () {
    signIn('arcadmin', 'admin');
    $response = $this->get('/server/doctor')->assertOk()
        ->assertSee('Server Doctor')->assertSee('Score 75')
        ->assertSee('The Fail2ban service is not running.')
        ->assertSee('Run v-restart-service fail2ban? The check runs again afterwards.')
        ->assertSee('bob.example')->assertSee('carol.example');
    expect(strpos($response->getContent(), 'bob.example'))->toBeLessThan(strpos($response->getContent(), 'carol.example'));
    $this->fake->assertRan('v-list-sys-doctor', fn ($a) => $a === ['all', 'json']);
    $this->get('/doctor/carol/carol.example')->assertOk()->assertSee('Not checked yet');
});

it('runs the Doctor as a job and follows it on the page', function () {
    $this->fake->on('v-run-sys-doctor')->returns('17900000000100');
    fakeJobs($this->fake, [jobRecord('17900000000100', ['TYPE' => 'doctor.run', 'OBJECT' => 'doctor:bob:bob.example',
        'ARGS' => 'v-run-sys-doctor bob bob.example bob job', 'STATUS' => 'running', 'LOG' => "bob.example: score 80\n"])]);
    signIn('bob', 'user');

    $this->post('/doctor/bob/bob.example/run')->assertRedirect('/doctor/bob/bob.example?job=17900000000100')
        ->assertSessionHas('ok', 'The Doctor checks bob.example.');
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a === ['bob', 'bob.example', 'bob']);
    $this->get('/doctor/bob/bob.example?job=17900000000100')->assertOk()->assertSee(route('jobs.show', ['id' => '17900000000100']), false);

    // A job of another object is not shown
    $this->get('/doctor/bob/other.example?job=17900000000100')->assertNotFound();
    $this->post('/doctor/bob/run')->assertRedirect('/doctor/bob?job=17900000000100');
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a === ['bob', '', 'bob']);
});

it('runs a fix and says why one was refused', function () {
    $this->fake->on('v-fix-sys-doctor')->returns('17900000000101');
    signIn('bob', 'user');

    $this->post('/doctor/bob/bob.example/checks/web.ssl-aliases/fix')->assertRedirect('/doctor/bob/bob.example?job=17900000000101')
        ->assertSessionHas('ok', 'The fix of web.ssl-aliases runs.');
    $this->fake->assertRan('v-fix-sys-doctor', fn ($a) => $a === ['bob', 'web.ssl-aliases', 'bob.example', 'bob']);

    $this->fake->on('v-fix-sys-doctor')->returnsExit(ExitCode::Exists, 'Error: web.dns-a has no fix');
    $this->post('/doctor/bob/bob.example/checks/web.dns-a/fix')->assertRedirect('/doctor/bob/bob.example')
        ->assertSessionHas('error', 'The check passes or has no fix; run the Doctor again to see its current result.');

    $this->post('/doctor/bob/bob.example/checks/server.fail2ban/fix')->assertNotFound();
    $this->post('/server/doctor/checks/server.fail2ban/fix')->assertForbidden();
    expect($this->fake->calls('v-fix-sys-doctor'))->toHaveCount(2);
});

it('lets administrators run and fix the server checks', function () {
    $this->fake->on('v-run-sys-doctor')->returns('17900000000102');
    $this->fake->on('v-fix-sys-doctor')->returns('17900000000103');
    signIn('arcadmin', 'admin');

    $this->post('/server/doctor/run')->assertRedirect('/server/doctor?job=17900000000102');
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a === ['system', '', 'arcadmin']);
    $this->post('/server/doctor/run', ['all' => '1'])->assertRedirect('/server/doctor?job=17900000000102');
    $this->fake->assertRan('v-run-sys-doctor', fn ($a) => $a === ['all', '', 'arcadmin']);
    $this->post('/server/doctor/checks/server.fail2ban/fix')->assertRedirect('/server/doctor?job=17900000000103');
    $this->fake->assertRan('v-fix-sys-doctor', fn ($a) => $a === ['system', 'server.fail2ban', '', 'arcadmin']);

    $this->fake->on('v-run-sys-doctor')->returnsExit(ExitCode::InUse, 'Error: a Doctor run of system is running (job 17900000000102)');
    $this->post('/server/doctor/run')->assertRedirect('/server/doctor')
        ->assertSessionHas('error', 'The Doctor is already running for this scope. Wait until it has finished.');
});

it('refuses runs to read-only sessions', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');
    $this->get('/doctor/bob')->assertOk()->assertDontSee('Run now');
    $this->post('/doctor/bob/bob.example/run')->assertForbidden();
    $this->post('/doctor/bob/bob.example/checks/web.ssl-aliases/fix')->assertForbidden();
    $this->fake->assertNotRan('v-run-sys-doctor');
    $this->fake->assertNotRan('v-fix-sys-doctor');
});

it('shows the Doctor tab on the web domain page', function () {
    fakeWebDomains($this->fake);
    fakeDoctor($this->fake);
    signIn('bob', 'user');
    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('Not checked yet')
        ->assertSee(route('doctor.domain', ['user' => 'bob', 'domain' => 'example.com']), false)
        ->assertSee(route('doctor.domain.run', ['user' => 'bob', 'domain' => 'example.com']), false);
    $this->fake->assertRan('v-list-sys-doctor', fn ($a) => $a === ['bob', 'example.com', 'json']);
});

it('gives every Doctor route a capability and a policy', function () {
    foreach (['doctor.view', 'doctor.run'] as $capability) {
        foreach (['admin', 'reseller', 'user'] as $role) {
            expect(Capabilities::allows(Role::from($role), $capability))->toBeTrue();
        }
    }
    foreach (['server.doctor', 'server.doctor.run', 'server.doctor.fix', 'doctor.index', 'doctor.show', 'doctor.domain', 'doctor.run', 'doctor.domain.run', 'doctor.domain.fix'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }
});
