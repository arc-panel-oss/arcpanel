<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// System packages page (E5 spec 2026-10-06-006 §7).

function packageRow(string $slug, string $name, string $category, array $extra = []): array
{
    return array_replace([
        'SLUG' => $slug, 'NAME' => $name, 'SUMMARY' => "$name summary", 'CATEGORY' => $category,
        'PROVIDES' => [], 'PACKAGES' => [$slug], 'AVAILABLE' => 'yes', 'STATUS' => 'missing', 'SOURCE' => '-',
        'VERSION' => '', 'UPGRADABLE' => 'no', 'DOMAINS' => [], 'USED_BY' => 0,
    ], $extra);
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-packages')->returnsJson([
        packageRow('python', 'Python 3', 'runtime', ['PROVIDES' => ['python'], 'STATUS' => 'installed', 'SOURCE' => 'arcpanel',
            'VERSION' => '3.12.3', 'UPGRADABLE' => 'yes', 'DOMAINS' => ['bob/app.example'], 'USED_BY' => 1]),
        packageRow('ruby', 'Ruby', 'runtime', ['PROVIDES' => ['ruby'], 'STATUS' => 'installed', 'SOURCE' => 'arcpanel', 'VERSION' => '3.2.3']),
        packageRow('go', 'Go', 'runtime', ['PROVIDES' => ['go'], 'AVAILABLE' => 'no', 'PACKAGES' => []]),
        packageRow('imagemagick', 'ImageMagick', 'media', ['STATUS' => 'installed', 'SOURCE' => 'system', 'VERSION' => '6.9.12']),
        packageRow('ffmpeg', 'FFmpeg', 'media', ['STATUS' => 'partial', 'SOURCE' => 'system']),
        packageRow('build-tools', 'Build tools', 'tooling'),
    ]);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
});

it('lists the catalog by category with state, version and usage', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/packages')->assertOk()
        ->assertSeeInOrder(['Runtimes', 'Python 3', '3.12.3', 'Update available', 'Provides: Python', 'Used by 1 web domain', 'Ruby', 'Go', 'Not available on this release', 'Media', 'ImageMagick', 'Installed by the system', 'FFmpeg', 'Partly installed', 'Tools', 'Build tools', 'Not installed'])
        ->assertSee('Install Build tools from the distribution&#039;s archive now?', false)
        ->assertSee('Install FFmpeg from the distribution&#039;s archive now?', false)
        ->assertDontSee('Install Python 3 from')
        ->assertDontSee('Install Go from')
        ->assertSee('Remove Ruby? Packages that were on the server before stay installed.')
        ->assertDontSee('Remove Python 3?')
        ->assertSee('Needed by web domains')
        ->assertDontSee('Remove ImageMagick?');
});

it('starts an install or a removal as the system task', function () {
    $this->fake->on('v-start-sys-task')->returns("1\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/packages/build-tools/add')
        ->assertRedirect('/server/packages')->assertSessionHas('ok', 'Build tools is being installed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['package', 'build-tools', 'add']);

    $this->post('/server/packages/ruby/delete')->assertSessionHas('ok', 'Ruby is being removed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['package', 'ruby', 'delete']);
});

it('shows the refusal of the command or of a running task', function () {
    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::Forbidden, 'Error: system package python is needed by web domains: bob/app.example');
    signIn('arcadmin', 'admin');
    $this->post('/server/packages/python/delete')->assertSessionHas('error', 'Error: system package python is needed by web domains: bob/app.example');

    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::InUse, 'Error: Another system task is running');
    $this->post('/server/packages/build-tools/add')->assertSessionHas('error', 'Another task is running. Wait until it has finished.');
});

it('answers 404 for a slug outside the catalog and for an unknown action', function () {
    signIn('arcadmin', 'admin');

    $this->post('/server/packages/redis/add')->assertNotFound();
    $this->post('/server/packages/python/purge')->assertNotFound();
    $this->post('/server/packages/Py;id/add')->assertNotFound();
    $this->fake->assertNotRan('v-start-sys-task');
});

it('keeps the page for administrators and read-only in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller'], ['resb', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/packages')->assertForbidden();
        $this->post('/server/packages/build-tools/add')->assertForbidden();
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    $this->get('/server/packages')->assertOk()->assertSee('Build tools')->assertDontSee('Install Build tools from');
    $this->post('/server/packages/build-tools/add')->assertForbidden();
    $this->fake->assertNotRan('v-start-sys-task');
});

it('shows the page in the server navigation of administrators only', function () {
    signIn('arcadmin', 'admin');
    // One sidebar item, Updates, with the tabs ArcPanel and System packages (sidebar hubs spec H4)
    $this->get('/server/packages')
        ->assertSee('<nav class="ap-tabs ap-hub-tabs" aria-label="Updates">', false)
        ->assertSee('<a class="ap-tab" href="'.route('server.updates').'">ArcPanel</a>', false)
        ->assertSee('<a class="ap-tab" href="'.route('server.packages').'" aria-current="page">System packages</a>', false);

    fakeResellers($this->fake);
    signIn('hostco', 'reseller');
    $this->get('/web/domains')->assertDontSee('href="'.route('server.packages').'"', false);
});

it('has a capability for every packages route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.packages'));

    expect($routes)->toHaveCount(2)->and(Capabilities::names())->toContain('server.packages');
});
