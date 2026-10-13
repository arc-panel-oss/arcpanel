<?php

use App\Arc\ExitCode;

// Packages with reseller settings (E11 spec §8).

beforeEach(function () {
    $this->fake = fakeArc(['POLICY_RESELLER_CREATE_PACKAGES' => 'yes']);
    fakeResellers($this->fake, ['hostco' => ['WEB_DOMAINS' => '10', 'DISK_QUOTA' => '5000', 'BANDWIDTH' => 'unlimited', 'MAIL_ACCOUNTS' => 'unlimited', 'RATE_LIMIT' => '200', 'BACKUPS' => '3']]);
    $base = ['WEB_TEMPLATE' => 'default', 'DNS_TEMPLATE' => 'default', 'WEB_DOMAINS' => '1', 'MAIL_ACCOUNTS' => '5', 'DATABASES' => '1',
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
