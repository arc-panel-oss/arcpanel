<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeCronJobs($this->fake);
    $this->fake->on('v-restart-cron')->returns();
});

// List (spec §7.2)

it('lists only the cron jobs of a user, with the notifications toggle', function () {
    signIn('bob', 'user');

    $this->get('/cron')->assertOk()
        ->assertSee('php /home/bob/web/example.com/public_html/cron.php')->assertSee('bash /home/bob/weekly-report.sh')
        ->assertDontSee('carol.example')->assertDontSee('All owners')
        ->assertSee('Enable Notifications')->assertDontSee('Disable Notifications')
        ->assertSee('/cron/bob/1/edit')->assertDontSee('/cron/bob/2/edit')
        ->assertSee('Are you sure you want to suspend this cron job?')
        ->assertSee('Are you sure you want to unsuspend this cron job?')
        ->assertSee('Are you sure you want to delete this cron job?');
});

it('shows "Disable Notifications" when the user receives cron reports', function () {
    fakeServerUsers($this->fake, ['bob' => ['CRON_REPORTS' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/cron')->assertOk()->assertSee('Disable Notifications')->assertDontSee('Enable Notifications');
});

it('lists the cron jobs of every user for an admin, with an owner filter', function () {
    signIn('arcadmin', 'admin');

    $this->get('/cron')->assertOk()
        ->assertSee('weekly-report.sh')->assertSee('carol.example/tick')->assertSee('All owners')
        ->assertSeeInOrder(['example.com/public_html/cron.php', 'weekly-report.sh', 'carol.example/tick'])
        // the notifications toggle needs one owner
        ->assertDontSee('Enable Notifications');
    $this->get('/cron?owner=carol')->assertOk()->assertSee('carol.example/tick')->assertDontSee('weekly-report.sh')
        ->assertSee('Enable Notifications')->assertSee('/cron/carol/reports?owner=carol');
    $this->get('/cron?owner=ghost')->assertNotFound();
});

it('scopes the list of a reseller to its tree', function () {
    fakeResellers($this->fake);
    signIn('hostco', 'reseller');

    $this->get('/cron')->assertOk()->assertSee('weekly-report.sh')->assertDontSee('carol.example');
    $this->get('/cron?owner=carol')->assertNotFound();
    $this->get('/cron/carol/1/edit')->assertNotFound();
    $this->get('/cron/bob/1/edit')->assertOk();
});

it('shows an empty state without cron jobs', function () {
    signIn('arcadmin', 'admin');

    $this->get('/cron?owner=arcadmin')->assertOk()->assertSee('No cron jobs for this owner.');
});

it('links cron jobs from the command palette to the new edit page', function () {
    $this->fake->on('v-search-object')->returnsJson([]);
    $this->fake->on('v-search-object', ['report', 'json'])->returnsJson(['1' => [
        'ID' => '1', 'USER' => 'bob', 'TYPE' => 'cron', 'KEY' => 'JOB', 'RESULT' => 'bash /home/bob/weekly-report.sh', 'ALIAS' => '',
        'LINK' => '2', 'PARENT' => '2', 'SUSPENDED' => 'yes', 'TIME' => '10:05:00', 'DATE' => '2026-09-30',
    ]]);
    signIn('arcadmin', 'admin');

    $groups = collect($this->getJson('/_palette?q=report')->assertOk()->json('groups'))->keyBy('label');
    expect($groups['Cron Jobs']['items'][0]['url'])->toBe(route('cron.edit', ['user' => 'bob', 'job' => '2']));

    $pages = collect($this->getJson('/_palette?q=cron')->json('groups'))->keyBy('label');
    expect(collect($pages['Pages and actions']['items'])->pluck('url'))->toContain(route('cron.create'), route('cron.index'));
});

// Add (spec §7.2)

it('adds a cron job as web/add/cron and shows the system time', function () {
    $this->fake->on('v-add-cron-job')->returns();
    signIn('bob', 'user');

    $this->get('/cron/create')->assertOk()->assertSee('System time')->assertSee('Every 3 days')->assertDontSee('Every 2 days')
        ->assertDontSee('strongly advised');
    $this->post('/cron', ['min' => '0', 'hour' => '0', 'day' => '*/3', 'month' => '*', 'wday' => '*', 'cmd' => 'php artisan schedule:run "now"'])
        ->assertRedirect('/cron')->assertSessionHas('ok', 'Cron job has been created successfully.');

    $this->fake->assertRan('v-add-cron-job', fn ($a) => $a === ['bob', '0', '0', '*/3', '*', '*', 'php artisan schedule:run "now"']);
    // v-add-cron-job restarts cron itself
    $this->fake->assertNotRan('v-restart-cron');
});

it('requires every field and accepts "0"', function () {
    signIn('bob', 'user');

    $this->post('/cron', ['min' => '', 'hour' => '0', 'day' => '*', 'month' => '*', 'wday' => '', 'cmd' => ''])
        ->assertSessionHasErrors([
            'min' => 'Field "Minute" can not be blank.',
            'wday' => 'Field "Day of Week" can not be blank.',
            'cmd' => 'Field "Command" can not be blank.',
        ])
        ->assertSessionDoesntHaveErrors(['hour']);
    $this->post('/cron', ['min' => '0', 'hour' => '0', 'day' => '1', 'month' => '1', 'wday' => '0', 'cmd' => "echo a\necho b"])
        ->assertSessionHasErrors(['cmd']);
    $this->fake->assertNotRan('v-add-cron-job');
});

it('keeps the input and shows the command error when the job cannot be added', function () {
    $this->fake->on('v-add-cron-job')->returnsExit(ExitCode::Invalid, 'Error: invalid min format :: 61');
    signIn('bob', 'user');

    $this->from('/cron/create')->post('/cron', ['min' => '5', 'hour' => '*', 'day' => '*', 'month' => '*', 'wday' => '*', 'cmd' => 'echo hi'])
        ->assertRedirect('/cron/create')->assertSessionHas('error', 'Error: invalid min format :: 61')
        ->assertSessionHasInput('cmd', 'echo hi');
});

it('refuses a schedule field outside the crontab grammar before any command (E39 W5)', function () {
    signIn('bob', 'user');

    foreach (['61', '5 * * * * evil', "5'", "5\tx", 'jan'] as $min) {
        $this->from('/cron/create')->post('/cron', ['min' => $min, 'hour' => '*', 'day' => '*', 'month' => '*', 'wday' => '*', 'cmd' => 'echo hi'])
            ->assertSessionHasErrors('min');
    }
    $this->fake->assertNotRan('v-add-cron-job');
});

it('lets an admin add a cron job for another user and warns about its own account', function () {
    $this->fake->on('v-add-cron-job')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/cron/create')->assertOk()->assertSee('strongly advised')->assertSee('Use this owner');
    $this->get('/cron/create?owner=carol')->assertOk()->assertDontSee('strongly advised');
    $this->post('/cron', ['owner' => 'carol', 'min' => '5', 'hour' => '4', 'day' => '*', 'month' => '*', 'wday' => '1', 'cmd' => 'echo carol'])
        ->assertRedirect('/cron?owner=carol');
    $this->fake->assertRan('v-add-cron-job', fn ($a) => $a === ['carol', '5', '4', '*', '*', '1', 'echo carol']);
});

it('does not let a user add a cron job for someone else', function () {
    signIn('bob', 'user');

    $this->get('/cron/create?owner=carol')->assertNotFound();
    $this->post('/cron', ['owner' => 'carol', 'min' => '0', 'hour' => '0', 'day' => '*', 'month' => '*', 'wday' => '*', 'cmd' => 'echo x'])
        ->assertNotFound();
    $this->fake->assertNotRan('v-add-cron-job');
});

// Edit (spec §7.2)

it('shows the edit form with the job values and answers 404 for unknown jobs', function () {
    signIn('bob', 'user');

    $this->get('/cron/bob/1/edit')->assertOk()
        ->assertSee('value="php /home/bob/web/example.com/public_html/cron.php"', false)
        ->assertSee('name="min"', false)->assertSee('System time');
    $this->get('/cron/bob/9/edit')->assertNotFound();
    $this->get('/cron/carol/1/edit')->assertNotFound();
    $this->get('/cron/bob/abc/edit')->assertNotFound();
});

it('saves a cron job with v-change-cron-job', function () {
    $this->fake->on('v-change-cron-job')->returns();
    signIn('bob', 'user');

    $this->post('/cron/bob/1/edit', ['min' => '0', 'hour' => '*/2', 'day' => '*', 'month' => '*', 'wday' => '1,2,3,4,5', 'cmd' => 'php cron.php --quiet'])
        ->assertRedirect('/cron/bob/1/edit')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-cron-job', fn ($a) => $a === ['bob', '1', '0', '*/2', '*', '*', '1,2,3,4,5', 'php cron.php --quiet']);
});

it('requires every field on edit and shows the command error', function () {
    $this->fake->on('v-change-cron-job')->returnsExit(ExitCode::Invalid, 'Error: invalid hour format :: 25');
    signIn('bob', 'user');

    $this->post('/cron/bob/1/edit', ['min' => '0', 'hour' => '', 'day' => '*', 'month' => '*', 'wday' => '*', 'cmd' => 'x'])
        ->assertSessionHasErrors(['hour' => 'Field "Hour" can not be blank.']);
    $this->fake->assertNotRan('v-change-cron-job');

    $this->post('/cron/bob/1/edit', ['min' => '0', 'hour' => '25', 'day' => '*', 'month' => '*', 'wday' => '*', 'cmd' => 'x'])
        ->assertSessionHasErrors('hour');
    $this->fake->assertNotRan('v-change-cron-job');

    $this->post('/cron/bob/1/edit', ['min' => '0', 'hour' => '2', 'day' => '*', 'month' => '*', 'wday' => '*', 'cmd' => 'x'])
        ->assertRedirect('/cron/bob/1/edit')->assertSessionHas('error', 'Error: invalid hour format :: 25');
});

// Single and bulk actions (spec §7.1)

it('suspends, unsuspends and deletes a cron job', function () {
    foreach (['v-suspend-cron-job', 'v-unsuspend-cron-job', 'v-delete-cron-job'] as $command) {
        $this->fake->on($command)->returns();
    }
    signIn('bob', 'user');

    $this->post('/cron/bob/1/suspend')->assertSessionHas('ok', 'Cron job has been suspended.');
    $this->post('/cron/bob/2/unsuspend')->assertSessionHas('ok', 'Cron job has been unsuspended.');
    $this->delete('/cron/bob/1')->assertRedirect('/cron')->assertSessionHas('ok', 'Cron job has been deleted.');
    $this->post('/cron/bob/9/suspend')->assertNotFound();

    $this->fake->assertRan('v-suspend-cron-job', fn ($a) => $a === ['bob', '1']);
    $this->fake->assertRan('v-unsuspend-cron-job', fn ($a) => $a === ['bob', '2']);
    $this->fake->assertRan('v-delete-cron-job', fn ($a) => $a === ['bob', '1']);
    // the single commands restart cron themselves
    $this->fake->assertNotRan('v-restart-cron');
});

it('runs a bulk action with RESTART=no and one cron restart, reporting done and failed items', function () {
    $this->fake->on('v-delete-cron-job')->returns();
    $this->fake->on('v-delete-cron-job', ['bob', '2', 'no'])->returnsExit(ExitCode::NotExist, "Error: cron job 2 doesn't exist");
    signIn('bob', 'user');

    $this->post('/cron/bulk', ['action' => 'delete', 'items' => ['bob/1', 'bob/2']])
        ->assertRedirect('/cron')->assertSessionHas('error', '1 done, 1 failed')
        ->assertSessionHas('error_detail', "bob/2: Error: cron job 2 doesn't exist");

    $this->fake->assertRan('v-delete-cron-job', fn ($a) => $a === ['bob', '1', 'no']);
    $this->fake->assertRan('v-restart-cron', times: 1);
});

it('lets a user suspend in bulk, as single actions do (D10)', function () {
    $this->fake->on('v-suspend-cron-job')->returns();
    signIn('bob', 'user');

    $this->post('/cron/bulk', ['action' => 'suspend', 'items' => ['bob/1']])->assertSessionHas('ok', '1 done, 0 failed');
    $this->fake->assertRan('v-suspend-cron-job', fn ($a) => $a === ['bob', '1', 'no']);
});

it('runs nothing when one bulk item belongs to another user', function () {
    signIn('bob', 'user');

    $this->post('/cron/bulk', ['action' => 'suspend', 'items' => ['bob/1', 'carol/1']])->assertNotFound();
    $this->post('/cron/bulk', ['action' => 'rebuild', 'items' => ['bob/1']])->assertSessionHasErrors(['action']);
    $this->post('/cron/bulk', ['action' => 'delete', 'items' => ['bob/one']])->assertSessionHasErrors(['items.0']);
    $this->fake->assertNotRan('v-suspend-cron-job');
    $this->fake->assertNotRan('v-delete-cron-job');
    $this->fake->assertNotRan('v-restart-cron');
});

it('lets an admin suspend cron jobs of several users', function () {
    $this->fake->on('v-suspend-cron-job')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/cron/bulk?owner=bob', ['action' => 'suspend', 'items' => ['bob/1', 'carol/1']])
        ->assertRedirect('/cron?owner=bob')->assertSessionHas('ok', '2 done, 0 failed');
    $this->fake->assertRan('v-suspend-cron-job', times: 2);
    $this->fake->assertRan('v-restart-cron', times: 1);
});

// E-mail reports (spec §7.1, §11)

it('enables and disables cron reports with success toasts', function () {
    $this->fake->on('v-add-cron-reports', ['bob'])->returns();
    $this->fake->on('v-delete-cron-reports', ['bob'])->returns();
    signIn('bob', 'user');

    $this->post('/cron/bob/reports')->assertRedirect('/cron')
        ->assertSessionHas('ok', 'Cron job email reporting has been successfully enabled.')->assertSessionMissing('error');
    $this->delete('/cron/bob/reports')->assertRedirect('/cron')
        ->assertSessionHas('ok', 'Cron job email reporting has been successfully disabled.');

    $this->fake->assertRan('v-add-cron-reports', fn ($a) => $a === ['bob']);
    $this->fake->assertRan('v-delete-cron-reports', fn ($a) => $a === ['bob']);
});

it('keeps the owner filter and scope on cron reports', function () {
    $this->fake->on('v-add-cron-reports', ['carol'])->returns();
    signIn('arcadmin', 'admin');
    $this->post('/cron/carol/reports?owner=carol')->assertRedirect('/cron?owner=carol')->assertSessionHas('ok');

    signIn('bob', 'user');
    $this->post('/cron/carol/reports')->assertNotFound();
    $this->fake->assertRan('v-add-cron-reports', times: 1);
});

it('shows the command error when reports cannot be changed', function () {
    $this->fake->on('v-add-cron-reports', ['bob'])->returnsExit(ExitCode::Exists, 'Error: CRON_REPORTS is already set');
    signIn('bob', 'user');

    $this->post('/cron/bob/reports')->assertSessionHas('error', 'Error: CRON_REPORTS is already set');
});

// Read-only mode and capabilities

it('refuses every change in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/cron')->assertOk()->assertDontSee('Enable Notifications')->assertDontSee('Add Cron Job');
    $this->get('/cron/bob/1/edit')->assertOk()->assertSee('disabled', false);
    foreach (['edit', 'suspend', 'unsuspend'] as $action) {
        $this->post("/cron/bob/1/{$action}")->assertForbidden();
    }
    $this->delete('/cron/bob/1')->assertForbidden();
    $this->post('/cron/bob/reports')->assertForbidden();
    $this->delete('/cron/bob/reports')->assertForbidden();
    $this->post('/cron/bulk', ['action' => 'delete', 'items' => ['bob/1']])->assertForbidden();
    $this->post('/cron', ['cmd' => 'x'])->assertForbidden();
    $this->fake->assertNotRan('v-delete-cron-job');
});

it('has a capability for every cron route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'cron.'));

    expect($routes)->toContain('cron.index', 'cron.create', 'cron.store', 'cron.bulk', 'cron.edit', 'cron.update',
        'cron.suspend', 'cron.unsuspend', 'cron.destroy', 'cron.reports.enable', 'cron.reports.disable');
    foreach (['cron.view', 'cron.create', 'cron.update', 'cron.delete'] as $capability) {
        expect(Capabilities::names())->toContain($capability);
    }
});
