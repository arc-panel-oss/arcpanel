<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// The Git Deploy card of Server settings → Web (E20 spec §3.4, §7,
// docs/features/specs/2026-10-29-001-e20-git-deploy-design.md).

/** `v-list-sys-deploy json` */
function deployServerFixture(array $overrides = []): array
{
    return array_merge([
        'DEPLOY_PORT' => '8088',
        'DEPLOY_MAX_ARTIFACT' => '512M',
        'DEPLOY_BUILD_TIMEOUT' => '900',
        'DEPLOY_OIDC_ISSUERS' => 'https://token.actions.githubusercontent.com,https://gitlab.com,https://gitlab.acme.dev',
        'DOMAINS' => '3',
        'PORT_OPEN' => 'yes',
    ], $overrides);
}

/** The form as the card sends it, unchanged. */
function deployForm(array $overrides = []): array
{
    return array_merge(['port' => '8088', 'max_artifact' => '512M', 'timeout' => '15', 'new_issuer' => ''], $overrides);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-info')->returnsJson(['sysinfo' => ['HOSTNAME' => 'srv1.example.net', 'UPTIME' => '10', 'LOADAVERAGE' => '0 / 0 / 0']]);
    $this->fake->on('v-list-sys-php-versions')->returnsJson(['8.4' => ['INSTALLED' => 'yes', 'DEFAULT' => 'yes', 'USED_BY' => '']]);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-list-sys-deploy')->returnsJson(deployServerFixture());
    $this->fake->on('v-change-sys-deploy')->returns();
});

it('shows the Git Deploy card on the Web tab', function () {
    signIn('arcadmin', 'admin');

    $page = $this->get('/server/settings/web')->assertOk()
        ->assertSee('Git Deploy')->assertSee('Port 8088 open · 3 domains')
        ->assertSee('name="port"', false)->assertSee('value="8088"', false)
        ->assertSee('value="512M"', false)
        // 900 seconds are shown as 15 minutes
        ->assertSee('name="timeout"', false)->assertSee('value="15"', false)
        ->assertSee('GitHub Actions')->assertSee('https://token.actions.githubusercontent.com')
        ->assertSee('GitLab.com')->assertSee('Built in')
        ->assertSee('gitlab.acme.dev')->assertSee('Remove gitlab.acme.dev')
        ->assertSee('name="new_issuer"', false)
        ->assertSee(route('server.settings.deploy'), false)
        ->getContent();
    // Built-in providers have no remove button
    expect(substr_count($page, 'name="issuer"'))->toBe(1);
});

it('tells when the deploy port is closed or turned off', function () {
    signIn('arcadmin', 'admin');

    $this->fake->on('v-list-sys-deploy')->returnsJson(deployServerFixture(['DOMAINS' => '0', 'PORT_OPEN' => 'no']));
    $this->get('/server/settings/web')->assertOk()->assertSee('Port 8088 closed · no domain uses Git Deploy');

    $this->fake->on('v-list-sys-deploy')->returnsJson(deployServerFixture(['DEPLOY_PORT' => '', 'PORT_OPEN' => 'no']));
    $this->get('/server/settings/web')->assertOk()->assertSee('Endpoint turned off');
});

it('keeps the Web tab when the deploy settings cannot be read', function () {
    $this->fake->on('v-list-sys-deploy')->returnsExit(ExitCode::Update);
    signIn('arcadmin', 'admin');

    $this->get('/server/settings/web')->assertOk()->assertSee('Default PHP Version')->assertDontSee('name="new_issuer"', false);
});

it('keeps the card for administrators and refuses changes in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/settings/web')->assertForbidden();
        $this->post('/server/settings/web/deploy', deployForm(['port' => '8090']))->assertForbidden();
        $this->delete('/server/settings/web/deploy/issuers', ['issuer' => 'https://gitlab.acme.dev'])->assertForbidden();
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    $this->get('/server/settings/web')->assertOk()->assertDontSee('Add provider')->assertDontSee('Remove gitlab.acme.dev');
    $this->post('/server/settings/web/deploy', deployForm(['port' => '8090']))->assertForbidden();
    $this->delete('/server/settings/web/deploy/issuers', ['issuer' => 'https://gitlab.acme.dev'])->assertForbidden();
    $this->fake->assertNotRan('v-change-sys-deploy');
});

it('runs v-change-sys-deploy only for the settings that changed', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web/deploy', deployForm())
        ->assertRedirect('/server/settings/web')->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-change-sys-deploy');

    $this->post('/server/settings/web/deploy', deployForm(['port' => '8090']))->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-deploy', fn ($a) => $a === ['DEPLOY_PORT', '8090'], 1);
    $this->fake->assertRan('v-change-sys-deploy', null, 1);

    $this->post('/server/settings/web/deploy', deployForm(['max_artifact' => '1g', 'timeout' => '30']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-deploy', fn ($a) => $a === ['DEPLOY_MAX_ARTIFACT', '1G'], 1);
    $this->fake->assertRan('v-change-sys-deploy', fn ($a) => $a === ['DEPLOY_BUILD_TIMEOUT', '1800'], 1);
    $this->fake->assertRan('v-change-sys-deploy', null, 3);
});

it('turns the endpoint off with an empty port', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web/deploy', deployForm(['port' => '']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-deploy', fn ($a) => $a === ['DEPLOY_PORT', ''], 1);
});

it('keeps a build timeout that is not whole minutes while the minutes stay', function () {
    $this->fake->on('v-list-sys-deploy')->returnsJson(deployServerFixture(['DEPLOY_BUILD_TIMEOUT' => '90']));
    signIn('arcadmin', 'admin');

    $this->get('/server/settings/web')->assertOk()->assertSee('value="2"', false);
    $this->post('/server/settings/web/deploy', deployForm(['timeout' => '2']))->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertNotRan('v-change-sys-deploy');
});

it('adds a CI provider to the list of issuers', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web/deploy', deployForm(['new_issuer' => 'https://github.example.com/_services/token/']))->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-deploy', fn ($a) => $a === ['DEPLOY_OIDC_ISSUERS',
        'https://token.actions.githubusercontent.com,https://gitlab.com,https://gitlab.acme.dev,https://github.example.com/_services/token'], 1);

    // A provider already in the list changes nothing
    $this->post('/server/settings/web/deploy', deployForm(['new_issuer' => 'https://gitlab.com']))->assertSessionHas('ok', 'Nothing has changed.');
    $this->fake->assertRan('v-change-sys-deploy', null, 1);
});

it('removes a CI provider the administrator added, but not a built-in one', function () {
    signIn('arcadmin', 'admin');

    $this->delete('/server/settings/web/deploy/issuers', ['issuer' => 'https://gitlab.acme.dev'])
        ->assertRedirect('/server/settings/web')->assertSessionHas('ok');
    $this->fake->assertRan('v-change-sys-deploy', fn ($a) => $a === ['DEPLOY_OIDC_ISSUERS', 'https://token.actions.githubusercontent.com,https://gitlab.com'], 1);

    $this->delete('/server/settings/web/deploy/issuers', ['issuer' => 'https://gitlab.com'])->assertSessionHasErrors('issuer');
    $this->delete('/server/settings/web/deploy/issuers', ['issuer' => 'https://nowhere.example'])->assertSessionHasErrors('issuer');
    $this->delete('/server/settings/web/deploy/issuers', [])->assertSessionHasErrors('issuer');
    $this->fake->assertRan('v-change-sys-deploy', null, 1);
});

it('refuses settings the command would refuse', function (array $input, string $field) {
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web/deploy', deployForm($input))->assertSessionHasErrors($field);
    $this->fake->assertNotRan('v-change-sys-deploy');
})->with([
    'port below 1024' => [['port' => '80'], 'port'],
    'port above 65535' => [['port' => '70000'], 'port'],
    'port not a number' => [['port' => 'abc'], 'port'],
    'upload without a unit' => [['max_artifact' => '512'], 'max_artifact'],
    'upload too large' => [['max_artifact' => '2048M'], 'max_artifact'],
    'upload too large in G' => [['max_artifact' => '2G'], 'max_artifact'],
    'upload of zero' => [['max_artifact' => '0M'], 'max_artifact'],
    'upload missing' => [['max_artifact' => ''], 'max_artifact'],
    'timeout below a minute' => [['timeout' => '0'], 'timeout'],
    'timeout above two hours' => [['timeout' => '121'], 'timeout'],
    'issuer over http' => [['new_issuer' => 'http://gitlab.acme.dev'], 'new_issuer'],
    'issuer with a query' => [['new_issuer' => 'https://gitlab.acme.dev/?x=1'], 'new_issuer'],
]);

it('reports a refusal of the command', function () {
    $this->fake->on('v-change-sys-deploy')->returnsExit(ExitCode::Exists, 'Error: port 8090 is reserved');
    signIn('arcadmin', 'admin');

    $this->post('/server/settings/web/deploy', deployForm(['port' => '8090']))
        ->assertRedirect('/server/settings/web')->assertSessionHas('error')->assertSessionHasInput('port', '8090');
});

it('routes the card through the server settings capability with not-readonly', function () {
    foreach (['server.settings.deploy', 'server.settings.deploy.issuers.destroy'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('not-readonly');
    }
    expect(Capabilities::names())->toContain('server.settings');
});
