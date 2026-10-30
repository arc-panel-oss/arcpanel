<?php

use App\Arc\ExitCode;

// Packages with reseller settings (E11 spec §8).

beforeEach(function () {
    $this->fake = fakeArc(['POLICY_RESELLER_CREATE_PACKAGES' => 'yes']);
    fakeResellers($this->fake, ['hostco' => ['WEB_DOMAINS' => '10', 'DISK_QUOTA' => '5000', 'BANDWIDTH' => 'unlimited', 'MAIL_ACCOUNTS' => 'unlimited', 'RATE_LIMIT' => '200', 'BACKUPS' => '3',
        'WEB_TEMPLATE' => 'default', 'BACKEND_TEMPLATE' => 'default', 'PROXY_TEMPLATE' => 'default', 'DNS_TEMPLATE' => 'default']]);
    $this->base = $base = ['WEB_TEMPLATE' => 'default', 'DNS_TEMPLATE' => 'default', 'WEB_DOMAINS' => '1', 'MAIL_ACCOUNTS' => '5', 'DATABASES' => '1',
        'DISK_QUOTA' => '1000', 'BANDWIDTH' => '1000', 'NS' => 'ns1.example.com,ns2.example.com', 'SHELL' => 'nologin',
        'USERS' => '0', 'RESELLER_PACKAGES' => '', 'RESELLER_VISIBLE' => 'no', 'RESELLER_OWNER' => ''];
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => $base,
        'starter' => ['RESELLER_VISIBLE' => 'yes'] + $base,
        'reseller' => ['USERS' => '25', 'RESELLER_PACKAGES' => '*'] + $base,
        'hostco_gold' => ['RESELLER_OWNER' => 'hostco'] + $base,
        'resb_silver' => ['RESELLER_OWNER' => 'resb'] + $base,
        'system' => $base,
    ]);
    $this->fake->on('v-list-reseller-packages', ['hostco', 'json'])->returnsJson(['starter' => [], 'hostco_gold' => []]);
    $this->fake->on('v-list-web-templates', ['json'])->returnsJson(['default', 'wordpress']);
    $this->fake->on('v-list-web-templates-backend', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-web-templates-proxy', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-dns-templates', ['json'])->returnsJson(['default', 'child-ns']);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-list-user-package')->returnsExit(ExitCode::NotExist);
});

function packageInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'business', 'web_template' => 'default', 'dns_template' => 'default', 'shell' => 'nologin',
        'web_domains' => '3', 'web_aliases' => '5', 'dns_domains' => '3', 'dns_records_unlimited' => '1', 'mail_domains' => '3',
        'mail_accounts' => '10', 'databases' => '3', 'cron_jobs' => '3', 'disk_quota' => '2000', 'bandwidth' => '2000',
        'rate_limit' => '200', 'backups' => '1', 'nameservers' => "ns1.example.com\nns2.example.com",
    ], $overrides);
}

it('lists every package but system for an admin, with reseller columns', function () {
    signIn('arcadmin', 'admin');

    $this->get('/packages')->assertOk()
        ->assertSee('starter')->assertSee('hostco_gold')->assertSee('For resellers')
        ->assertDontSee('>system<', false);
});

it('lists only assignable packages to a reseller, own ones editable and without prefix', function () {
    signIn('hostco', 'reseller');

    $this->get('/packages')->assertOk()
        ->assertSee('starter')->assertSee('gold')
        ->assertDontSee('resb_silver')->assertDontSee('For resellers')
        ->assertSee(route('packages.edit', ['package' => 'hostco_gold']), false)
        ->assertDontSee(route('packages.edit', ['package' => 'starter']), false);
    $this->get('/packages/starter/edit')->assertForbidden();
    $this->get('/packages/resb_silver/edit')->assertNotFound();
});

it('keeps users out of packages', function () {
    signIn('bob', 'user');

    $this->get('/packages')->assertForbidden();
    // the package pages refuse before looking for the package
    $this->get('/packages/default/edit')->assertForbidden();
    $this->get('/packages/nosuch/edit')->assertForbidden();
    $this->post('/packages/default/copy', ['name' => 'copy'])->assertForbidden();
    $this->delete('/packages/default')->assertForbidden();
});

it('creates a package with reseller settings for an admin', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    $this->post('/packages', packageInput(['users' => '10', 'reseller_packages' => 'starter, default', 'reseller_visible' => '1']))
        ->assertSessionHasNoErrors()->assertRedirect('/packages')->assertSessionHas('ok');

    $this->fake->assertRan('v-add-user-package', fn ($a) => $a[1] === 'business' && str_contains($a[0], "DNS_RECORDS='unlimited'") && str_contains($a[0], "NS='ns1.example.com,ns2.example.com'"));
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'USERS', '10']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'RESELLER_PACKAGES', 'starter,default']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'RESELLER_VISIBLE', 'yes']);
});

it('refuses unknown templates and a single name server', function () {
    signIn('arcadmin', 'admin');

    $this->post('/packages', packageInput(['web_template' => 'evil', 'nameservers' => 'ns1.example.com']))
        ->assertSessionHasErrors(['web_template', 'nameservers']);
    $this->fake->assertNotRan('v-add-user-package');
});

it('creates a reseller package within the reseller limits', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-reseller-package')->returns();

    $this->get('/packages/create')->assertOk()->assertSee('Your plan: 10')->assertDontSee('Packages the reseller may assign');

    $this->post('/packages', packageInput(['web_domains' => '20', 'disk_quota_unlimited' => '1']))
        ->assertSessionHasErrors(['web_domains', 'disk_quota']);

    $this->post('/packages', packageInput(['users' => '99']))->assertRedirect('/packages');
    $this->fake->assertRan('v-add-reseller-package', fn ($a) => $a[0] === 'hostco' && $a[2] === 'business');
    $this->fake->assertNotRan('v-change-package-tenancy');
});

it('needs the policy for reseller packages', function () {
    $this->fake = fakeArc(['POLICY_RESELLER_CREATE_PACKAGES' => 'no']);
    fakeResellers($this->fake);
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson(['hostco_gold' => ['RESELLER_OWNER' => 'hostco']]);
    $this->fake->on('v-list-reseller-packages', ['hostco', 'json'])->returnsJson(['hostco_gold' => []]);
    signIn('hostco', 'reseller');

    $this->get('/packages')->assertOk()->assertDontSee('Add Package');
    $this->get('/packages/create')->assertForbidden();
    $this->get('/packages/hostco_gold/edit')->assertForbidden();
});

it('saves, renames and applies a package for an admin', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-update-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    $this->fake->on('v-rename-user-package')->returns();
    $this->fake->on('v-rename-package-tenancy')->returns();

    $this->get('/packages/starter/edit')->assertOk()->assertSee('Available to resellers');

    $this->post('/packages/starter', packageInput(['name' => 'basic', 'reseller_visible' => '1']))
        ->assertRedirect('/packages/basic/edit');
    $this->fake->assertRan('v-add-user-package', fn ($a) => $a[1] === 'starter' && $a[2] === 'yes');
    $this->fake->assertRan('v-update-user-package', fn ($a) => $a === ['starter']);
    $this->fake->assertNotRan('v-change-package-tenancy');
    $this->fake->assertRan('v-rename-user-package', fn ($a) => $a === ['starter', 'basic']);
    $this->fake->assertRan('v-rename-package-tenancy', fn ($a) => $a === ['starter', 'basic']);
});

it('changes and deletes its own package for a reseller through the reseller commands', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-change-reseller-package')->returns();
    $this->fake->on('v-delete-reseller-package')->returns();

    $this->post('/packages/hostco_gold', packageInput(['name' => 'other']))->assertRedirect('/packages/hostco_gold/edit');
    $this->fake->assertRan('v-change-reseller-package', fn ($a) => $a[0] === 'hostco' && $a[2] === 'hostco_gold');

    $this->delete('/packages/hostco_gold')->assertRedirect('/packages');
    $this->fake->assertRan('v-delete-reseller-package', fn ($a) => $a === ['hostco', 'hostco_gold']);
});

it('refuses to delete a package in use', function () {
    fakeResellers($this->fake, ['bob' => ['PACKAGE' => 'starter']]);
    signIn('arcadmin', 'admin');

    $this->delete('/packages/default')->assertForbidden();
    $this->delete('/packages/starter')->assertSessionHas('error');
    $this->fake->assertNotRan('v-delete-user-package');
});

it('copies a package with its reseller settings', function () {
    signIn('arcadmin', 'admin');
    $this->fake->on('v-copy-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    $this->post('/packages/reseller/copy', ['name' => 'reseller2'])->assertRedirect('/packages/reseller2/edit');
    $this->fake->assertRan('v-copy-user-package', fn ($a) => $a === ['reseller', 'reseller2']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['reseller2', 'USERS', '25']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['reseller2', 'RESELLER_PACKAGES', '*']);
});

it('sets PORTS of a package, within the reseller plan for a reseller (E12)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->post('/packages', packageInput(['ports' => '5']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'PORTS', '5']);

    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('Your plan: 0');
    $this->post('/packages', packageInput(['ports' => '5']))->assertSessionHasErrors('ports');
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('sets the Node.js keys of a package, within the reseller plan for a reseller (E13)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="node_allowed"', false);
    $this->post('/packages', packageInput(['node' => '1', 'node_versions' => '2', 'node_allowed' => '22,24']))->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([['business', 'NODE_VERSIONS', '2'], ['business', 'NODE_ALLOWED', '22,24'], ['business', 'NODE', 'yes']]);

    $this->post('/packages', packageInput(['name' => 'plain']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a[0] === 'plain', 0);
    $this->post('/packages', packageInput(['node_allowed' => 'lts/*']))->assertSessionHasErrors('node_allowed');

    // the reseller package has no Node.js
    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('Node.js is not included in your plan.');
    $this->post('/packages', packageInput(['node' => '1']))->assertSessionHasErrors('node');
    $this->post('/packages', packageInput(['node_versions_unlimited' => '1']))->assertSessionHasErrors('node_versions');
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('sets NODE_APPS of a package, within the reseller plan for a reseller (E14)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="node_apps"', false);
    $this->post('/packages', packageInput(['node' => '1', 'node_apps' => '5']))->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([['business', 'NODE_APPS', '5'], ['business', 'NODE', 'yes']]);
    $this->post('/packages', packageInput(['name' => 'apps', 'node_apps_unlimited' => '1']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['apps', 'NODE_APPS', 'unlimited']);
    $this->post('/packages', packageInput(['node_apps' => '-1']))->assertSessionHasErrors('node_apps');

    // the reseller package has NODE_APPS 3 (the default)
    signIn('hostco', 'reseller');
    $this->post('/packages', packageInput(['node_apps' => '4']))->assertSessionHasErrors('node_apps');
    $this->post('/packages', packageInput(['node_apps_unlimited' => '1']))->assertSessionHasErrors('node_apps');
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('sets the Docker keys of a package, within the reseller plan for a reseller (E4)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="docker_apps"', false)->assertSee('name="docker_memory"', false);
    $this->post('/packages', packageInput(['docker' => '1', 'docker_apps' => '2', 'docker_cpus' => '1.5', 'docker_memory' => '1G']))
        ->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([
        ['business', 'DOCKER_APPS', '2'], ['business', 'DOCKER_CPUS', '1.5'], ['business', 'DOCKER_MEMORY', '1G'], ['business', 'DOCKER', 'yes'],
    ]);
    $this->post('/packages', packageInput(['docker_cpus' => 'all']))->assertSessionHasErrors('docker_cpus');
    $this->post('/packages', packageInput(['docker_memory' => '1T']))->assertSessionHasErrors('docker_memory');

    // the reseller package has no Docker apps
    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('Docker apps are not included in your plan.');
    $this->post('/packages', packageInput(['docker' => '1']))->assertSessionHasErrors('docker');
    $this->post('/packages', packageInput(['docker_apps' => '1']))->assertSessionHasErrors('docker_apps');
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('sets the app process keys of a package, within the reseller plan for a reseller (E42)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="app_processes"', false)->assertSee('name="app_processes_cpus"', false);
    $this->post('/packages', packageInput(['app_processes' => '1', 'app_processes_max' => '5', 'app_processes_cpus' => '0.5', 'app_processes_memory' => '768M']))
        ->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([
        ['business', 'APP_PROCESSES_MAX', '5'], ['business', 'APP_PROCESSES_MEMORY', '768M'], ['business', 'APP_PROCESSES_CPUS', '0.5'], ['business', 'APP_PROCESSES', 'yes'],
    ]);
    $this->post('/packages', packageInput(['app_processes_cpus' => 'all']))->assertSessionHasErrors('app_processes_cpus');
    $this->post('/packages', packageInput(['app_processes_memory' => '1T']))->assertSessionHasErrors('app_processes_memory');

    // the reseller package has no app processes
    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('App processes are not included in your plan.');
    $this->post('/packages', packageInput(['app_processes' => '1']))->assertSessionHasErrors('app_processes');
    $this->post('/packages', packageInput(['app_processes_max' => '4']))->assertSessionHasErrors('app_processes_max');
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('sets the object storage keys of a package, within the reseller plan for a reseller (E44)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="object_storage"', false)->assertSee('name="object_storage_quota"', false);
    $this->post('/packages', packageInput(['object_storage' => '1', 'object_storage_buckets' => '5', 'object_storage_quota' => '20G', 'object_storage_backup' => '1']))
        ->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([
        ['business', 'OBJECT_STORAGE_BUCKETS', '5'], ['business', 'OBJECT_STORAGE_QUOTA', '20G'], ['business', 'OBJECT_STORAGE_BACKUP', 'yes'], ['business', 'OBJECT_STORAGE', 'yes'],
    ]);
    $this->post('/packages', packageInput(['object_storage_quota' => '20 GB']))->assertSessionHasErrors('object_storage_quota');

    // the reseller package has no object storage
    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('Object storage is not included in your plan.');
    $this->post('/packages', packageInput(['object_storage' => '1']))->assertSessionHasErrors('object_storage');
    $this->post('/packages', packageInput(['object_storage_buckets' => '4']))->assertSessionHasErrors('object_storage_buckets');
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('sets how often scheduled jobs back up the accounts of a package, at most as often as the reseller plan (E48)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="backup_frequency"', false)->assertSee('Backup frequency at most');
    $this->post('/packages', packageInput(['backup_frequency' => 'hourly']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'BACKUP_FREQUENCY', 'hourly']);
    $this->post('/packages', packageInput(['backup_frequency' => 'minutely']))->assertSessionHasErrors('backup_frequency');

    // the reseller plan backs up daily: hourly goes beyond it, weekly does not
    signIn('hostco', 'reseller');
    $this->post('/packages', packageInput(['backup_frequency' => 'hourly']))->assertSessionHasErrors(['backup_frequency' => 'At most daily on your plan.']);
    $this->fake->assertNotRan('v-add-reseller-package');
    $this->post('/packages', packageInput(['backup_frequency' => 'weekly']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['hostco_business', 'BACKUP_FREQUENCY', 'weekly']);
});

it('sets the PHP keys of a package with the default profile kept allowed at each step (E41)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-update-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="php_group"', false)
        ->assertSee('PHP 8.2')->assertSee('security fixes only')->assertSee('name="php_profiles[]"', false)->assertSee('value="medium"', false);

    // every version, two profiles with medium as the default
    $this->post('/packages', packageInput(['php_group' => '1', 'php_versions_all' => '1', 'php_profiles' => ['medium', 'small'], 'php_default_profile' => 'medium']))
        ->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([
        ['business', 'PHP_PROFILES', 'medium,small,default'],
        ['business', 'PHP_DEFAULT_PROFILE', 'medium'],
        ['business', 'PHP_PROFILES', 'medium,small'],
    ]);

    // listed versions; the defaults stay untouched
    $this->post('/packages', packageInput(['name' => 'pinned', 'php_group' => '1', 'php_versions' => ['8.4', '8.3'], 'php_profiles' => ['default'], 'php_default_profile' => 'default']))
        ->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['pinned', 'PHP_VERSIONS', '8.3,8.4']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a[0] === 'pinned' && $a[1] !== 'PHP_VERSIONS', 0);

    $this->post('/packages', packageInput(['php_group' => '1', 'php_profiles' => ['small'], 'php_default_profile' => 'small']))->assertSessionHasErrors('php_versions');
    $this->post('/packages', packageInput(['php_group' => '1', 'php_versions_all' => '1', 'php_profiles' => ['small'], 'php_default_profile' => 'medium']))->assertSessionHasErrors('php_default_profile');
    $this->post('/packages', packageInput(['php_group' => '1', 'php_versions' => ['8.3;id'], 'php_profiles_all' => '1', 'php_default_profile' => 'default']))->assertSessionHasErrors('php_versions.0');
});

it('limits the PHP of a reseller package to the reseller plan (E41 D12)', function () {
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'reseller' => ['USERS' => '25', 'RESELLER_PACKAGES' => '*', 'PHP_VERSIONS' => '8.4', 'PHP_PROFILES' => 'default,small', 'PHP_DEFAULT_PROFILE' => 'default'],
        'starter' => ['RESELLER_VISIBLE' => 'yes'],
        'hostco_gold' => ['RESELLER_OWNER' => 'hostco'],
    ]);

    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('Your plan: versions 8.4, profiles default,small.')
        ->assertSee('value="8.4"', false)->assertDontSee('value="8.2"', false)->assertDontSee('name="php_versions_all"', false)
        ->assertDontSee('value="medium"', false);
    $this->post('/packages', packageInput(['php_group' => '1', 'php_versions' => ['8.3'], 'php_profiles' => ['default'], 'php_default_profile' => 'default']))
        ->assertSessionHasErrors('php_versions');
    $this->post('/packages', packageInput(['php_group' => '1', 'php_versions_all' => '1', 'php_profiles_all' => '1', 'php_default_profile' => 'default']))
        ->assertSessionHasErrors(['php_versions', 'php_profiles']);
    $this->fake->assertNotRan('v-add-reseller-package');

    $this->post('/packages', packageInput(['php_group' => '1', 'php_versions' => ['8.4'], 'php_profiles' => ['default', 'small'], 'php_default_profile' => 'small']))
        ->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['hostco_business', 'PHP_VERSIONS', '8.4']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['hostco_business', 'PHP_DEFAULT_PROFILE', 'small']);
});

it('sets the maximums of the per-domain PHP settings of a package (E19)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    $php = ['php_group' => '1', 'php_versions_all' => '1', 'php_profiles' => ['default'], 'php_default_profile' => 'default'];

    signIn('arcadmin', 'admin');
    $this->get('/packages/create')->assertOk()->assertSee('name="php_max_memory"', false)->assertSee('name="php_max_upload"', false)
        ->assertSee('name="php_max_execution_time"', false)->assertSee('name="php_max_input_vars"', false)->assertSee('value="512M"', false);
    $this->post('/packages', packageInput($php + ['php_max_memory' => '1G', 'php_max_upload' => 'unlimited', 'php_max_execution_time' => '300', 'php_max_input_vars' => '20000']))
        ->assertRedirect('/packages');
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->filter(fn ($a) => str_starts_with($a[1], 'PHP_MAX_'))->values()->all();
    // the default maximum of execution time needs no step
    expect($steps)->toBe([
        ['business', 'PHP_MAX_MEMORY', '1G'], ['business', 'PHP_MAX_UPLOAD', 'unlimited'], ['business', 'PHP_MAX_INPUT_VARS', '20000'],
    ]);
    foreach (['php_max_memory' => '1T', 'php_max_upload' => '-1', 'php_max_execution_time' => '0', 'php_max_input_vars' => 'all'] as $field => $value) {
        $this->post('/packages', packageInput($php + [$field => $value]))->assertSessionHasErrors($field);
    }
    $this->post('/packages', packageInput($php + ['php_max_execution_time' => '86401']))->assertSessionHasErrors('php_max_execution_time');
});

it('keeps the PHP maximums of a reseller package within the reseller plan (E19)', function () {
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'reseller' => ['USERS' => '25', 'RESELLER_PACKAGES' => '*', 'PHP_VERSIONS' => '*', 'PHP_PROFILES' => '*', 'PHP_DEFAULT_PROFILE' => 'default', 'PHP_MAX_MEMORY' => '256M'],
        'starter' => ['RESELLER_VISIBLE' => 'yes'],
    ]);
    $php = ['php_group' => '1', 'php_versions_all' => '1', 'php_profiles_all' => '1', 'php_default_profile' => 'default'];

    signIn('hostco', 'reseller');
    $this->get('/packages/create')->assertOk()->assertSee('Your plan: 256M');
    $this->post('/packages', packageInput($php + ['php_max_memory' => '512M']))->assertSessionHasErrors('php_max_memory');
    // without the key the reseller's plan has the default maximum
    $this->post('/packages', packageInput($php + ['php_max_memory' => '128M', 'php_max_input_vars' => 'unlimited']))->assertSessionHasErrors('php_max_input_vars');
    $this->fake->assertNotRan('v-add-reseller-package');

    $this->post('/packages', packageInput($php + ['php_max_memory' => '128M', 'php_max_upload' => '64M']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['hostco_business', 'PHP_MAX_MEMORY', '128M']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['hostco_business', 'PHP_MAX_UPLOAD', '64M']);
});

it('keeps a reseller package to the shells and templates of the plan (E39 W4 §8.1)', function () {
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-reseller-package')->returns();

    // Shells from POLICY_RESELLER_PACKAGE_SHELLS (nologin by default), the reseller's own templates
    $this->get('/packages/create')->assertOk()
        ->assertDontSee('<option value="bash"', false)->assertDontSee('<option value="wordpress"', false);
    $this->post('/packages', packageInput(['shell' => 'bash', 'web_template' => 'wordpress', 'dns_template' => 'child-ns']))
        ->assertSessionHasErrors(['shell' => 'Your plan does not allow this value.', 'web_template', 'dns_template']);
    $this->fake->assertNotRan('v-add-reseller-package');
});

it('lets a reseller package use the shells of the setting and any template while users may choose them', function () {
    withSysConfig($this->fake, ['POLICY_RESELLER_CREATE_PACKAGES' => 'yes', 'POLICY_RESELLER_PACKAGE_SHELLS' => 'nologin,bash',
        'POLICY_USER_EDIT_WEB_TEMPLATES' => 'yes', 'POLICY_USER_EDIT_DNS_TEMPLATES' => 'yes']);
    signIn('hostco', 'reseller');
    $this->fake->on('v-add-reseller-package')->returns();

    $this->get('/packages/create')->assertOk()->assertSee('<option value="bash"', false);
    $this->post('/packages', packageInput(['shell' => 'bash', 'web_template' => 'wordpress', 'dns_template' => 'child-ns']))
        ->assertSessionHasNoErrors()->assertRedirect('/packages');
    $this->fake->assertRan('v-add-reseller-package', fn ($a) => str_contains($a[1], "SHELL='bash'") && str_contains($a[1], "WEB_TEMPLATE='wordpress'"));
});

it('lets a reseller package keep the shell it has', function () {
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => $this->base, 'starter' => $this->base, 'reseller' => $this->base,
        'hostco_gold' => ['RESELLER_OWNER' => 'hostco', 'SHELL' => 'bash'] + $this->base,
    ]);
    $this->fake->on('v-change-reseller-package')->returns();
    signIn('hostco', 'reseller');

    $this->get('/packages/hostco_gold/edit')->assertOk()->assertSee('<option value="bash"', false);
    $this->post('/packages/hostco_gold', packageInput(['shell' => 'bash']))->assertSessionHasNoErrors();
    $this->fake->assertRan('v-change-reseller-package', fn ($a) => str_contains($a[1], "SHELL='bash'"));
});

it('compares the limits of a reseller package after their units (E39 W4 §8.2)', function () {
    fakeResellers($this->fake, ['hostco' => ['WEB_DOMAINS' => '10', 'WEB_TEMPLATE' => 'default', 'BACKEND_TEMPLATE' => 'default',
        'PROXY_TEMPLATE' => 'default', 'DNS_TEMPLATE' => 'default', 'BACKUPS_INCREMENTAL' => 'no']]);
    // v-list-user leaves out the cgroup limits and RATE_LIMIT: they come from the reseller's package
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => $this->base, 'starter' => $this->base,
        'reseller' => ['MEMORY_LIMIT' => '1G', 'CPU_QUOTA' => '100%', 'RATE_LIMIT' => '100'] + $this->base,
    ]);
    withSysConfig($this->fake, ['POLICY_RESELLER_CREATE_PACKAGES' => 'yes', 'RESOURCES_LIMIT' => 'yes']);
    $this->fake->on('v-add-reseller-package')->returns();
    signIn('hostco', 'reseller');

    $this->post('/packages', packageInput(['memory_limit' => '2048M', 'cpu_quota' => '150%', 'rate_limit' => '200', 'backups_incremental' => '1']))
        ->assertSessionHasErrors(['memory_limit' => 'At most 1G on your plan.', 'cpu_quota', 'rate_limit',
            'backups_incremental' => 'Incremental backups are not included in your plan.']);
    $this->post('/packages', packageInput(['memory_limit' => '1024M', 'cpu_quota' => '50%', 'rate_limit' => '100']))
        ->assertSessionHasNoErrors()->assertRedirect('/packages');
});

it('switches Git Deploy of a package, on when the key is missing (E20 D13)', function () {
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-update-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();

    signIn('arcadmin', 'admin');
    $page = $this->get('/packages/create')->assertOk()->assertSee('name="git_deploy_group"', false)->assertSee('Git Deploy')->getContent();
    preg_match('/<input[^>]*name="git_deploy"[^>]*>/', $page, $switch);
    expect($switch[0] ?? '')->toContain('checked');

    // On by default: no step; turned off: GIT_DEPLOY no
    $this->post('/packages', packageInput(['git_deploy_group' => '1', 'git_deploy' => '1']))->assertRedirect('/packages');
    $this->fake->assertNotRan('v-change-package-tenancy');
    $this->post('/packages', packageInput(['git_deploy_group' => '1']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'GIT_DEPLOY', 'no'], 1);

    // A form without the switch keeps the key
    $this->post('/packages/starter', packageInput(['name' => 'starter']))->assertRedirect('/packages/starter/edit');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a[1] === 'GIT_DEPLOY', 1);
});

it('shows Git Deploy of a package off and turns it back on', function () {
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => $this->base,
        'starter' => ['GIT_DEPLOY' => 'no'] + $this->base,
    ]);
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-update-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    signIn('arcadmin', 'admin');

    $page = $this->get('/packages/starter/edit')->assertOk()->getContent();
    preg_match('/<input[^>]*name="git_deploy"[^>]*>/', $page, $switch);
    expect($switch[0] ?? '')->toContain('name="git_deploy"')->not->toContain('checked');
    $this->post('/packages/starter', packageInput(['name' => 'starter', 'git_deploy_group' => '1', 'git_deploy' => '1']))->assertRedirect('/packages/starter/edit');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['starter', 'GIT_DEPLOY', 'yes'], 1);
});

it('keeps Git Deploy within the reseller plan for a reseller (E20 D13)', function () {
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => $this->base,
        'starter' => ['RESELLER_VISIBLE' => 'yes'] + $this->base,
        'reseller' => ['USERS' => '25', 'RESELLER_PACKAGES' => '*', 'GIT_DEPLOY' => 'no'] + $this->base,
        'hostco_gold' => ['RESELLER_OWNER' => 'hostco'] + $this->base,
    ]);
    $this->fake->on('v-add-reseller-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    signIn('hostco', 'reseller');

    $page = $this->get('/packages/create')->assertOk()->assertSee('Git Deploy is not included in your plan.')->getContent();
    preg_match('/<input[^>]*name="git_deploy"[^>]*>/', $page, $switch);
    expect($switch[0] ?? '')->toContain('disabled');
    $this->post('/packages', packageInput(['git_deploy_group' => '1', 'git_deploy' => '1']))
        ->assertSessionHasErrors(['git_deploy' => 'Git Deploy is not included in your plan.']);
    $this->fake->assertNotRan('v-add-reseller-package');

    // The disabled switch sends nothing: the new package has it off
    $this->post('/packages', packageInput(['git_deploy_group' => '1']))->assertRedirect('/packages');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['hostco_business', 'GIT_DEPLOY', 'no'], 1);
});

it('copies Git Deploy turned off with the package', function () {
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => $this->base,
        'starter' => ['GIT_DEPLOY' => 'no'] + $this->base,
    ]);
    $this->fake->on('v-copy-user-package')->returns();
    $this->fake->on('v-change-package-tenancy')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/packages/starter/copy', ['name' => 'starter2'])->assertRedirect('/packages/starter2/edit');
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['starter2', 'GIT_DEPLOY', 'no'], 1);
});
