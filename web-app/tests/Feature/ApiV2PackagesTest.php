<?php

use App\Arc\ExitCode;

// REST API v2, packages (E34 spec §6.2, E11 spec §8): hostco (reseller A)
// may assign starter and its own hostco_gold, resb (reseller B) starter and
// its own resb_silver.

beforeEach(function () {
    $this->fake = fakeApi(['POLICY_RESELLER_CREATE_PACKAGES' => 'yes']);
    fakeResellers($this->fake, ['hostco' => ['WEB_DOMAINS' => '10', 'DISK_QUOTA' => '5000', 'BANDWIDTH' => 'unlimited', 'MAIL_ACCOUNTS' => 'unlimited', 'RATE_LIMIT' => '200', 'BACKUPS' => '3']]);
    $base = ['WEB_TEMPLATE' => 'default', 'DNS_TEMPLATE' => 'default', 'WEB_DOMAINS' => '1', 'MAIL_ACCOUNTS' => '5', 'DATABASES' => '1',
        'DISK_QUOTA' => '1000', 'BANDWIDTH' => '1000', 'DNS_RECORDS' => 'unlimited', 'NS' => 'ns1.example.com,ns2.example.com', 'SHELL' => 'nologin',
        'USERS' => '0', 'RESELLER_PACKAGES' => '', 'RESELLER_VISIBLE' => 'no', 'RESELLER_OWNER' => '', 'RATE_LIMIT' => '200', 'BACKUPS' => '1',
        'DATE' => '2026-09-01', 'TIME' => '10:00:00'];
    $this->packages = [
        'default' => $base,
        'starter' => ['RESELLER_VISIBLE' => 'yes'] + $base,
        'reseller' => ['USERS' => '25', 'RESELLER_PACKAGES' => '*', 'PORTS' => 'unlimited'] + $base,
        'hostco_gold' => ['RESELLER_OWNER' => 'hostco'] + $base,
        'resb_silver' => ['RESELLER_OWNER' => 'resb'] + $base,
        'system' => $base,
    ];
    // Packages added by a test appear in the list from then on
    $this->fake->on('v-list-package-tenancy')->using(fn (array $args) => [0, (string) json_encode($args[0] === 'all'
        ? $this->packages
        : array_intersect_key($this->packages, [$args[0] => true]))]);
    $this->fake->on('v-list-reseller-packages', ['hostco', 'json'])->returnsJson(['starter' => [], 'hostco_gold' => []]);
    $this->fake->on('v-list-reseller-packages', ['resb', 'json'])->returnsJson(['starter' => [], 'resb_silver' => []]);
    $this->fake->on('v-list-web-templates', ['json'])->returnsJson(['default', 'wordpress']);
    $this->fake->on('v-list-web-templates-backend', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-web-templates-proxy', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-dns-templates', ['json'])->returnsJson(['default', 'child-ns']);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-list-user-package')->using(fn (array $args) => isset($this->packages[$args[0]]) ? [0, '{}'] : [ExitCode::NotExist->value, 'Error: no package']);
});

/** Let $command succeed and add package $name (with $row) to the list. */
function apiPackageAdded($test, string $command, string $name, array $row = []): void
{
    $test->fake->on($command)->using(function () use ($test, $name, $row) {
        $test->packages[$name] = $row + $test->packages['default'];

        return [0, ''];
    });
}

// Read (listPackages, getPackage)

it('lists packages by role', function (string $principal, string $role, array $names) {
    $this->getJson('/api/v2/packages', apiAs($this->fake, $principal, $role, readOnly: true))->assertOk()
        ->assertJsonPath('data.*.name', $names)
        ->assertJsonPath('meta.total', count($names));
})->with([
    'admin: every package but system' => ['arcadmin', 'admin', ['default', 'hostco_gold', 'resb_silver', 'reseller', 'starter']],
    'reseller A: what it may assign' => ['hostco', 'reseller', ['hostco_gold', 'starter']],
    'reseller B: what it may assign' => ['resb', 'reseller', ['resb_silver', 'starter']],
]);

it('pages the package list', function () {
    $this->getJson('/api/v2/packages?limit=2&offset=1', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.limit', 2)
        ->assertJsonPath('meta.offset', 1);
});

it('keeps users out of packages', function () {
    $headers = apiAs($this->fake, 'bob', 'user', readOnly: true);

    $this->getJson('/api/v2/packages', $headers)->assertForbidden();
    $this->getJson('/api/v2/packages/default', $headers)->assertForbidden();
});

it('shows a package inside the reach and answers 404 outside it', function (string $principal, string $role, string $package, int $status) {
    $this->getJson("/api/v2/packages/{$package}", apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
})->with([
    'admin, a reseller package' => ['arcadmin', 'admin', 'resb_silver', 200],
    'admin, system' => ['arcadmin', 'admin', 'system', 404],
    'reseller A, its own' => ['hostco', 'reseller', 'hostco_gold', 200],
    'reseller A, assignable' => ['hostco', 'reseller', 'starter', 200],
    'reseller A, of reseller B' => ['hostco', 'reseller', 'resb_silver', 404],
    'reseller A, not assignable' => ['hostco', 'reseller', 'reseller', 404],
    'reseller B, of reseller A' => ['resb', 'reseller', 'hostco_gold', 404],
]);

it('describes a package with typed fields', function () {
    $this->getJson('/api/v2/packages/reseller', apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.name', 'reseller')
        ->assertJsonPath('data.owner', null)
        ->assertJsonPath('data.users', 2)
        ->assertJsonPath('data.limits.web_domains', 1)
        ->assertJsonPath('data.limits.dns_records', null)
        ->assertJsonPath('data.limits.rate_limit', 200)
        ->assertJsonPath('data.nameservers', ['ns1.example.com', 'ns2.example.com'])
        ->assertJsonPath('data.reseller', ['users' => 25, 'packages' => ['*'], 'visible' => false])
        ->assertJsonPath('data.ports', null)
        ->assertJsonPath('data.node', ['enabled' => false, 'versions' => 3, 'allowed' => 'lts', 'apps' => 3])
        ->assertJsonPath('data.docker', ['enabled' => false, 'apps' => 0, 'cpus' => null, 'memory' => null])
        ->assertJsonPath('data.created_at', '2026-09-01T10:00:00');
    $this->getJson('/api/v2/packages/hostco_gold', apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertOk()
        ->assertJsonPath('data.label', 'gold')
        ->assertJsonPath('data.owner', 'hostco');
});

// Create (createPackage)

/** Main paths run with the default key and with a key that holds only the accounts set (D30). */
dataset('package keys', [
    'default key' => [null],
    'v2-accounts key' => ['accounts'],
]);

it('creates a package with reseller settings for an admin, from the defaults of the form', function (?string $area) {
    apiPackageAdded($this, 'v-add-user-package', 'business');
    $this->fake->on('v-change-package-tenancy')->returns();

    $this->postJson('/api/v2/packages', [
        'name' => 'business', 'limits' => ['web_domains' => 3, 'mail_accounts' => null],
        'reseller' => ['users' => 10, 'packages' => ['starter', 'default'], 'visible' => true],
    ], apiAs($this->fake, 'arcadmin', 'admin', area: $area))
        ->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/packages/business')
        ->assertJsonPath('data.name', 'business');

    $this->fake->assertRan('v-add-user-package', fn ($a) => $a[1] === 'business'
        && str_contains($a[0], "WEB_DOMAINS='3'") && str_contains($a[0], "MAIL_ACCOUNTS='unlimited'") && str_contains($a[0], "WEB_ALIASES='5'")
        && str_contains($a[0], "NS='ns1.example.com,ns2.example.com'"));
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'USERS', '10']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'RESELLER_PACKAGES', 'starter,default']);
    $this->fake->assertRan('v-change-package-tenancy', fn ($a) => $a === ['business', 'RESELLER_VISIBLE', 'yes']);
})->with('package keys');

it('answers a repeated create with 409 and runs nothing', function () {
    $this->postJson('/api/v2/packages', ['name' => 'starter'], apiAs($this->fake, 'arcadmin', 'admin'))->assertStatus(409)
        ->assertJsonPath('error.code', 'E_EXISTS');
    $this->fake->assertNotRan('v-add-user-package');
});

it('validates templates, name servers and limits with the API field names', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin');

    $this->postJson('/api/v2/packages', ['name' => 'business', 'templates' => ['web' => 'evil'], 'nameservers' => ['ns1.example.com']], $headers)
        ->assertUnprocessable()->assertJsonPath('error.code', 'E_VALIDATION')->assertJsonStructure(['error' => ['fields' => ['nameservers']]]);
    $this->postJson('/api/v2/packages', ['name' => 'business', 'templates' => ['web' => 'evil']], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['templates.web']]]);
    $this->postJson('/api/v2/packages', ['name' => 'business', 'limits' => ['web_domains' => -1], 'docker' => ['cpus' => 'all']], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['limits.web_domains', 'docker.cpus']]]);
    $this->postJson('/api/v2/packages', ['name' => "business\n", 'nameservers' => ["ns1.example.com\n", 'ns2.example.com']], $headers)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['name', 'nameservers.0']]]);
    $this->fake->assertNotRan('v-add-user-package');
});

it('creates a reseller package within the reseller limits, without reseller settings', function (?string $area) {
    apiPackageAdded($this, 'v-add-reseller-package', 'hostco_business', ['RESELLER_OWNER' => 'hostco']);
    $headers = apiAs($this->fake, 'hostco', 'reseller', area: $area);

    $this->postJson('/api/v2/packages', ['name' => 'business', 'limits' => ['web_domains' => 20, 'disk_mb' => null]], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('error.fields', ['limits.web_domains' => ['At most 10 on your plan.'], 'limits.disk_mb' => ['At most 5000 on your plan.']]);

    $this->postJson('/api/v2/packages', ['name' => 'business', 'reseller' => ['users' => 99]], $headers)
        ->assertCreated()->assertHeader('Location', 'http://localhost/api/v2/packages/hostco_business')
        ->assertJsonPath('data.label', 'business');
    $this->fake->assertRan('v-add-reseller-package', fn ($a) => $a[0] === 'hostco' && $a[2] === 'business');
    $this->fake->assertNotRan('v-change-package-tenancy');
})->with('package keys');

it('needs the policy for reseller packages', function () {
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_RESELLER_CREATE_PACKAGES' => 'no']);
    $headers = apiAs($this->fake, 'hostco', 'reseller');

    $this->postJson('/api/v2/packages', ['name' => 'business'], $headers)->assertForbidden();
    $this->patchJson('/api/v2/packages/hostco_gold', ['limits' => ['web_domains' => 2]], $headers)->assertForbidden();
});

it('plans a create without running it, the package file masked', function () {
    $response = $this->postJson('/api/v2/packages?dry_run=1', ['name' => 'business'], apiAs($this->fake, 'arcadmin', 'admin'))->assertOk()
        ->assertJsonPath('data.steps.0.command', 'v-add-user-package')
        ->assertJsonPath('data.steps.0.args', ['******', 'business']);

    $this->fake->assertNotRan('v-add-user-package');
});

it('refuses a key whose sets do not allow the change and runs nothing', function () {
    $this->postJson('/api/v2/packages', ['name' => 'business'], apiAs($this->fake, 'hostco', 'reseller', readOnly: true))->assertForbidden()
        ->assertJsonPath('error.code', 'E_FORBIDDEN');

    $this->fake->assertNotRan('v-add-reseller-package');
});

// Update, copy, delete

it('saves, renames and applies a package for an admin', function (?string $area) {
    foreach (['v-add-user-package', 'v-update-user-package', 'v-change-package-tenancy', 'v-rename-package-tenancy'] as $command) {
        $this->fake->on($command)->returns();
    }
    apiPackageAdded($this, 'v-rename-user-package', 'basic', ['RESELLER_VISIBLE' => 'yes', 'WEB_DOMAINS' => '4']);

    $this->patchJson('/api/v2/packages/starter', ['name' => 'basic', 'limits' => ['web_domains' => 4]], apiAs($this->fake, 'arcadmin', 'admin', area: $area))->assertOk()
        ->assertJsonPath('data.name', 'basic')
        ->assertJsonPath('data.limits.web_domains', 4);

    $this->fake->assertRan('v-add-user-package', fn ($a) => $a[1] === 'starter' && $a[2] === 'yes' && str_contains($a[0], "WEB_DOMAINS='4'") && str_contains($a[0], "DATABASES='1'"));
    $this->fake->assertRan('v-update-user-package', fn ($a) => $a === ['starter']);
    $this->fake->assertNotRan('v-change-package-tenancy');
    $this->fake->assertRan('v-rename-user-package', fn ($a) => $a === ['starter', 'basic']);
    $this->fake->assertRan('v-rename-package-tenancy', fn ($a) => $a === ['starter', 'basic']);
})->with('package keys');

it('changes its own package for a reseller, keeping the name', function (?string $area) {
    $this->fake->on('v-change-reseller-package')->returns();
    $headers = apiAs($this->fake, 'hostco', 'reseller', area: $area);

    $this->patchJson('/api/v2/packages/hostco_gold', ['name' => 'other', 'limits' => ['web_domains' => 2]], $headers)->assertOk()
        ->assertJsonPath('data.name', 'hostco_gold');
    $this->fake->assertRan('v-change-reseller-package', fn ($a) => $a[0] === 'hostco' && $a[2] === 'hostco_gold' && str_contains($a[1], "WEB_DOMAINS='2'"));

    $this->patchJson('/api/v2/packages/starter', ['limits' => ['web_domains' => 2]], $headers)->assertForbidden();
    $this->patchJson('/api/v2/packages/resb_silver', ['limits' => ['web_domains' => 2]], $headers)->assertNotFound();
})->with('package keys');

it('copies a package for an admin only', function () {
    $this->fake->on('v-change-package-tenancy')->returns();
    apiPackageAdded($this, 'v-copy-user-package', 'starter2');

    $this->postJson('/api/v2/packages/starter/copy', ['name' => 'starter2'], apiAs($this->fake, 'arcadmin', 'admin'))->assertCreated()
        ->assertHeader('Location', 'http://localhost/api/v2/packages/starter2');
    $this->fake->assertRan('v-copy-user-package', fn ($a) => $a === ['starter', 'starter2']);

    $this->postJson('/api/v2/packages/starter/copy', ['name' => 'default'], apiAs($this->fake, 'arcadmin', 'admin'))->assertStatus(409);
    $this->postJson('/api/v2/packages/hostco_gold/copy', ['name' => 'x'], apiAs($this->fake, 'hostco', 'reseller'))->assertForbidden();
});

it('deletes a package without users', function (?string $area) {
    $this->fake->on('v-delete-user-package')->returns();
    $this->fake->on('v-delete-package-tenancy')->returns();
    $this->fake->on('v-delete-reseller-package')->returns();
    $admin = apiAs($this->fake, 'arcadmin', 'admin', area: $area);

    $this->deleteJson('/api/v2/packages/reseller', [], $admin)->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
    $this->deleteJson('/api/v2/packages/default', [], $admin)->assertForbidden();
    $this->fake->assertNotRan('v-delete-user-package');

    $this->deleteJson('/api/v2/packages/starter', [], $admin)->assertNoContent();
    $this->fake->assertRan('v-delete-user-package', fn ($a) => $a === ['starter']);
    $this->fake->assertRan('v-delete-package-tenancy', fn ($a) => $a === ['starter']);

    $this->deleteJson('/api/v2/packages/hostco_gold', [], apiAs($this->fake, 'hostco', 'reseller', area: $area))->assertNoContent();
    $this->fake->assertRan('v-delete-reseller-package', fn ($a) => $a === ['hostco', 'hostco_gold']);
    $this->deleteJson('/api/v2/packages/hostco_gold', [], apiAs($this->fake, 'resb', 'reseller', area: $area))->assertNotFound();
})->with('package keys');
