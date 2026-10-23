<?php

use App\Arc\ExitCode;

// REST API v2, the PHP Manager (E41 spec 2026-10-12-001 §9): versions,
// extensions, php.ini and PHP-FPM profiles of the server, the PHP of a web
// domain and of a package. Every command is answered by the fake.

const PHP_TASK = '17286400004141';

function apiPhpDomainRow(array $overrides = []): array
{
    return array_replace([
        'DOMAIN' => 'example.com', 'VERSION' => '8.3', 'BACKEND' => 'PHP-8_3', 'PROFILE' => 'small', 'PROFILE_APPLIED' => 'yes',
        'VERSION_ALLOWED' => 'yes', 'PROFILE_ALLOWED' => 'yes', 'ALLOWED' => 'yes', 'PACKAGE' => 'default', 'DEFAULT_PROFILE' => 'default',
        'VERSIONS' => ['8.3' => ['STATUS' => 'security', 'EOL' => '2027-12-31'], '8.4' => ['STATUS' => 'active', 'EOL' => '2028-12-31']],
        'PROFILES' => ['default', 'small'],
        'EFFECTIVE' => ['memory_limit' => ['VALUE' => '128M', 'SOURCE' => 'profile'], 'max_input_vars' => ['VALUE' => '4000', 'SOURCE' => 'php.ini']],
    ], $overrides);
}

beforeEach(function () {
    $this->fake = fakeApi();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-start-sys-task')->returns(PHP_TASK."\n");
    $ext = fn (string $package, string $group, array $extra = []) => array_replace([
        'PACKAGE' => $package, 'MODULE' => '', 'GROUP' => $group, 'REQUIRED' => 'no', 'CONFLICTS' => '', 'NOTE' => '', 'CATALOG' => 'yes',
        'TOGGLE_ONLY' => 'no', 'INSTALLED' => 'no', 'AVAILABLE' => 'yes', 'FPM' => 'off', 'CLI' => 'off',
    ], $extra);
    $this->fake->on('v-list-sys-php-extensions')->returnsJson([
        'curl' => $ext('php8.4-curl', 'network', ['MODULE' => 'curl', 'REQUIRED' => 'yes', 'INSTALLED' => 'yes', 'FPM' => 'on', 'CLI' => 'on']),
        'redis' => $ext('php8.4-redis', 'cache', ['MODULE' => 'redis']),
        'opcache' => $ext('', 'cache', ['MODULE' => 'opcache', 'TOGGLE_ONLY' => 'yes', 'INSTALLED' => 'yes', 'FPM' => 'on', 'CLI' => 'off']),
        'ctype' => $ext('', 'other', ['MODULE' => 'ctype', 'CATALOG' => 'no', 'INSTALLED' => 'yes', 'FPM' => 'on', 'CLI' => 'on']),
    ]);
    $value = fn (string $v, string $source = 'file', int $line = 400, int $overrides = 0) => ['VALUE' => $v, 'SOURCE' => $source, 'LINE' => $line, 'PROFILE_OVERRIDES' => $overrides];
    $this->fake->on('v-list-sys-php-ini')->using(fn (array $args) => ($args[2] ?? '') === 'raw' ? [0, "[PHP]\nmemory_limit = 128M\n"] : [0, (string) json_encode([
        'VERSION' => $args[0], 'SAPI' => $args[1], 'FILE' => "/etc/php/{$args[0]}/{$args[1]}/php.ini", 'SIZE' => '72000', 'MTIME' => '2026-10-12 10:00:00',
        'VALUES' => ['memory_limit' => $value('128M', 'file', 435, 2), 'cgi.fix_pathinfo' => $value('1', 'default', 0)],
    ])]);
    $this->fake->on('v-list-sys-php-ini-backups')->returnsJson(['20261012-100000' => ['DATE' => '2026-10-12 10:00:00', 'SIZE' => '71000']]);
    $this->fake->on('v-list-web-domain-php')->returnsJson(apiPhpDomainRow());
});

// ---------------------------------------------------------------- versions

it('lists the PHP versions of the catalog for administrators only', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/system/php/versions', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0', [
                'version' => '7.3', 'installed' => false, 'default' => false, 'used_by' => [], 'status' => 'eol', 'released' => '2023-11-23',
                'active_until' => '2025-12-31', 'eol' => '2021-12-06', 'installable' => false, 'available' => true, 'extensions' => 0,
            ])
            ->assertJsonPath('data.2.default', true)
            ->assertJsonPath('data.2.used_by', ['bob/bob.example'])
            ->assertJsonPath('meta.total', 5);
    } else {
        $response->assertJsonPath('error.code', 'E_FORBIDDEN');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 403],
    'reseller B' => ['resb', 'reseller', 403],
    'user' => ['bob', 'user', 403],
]);

it('refuses every server endpoint to non-administrators before any command', function (string $principal, string $role) {
    $headers = apiAs($this->fake, $principal, $role);

    $this->getJson('/api/v2/system/php/versions/8.4', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/php/versions/8.5', [], $headers)->assertForbidden();
    $this->getJson('/api/v2/system/php/versions/8.4/extensions', $headers)->assertForbidden();
    $this->putJson('/api/v2/system/php/versions/8.4/extensions/redis', ['installed' => true], $headers)->assertForbidden();
    $this->getJson('/api/v2/system/php/versions/8.4/ini/fpm', $headers)->assertForbidden();
    $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm', ['values' => ['memory_limit' => '1G']], $headers)->assertForbidden();
    $this->getJson('/api/v2/system/php/profiles', $headers)->assertForbidden();
    $this->postJson('/api/v2/system/php/profiles', ['name' => 'x', 'pm' => 'static', 'max_children' => 2, 'max_requests' => 0], $headers)->assertForbidden();
    foreach (['v-list-sys-php-extensions', 'v-list-sys-php-ini', 'v-start-sys-task', 'v-change-sys-php-ini-values', 'v-list-sys-php-profiles', 'v-add-sys-php-profile'] as $command) {
        $this->fake->assertNotRan($command);
    }
})->with([
    'reseller A' => ['hostco', 'reseller'],
    'reseller B' => ['resb', 'reseller'],
    'user' => ['bob', 'user'],
]);

it('installs and removes a version as the system task with a key holding only v2-server', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/php/versions/8.4', $headers)->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson('/api/v2/system/php/versions/8.5', [], $headers)->assertStatus(202)->assertDocumentedHeaders('installPhpVersion')
        ->assertHeader('Link', '</api/v2/tasks/sys:'.PHP_TASK.'>; rel="monitor"')
        ->assertJsonPath('data.kind', 'php');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php', '8.5', '-']);
    $this->deleteJson('/api/v2/system/php/versions/8.2', [], $headers)->assertStatus(202)->assertDocumentedHeaders('removePhpVersion');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php', '-', '8.2']);

    $this->postJson('/api/v2/system/php/versions/8.5?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)->assertJsonPath('data.steps.0.args', ['php', '8.5', '-']);
    $this->fake->assertRan('v-start-sys-task', null, 2);

    $this->getJson('/api/v2/system/php/versions/9.9', $headers)->assertNotFound()->assertJsonPath('error.code', 'E_NOTEXIST');
    $this->postJson('/api/v2/system/php/versions/8.x', [], $headers)->assertNotFound();
});

it('refuses the changes to a v2-read key before any command, and reads with it', function () {
    $headers = apiKey($this->fake, 'arcadmin', 'admin', ['v2-read']);

    $this->getJson('/api/v2/system/php/versions', $headers)->assertOk();
    $this->getJson('/api/v2/system/php/versions/8.4/extensions', $headers)->assertOk();
    $this->getJson('/api/v2/system/php/versions/8.4/ini/cli', $headers)->assertOk();
    $this->getJson('/api/v2/system/php/profiles', $headers)->assertOk();
    $this->getJson('/api/v2/users/bob/web-domains/example.com/php', $headers)->assertOk();
    $this->getJson('/api/v2/users/bob/web-domains/example.com/php/settings', $headers)->assertOk();
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings', ['memory_limit' => '256M'], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/users/bob/web-domains/example.com/php/settings', [], $headers)->assertForbidden();
    $this->fake->assertNotRan('v-change-web-domain-php-setting');
    $this->fake->assertNotRan('v-delete-web-domain-php-setting');

    $this->postJson('/api/v2/system/php/versions/8.5', [], $headers)->assertForbidden()->assertJsonPath('error.commands', ['v-start-sys-task']);
    $this->putJson('/api/v2/system/php/versions/8.4/ini/fpm?dry_run=1', ['content' => "[PHP]\n"], $headers)->assertForbidden();
    $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm', ['values' => ['memory_limit' => '1G']], $headers)->assertForbidden();
    $this->putJson('/api/v2/system/php/versions/8.4/extensions/opcache', ['cli' => true], $headers)->assertForbidden();
    $this->deleteJson('/api/v2/system/php/profiles/small', [], $headers)->assertForbidden();
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['version' => '8.4'], $headers)->assertForbidden();
    foreach (['v-start-sys-task', 'v-check-sys-php-ini', 'v-change-sys-php-ini', 'v-change-sys-php-ini-values', 'v-change-sys-php-extension', 'v-delete-sys-php-profile', 'v-change-web-domain-php'] as $command) {
        $this->fake->assertNotRan($command);
    }
});

// -------------------------------------------------------------- extensions

it('lists the extensions and installs, toggles and removes one', function () {
    $this->fake->on('v-change-sys-php-extension')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/php/versions/8.4/extensions', $headers)->assertOk()
        ->assertJsonPath('data.0.name', 'ctype')->assertJsonPath('data.0.catalog', false)
        ->assertJsonPath('data.1.name', 'curl')->assertJsonPath('data.1.required', true)->assertJsonPath('data.1.package', 'php8.4-curl')
        ->assertJsonPath('data.2.name', 'opcache')->assertJsonPath('data.2.package', null);

    $this->putJson('/api/v2/system/php/versions/8.4/extensions/redis', ['installed' => true], $headers)->assertStatus(202)->assertDocumentedHeaders('setPhpExtension')
        ->assertJsonPath('data.kind', 'php-ext');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php-ext', '8.4', 'redis', '-']);

    $this->putJson('/api/v2/system/php/versions/8.4/extensions/opcache', ['fpm' => true, 'cli' => true], $headers)->assertOk()
        ->assertJsonPath('data.name', 'opcache');
    $this->fake->assertRan('v-change-sys-php-extension', fn ($a) => $a === ['8.4', 'opcache', 'on', 'cli'], 1);
    $this->fake->assertRan('v-change-sys-php-extension', null, 1);

    $this->putJson('/api/v2/system/php/versions/8.4/extensions/redis', ['cli' => true], $headers)->assertNotFound();
    $this->putJson('/api/v2/system/php/versions/8.4/extensions/ctype', ['cli' => false], $headers)->assertNotFound();
    $this->putJson('/api/v2/system/php/versions/7.3/extensions/redis', ['installed' => true], $headers)->assertNotFound();

    $this->fake->on('v-change-sys-php-extension')->returnsExit(ExitCode::Forbidden, 'Error: the extension curl is required and cannot be turned off');
    $this->putJson('/api/v2/system/php/versions/8.4/extensions/curl', ['fpm' => false], $headers)->assertForbidden()
        ->assertJsonPath('error.step.command', 'v-change-sys-php-extension');

    $this->deleteJson('/api/v2/system/php/versions/8.4/extensions/redis', [], $headers)->assertStatus(202)->assertDocumentedHeaders('removePhpExtension');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php-ext', '8.4', '-', 'redis']);
});

// ----------------------------------------------------------------- php.ini

it('reads a php.ini with its sources and the file on request', function () {
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $response = $this->getJson('/api/v2/system/php/versions/8.4/ini/fpm', $headers)->assertOk()
        ->assertJsonPath('data.file', '/etc/php/8.4/fpm/php.ini')
        ->assertJsonPath('data.values.memory_limit', ['value' => '128M', 'source' => 'file', 'line' => 435, 'profile_overrides' => 2])
        ->assertJsonMissingPath('data.content');
    expect($response->json('data.values')['cgi.fix_pathinfo'])->toBe(['value' => '1', 'source' => 'default', 'line' => null, 'profile_overrides' => 0]);
    $this->getJson('/api/v2/system/php/versions/8.4/ini/cli?raw=1', $headers)->assertOk()
        ->assertJsonPath('data.content', "[PHP]\nmemory_limit = 128M\n");
    $this->getJson('/api/v2/system/php/versions/8.4/ini/apache2', $headers)->assertNotFound();
    $this->getJson('/api/v2/system/php/versions/8.4/ini/fpm/backups', $headers)->assertOk()
        ->assertJsonPath('data.0', ['id' => '20261012-100000', 'created_at' => '2026-10-12 10:00:00', 'size' => 71000]);
});

it('validates a php.ini: a dry run answers the findings, errors 422 by line, warnings are saved', function () {
    $this->fake->on('v-check-sys-php-ini')->returnsJson([['line' => 3, 'level' => 'warning', 'directive' => 'x.y', 'message' => 'PHP does not know x.y and ignores it']]);
    $this->fake->on('v-change-sys-php-ini')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->putJson('/api/v2/system/php/versions/8.4/ini/fpm?dry_run=1', ['content' => "[PHP]\nx.y = 1\n"], $headers)->assertOk()
        ->assertJsonPath('data.dry_run', true)->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.findings.0.line', 3)->assertJsonPath('data.steps.0.command', 'v-change-sys-php-ini');
    $this->fake->assertNotRan('v-change-sys-php-ini');

    $this->putJson('/api/v2/system/php/versions/8.4/ini/fpm', ['content' => "[PHP]\r\nx.y = 1\r\n"], $headers)->assertOk()
        ->assertJsonPath('data.findings.0.level', 'warning');
    $this->fake->assertRan('v-change-sys-php-ini', fn ($a) => $a === ['8.4', 'fpm', "[PHP]\nx.y = 1\n"]);

    $this->fake->on('v-check-sys-php-ini')->returnsJson([['line' => 2, 'level' => 'error', 'directive' => 'memory_limit', 'message' => 'memory_limit must be a size such as 128M']]);
    $this->putJson('/api/v2/system/php/versions/8.4/ini/fpm', ['content' => "[PHP]\nmemory_limit = lots\n"], $headers)->assertStatus(422)
        ->assertJsonPath('error.code', 'E_VALIDATION')
        ->assertJsonPath('error.fields', ['line:2' => ['memory_limit must be a size such as 128M']]);
    $this->fake->assertRan('v-change-sys-php-ini', null, 1);
});

it('changes directives of the form, validated with the values applied', function () {
    $this->fake->on('v-check-sys-php-ini')->returnsJson([]);
    $this->fake->on('v-change-sys-php-ini-values')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $body = ['values' => ['memory_limit' => '256M', 'display_errors' => false, 'disable_functions' => ['exec', 'system']]];
    $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm?dry_run=1', $body, $headers)->assertOk()->assertJsonPath('data.valid', true);
    $this->fake->assertRan('v-check-sys-php-ini', fn ($a) => $a === ['8.4', 'fpm', "memory_limit=256M\ndisplay_errors=Off\ndisable_functions=exec,system\n", 'json', 'values']);
    $this->fake->assertNotRan('v-change-sys-php-ini-values');

    $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm', $body, $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-php-ini-values', fn ($a) => $a === ['8.4', 'fpm', "memory_limit=256M\ndisplay_errors=Off\ndisable_functions=exec,system\n"]);

    $response = $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm', ['values' => ['extension' => 'x.so']], $headers)->assertStatus(422);
    expect($response->json('error.fields'))->toHaveKey('values.extension');
    $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm', ['values' => ['memory_limit' => '1"G']], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/system/php/versions/8.4/ini/fpm', ['values' => ['display_errors' => 'On']], $headers)->assertStatus(422);
    $this->fake->assertRan('v-change-sys-php-ini-values', null, 1);
});

it('restores a backup', function () {
    $this->fake->on('v-rollback-sys-php-ini')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->postJson('/api/v2/system/php/versions/8.4/ini/fpm/backups/20261012-100000/restore', [], $headers)->assertOk()
        ->assertJsonPath('data.sapi', 'fpm');
    $this->fake->assertRan('v-rollback-sys-php-ini', fn ($a) => $a === ['8.4', 'fpm', '20261012-100000']);
    $this->postJson('/api/v2/system/php/versions/8.4/ini/fpm/backups/20200101-000000/restore', [], $headers)->assertNotFound();
});

// ---------------------------------------------------------------- profiles

it('lists, creates, changes and deletes PHP-FPM profiles', function () {
    foreach (['v-add-sys-php-profile', 'v-change-sys-php-profile', 'v-delete-sys-php-profile'] as $command) {
        $this->fake->on($command)->returns();
    }
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'server');

    $this->getJson('/api/v2/system/php/profiles', $headers)->assertOk()
        ->assertJsonPath('data.0.name', 'default')
        ->assertJsonPath('data.0.limits.memory_limit', null)
        ->assertJsonPath('data.0.packages', ['default'])
        ->assertJsonPath('data.2.name', 'medium')->assertJsonPath('data.2.start_servers', 4)->assertJsonPath('data.2.idle_timeout', null);
    $this->getJson('/api/v2/system/php/profiles/small', $headers)->assertOk()->assertJsonPath('data.limits.memory_limit', '128M');
    $this->getJson('/api/v2/system/php/profiles/nosuch', $headers)->assertNotFound();

    $profile = ['name' => 'shop', 'pm' => 'dynamic', 'max_children' => 10, 'start_servers' => 2, 'min_spare' => 1, 'max_spare' => 3,
        'max_requests' => 500, 'idle_timeout' => '10s', 'limits' => ['memory_limit' => '256M', 'max_input_vars' => 3000]];
    $this->postJson('/api/v2/system/php/profiles?dry_run=1', $profile, $headers)->assertOk()->assertJsonPath('data.steps.0.command', 'v-add-sys-php-profile');
    $this->fake->assertNotRan('v-add-sys-php-profile');
    $this->fake->on('v-list-sys-php-profiles')->returnsJson(phpProfileRows(['shop' => array_replace(phpProfileRows()['medium'], ['NAME' => 'shop', 'BUILTIN' => 'no'])]));
    $this->postJson('/api/v2/system/php/profiles', $profile, $headers)->assertCreated()
        ->assertHeader('Location', route('api.v2.system.php.profiles.show', ['profile' => 'shop']))
        ->assertJsonPath('data.name', 'shop');
    $this->fake->assertRan('v-add-sys-php-profile', fn ($a) => $a[0] === 'shop' && str_contains($a[1], "PM='dynamic'")
        && str_contains($a[1], "IDLE_TIMEOUT=''") && str_contains($a[1], "MAX_INPUT_VARS='3000'") && str_contains($a[1], "START_SERVERS='2'"));

    $this->putJson('/api/v2/system/php/profiles/shop', ['pm' => 'static', 'max_children' => 4, 'max_requests' => 0], $headers)->assertOk();
    $this->fake->assertRan('v-change-sys-php-profile', fn ($a) => $a[0] === 'shop' && str_contains($a[1], "PM='static'") && str_contains($a[1], "START_SERVERS=''"));
    $this->putJson('/api/v2/system/php/profiles/shop', ['name' => 'other', 'pm' => 'static', 'max_children' => 4, 'max_requests' => 0], $headers)->assertStatus(422);
    $this->postJson('/api/v2/system/php/profiles', ['name' => 'Bad Name', 'pm' => 'static', 'max_children' => 4, 'max_requests' => 0], $headers)->assertStatus(422);

    $this->deleteJson('/api/v2/system/php/profiles/shop?force=1', [], $headers)->assertNoContent();
    $this->fake->assertRan('v-delete-sys-php-profile', fn ($a) => $a === ['shop', 'yes']);
    $this->fake->on('v-delete-sys-php-profile')->returnsExit(ExitCode::InUse, 'Error: profile small is in use by web domains bob/example.com');
    $this->deleteJson('/api/v2/system/php/profiles/small', [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'E_INUSE');
});

// ----------------------------------------------------------------- domains

it('reads the PHP of a web domain within the owner tree', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/users/bob/web-domains/example.com/php', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('data.version', '8.3')
            ->assertJsonPath('data.profile', 'small')
            ->assertJsonPath('data.allowed', ['version' => true, 'profile' => true])
            ->assertJsonPath('data.versions.1', ['version' => '8.4', 'status' => 'active', 'eol' => '2028-12-31'])
            ->assertJsonPath('data.effective.memory_limit', ['value' => '128M', 'source' => 'profile']);
    } else {
        $response->assertJsonPath('error.code', 'E_NOTEXIST');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 200],
    'reseller B' => ['resb', 'reseller', 404],
    'user' => ['bob', 'user', 200],
    'other user' => ['carol', 'user', 404],
]);

it('sets the PHP of a web domain with a key holding only v2-web', function () {
    $this->fake->on('v-change-web-domain-php')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'web');

    $this->putJson('/api/v2/users/bob/web-domains/example.com/php?dry_run=1', ['version' => '8.4'], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', '8.4', 'small']);
    $this->fake->assertNotRan('v-change-web-domain-php');

    $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['version' => '8.4', 'profile' => 'default'], $headers)->assertOk()
        ->assertJsonPath('data.version', '8.3');
    $this->fake->assertRan('v-change-web-domain-php', fn ($a) => $a === ['bob', 'example.com', '8.4', 'default']);
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['profile' => 'small'], $headers)->assertOk();
    $this->fake->assertRan('v-change-web-domain-php', null, 1);

    $this->fake->on('v-change-web-domain-php')->returnsExit(ExitCode::Forbidden, 'Error: PHP 8.2 is not allowed by the package default');
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['version' => '8.2'], $headers)->assertForbidden()
        ->assertJsonPath('error.message', 'Error: PHP 8.2 is not allowed by the package default');
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['version' => '8.x'], $headers)->assertStatus(422);
    $this->putJson('/api/v2/users/carol/web-domains/carol.example/php', ['version' => '8.4'], $headers)->assertNotFound();
});

it('reads the PHP settings of a web domain within the owner tree (E19)', function (string $principal, string $role, int $status) {
    $response = $this->getJson('/api/v2/users/bob/web-domains/example.com/php/settings', apiAs($this->fake, $principal, $role, readOnly: true))->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.pool', 'domain')
            ->assertJsonPath('data.nginx_max_body', '1024m')
            ->assertJsonPath('data.settings.upload_max_filesize', [
                'directive' => 'upload_max_filesize', 'value' => '64M', 'max' => '256M', 'ini' => '2M', 'profile' => '32M', 'effective' => '64M', 'source' => 'domain',
            ])
            ->assertJsonPath('data.settings.memory_limit.value', null)
            ->assertJsonPath('data.settings.display_errors.max', null)
            ->assertJsonPath('data.settings.date_timezone.directive', 'date.timezone')
            ->assertJsonPath('data.warnings', []);
    } else {
        $response->assertJsonPath('error.code', 'E_NOTEXIST');
    }
})->with([
    'administrator' => ['arcadmin', 'admin', 200],
    'reseller A' => ['hostco', 'reseller', 200],
    'reseller B' => ['resb', 'reseller', 404],
    'user' => ['bob', 'user', 200],
    'other user' => ['carol', 'user', 404],
]);

it('changes the PHP settings of a web domain with a key holding only v2-web (E19)', function () {
    $this->fake->on('v-change-web-domain-php-setting')->returns();
    $this->fake->on('v-delete-web-domain-php-setting')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'web');

    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings?dry_run=1', ['memory_limit' => '256M', 'upload_max_filesize' => null, 'post_max_size' => null], $headers)
        ->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'memory_limit', '256M', 'no'])
        ->assertJsonPath('data.steps.1.args', ['bob', 'example.com', 'upload_max_filesize', 'no'])
        ->assertJsonPath('data.steps.2.args', ['bob', 'example.com', 'post_max_size', 'yes']);
    $this->fake->assertNotRan('v-change-web-domain-php-setting');
    $this->fake->assertNotRan('v-delete-web-domain-php-setting');

    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings', ['date_timezone' => 'Europe/Berlin', 'display_errors' => 'off'], $headers)
        ->assertOk()->assertJsonPath('data.pool', 'domain');
    expect(collect($this->fake->calls('v-change-web-domain-php-setting'))->pluck('args')->all())->toBe([
        ['bob', 'example.com', 'display_errors', 'off', 'no'],
        ['bob', 'example.com', 'date.timezone', 'Europe/Berlin', 'yes'],
    ]);
    // the current values change nothing
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings', ['upload_max_filesize' => '64M'], $headers)->assertOk();
    $this->fake->assertRan('v-change-web-domain-php-setting', null, 2);

    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings', ['display_errors' => 'stderr'], $headers)
        ->assertStatus(422)->assertJsonPath('error.fields', fn ($fields) => isset($fields['display_errors']));
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings', ['memory_limit' => '600M', 'post_max_size' => '32M'], $headers)
        ->assertStatus(422)->assertJsonPath('error.fields', fn ($fields) => isset($fields['memory_limit'], $fields['post_max_size']));
    $this->fake->assertRan('v-change-web-domain-php-setting', null, 2);
    $this->putJson('/api/v2/users/carol/web-domains/carol.example/php/settings', ['memory_limit' => '256M'], $headers)->assertNotFound();
});

it('resets the PHP settings of a web domain, and refuses a template without the profile line (E19)', function () {
    $this->fake->on('v-delete-web-domain-php-setting')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'web');

    $this->deleteJson('/api/v2/users/bob/web-domains/example.com/php/settings?dry_run=1', [], $headers)->assertOk()
        ->assertJsonPath('data.steps.0.args', ['bob', 'example.com', 'all']);
    $this->fake->assertNotRan('v-delete-web-domain-php-setting');
    $this->deleteJson('/api/v2/users/bob/web-domains/example.com/php/settings', [], $headers)->assertOk()->assertJsonPath('data.supported', true);
    $this->fake->assertRan('v-delete-web-domain-php-setting', fn ($a) => $a === ['bob', 'example.com', 'all']);

    $this->fake->on('v-list-web-domain-php-settings')->returnsJson(domainPhpSettingsRow(['SUPPORTED' => 'no']));
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php/settings', ['memory_limit' => '256M'], $headers)->assertStatus(409)
        ->assertJsonPath('error.code', 'E_DISABLED');
});

it('limits PHP changes of web domains to 6 a minute per account (E39 W5 P7)', function () {
    $this->fake->on('v-change-web-domain-php')->returns();
    $headers = apiAs($this->fake, 'bob', 'user', area: 'web');

    foreach (range(1, 6) as $i) {
        $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['version' => '8.4', 'profile' => 'default'], $headers)->assertOk();
    }
    $this->putJson('/api/v2/users/bob/web-domains/example.com/php', ['version' => '8.4', 'profile' => 'default'], $headers)->assertStatus(429);
    $this->fake->assertRan('v-change-web-domain-php', null, 6);
});

it('keeps a backend template of a version the package does not allow from users', function () {
    $this->fake->on('v-list-web-templates-backend', ['json'])->returnsJson(['PHP-8_2', 'PHP-8_3', 'default']);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['PHP_VERSIONS' => '8.3']]);
    withSysConfig($this->fake, ['API_SYSTEM' => '2', 'API_ALLOWED_IP' => 'allow-all', 'POLICY_USER_EDIT_WEB_TEMPLATES' => 'yes']);

    $this->patchJson('/api/v2/users/bob/web-domains/example.com', ['templates' => ['backend' => 'PHP-8_2']], apiAs($this->fake, 'bob', 'user', area: 'web'))
        ->assertStatus(422)->assertJsonPath('error.fields', fn ($fields) => isset($fields['templates.backend']));
    $this->fake->assertNotRan('v-change-web-domain-backend-tpl');
});

// ---------------------------------------------------------------- packages

it('shows and sets the PHP of a package', function () {
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => ['WEB_TEMPLATE' => 'default', 'NS' => 'ns1.example.com,ns2.example.com', 'PHP_VERSIONS' => '8.3,8.4', 'PHP_PROFILES' => 'default,small', 'PHP_DEFAULT_PROFILE' => 'small',
            'PHP_MAX_MEMORY' => '512M', 'PHP_MAX_UPLOAD' => 'unlimited', 'PHP_MAX_EXECUTION_TIME' => '300', 'PHP_MAX_INPUT_VARS' => '10000'],
    ]);
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-update-user-package')->returns();
    $this->fake->on('v-list-web-templates', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-web-templates-backend', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-web-templates-proxy', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-dns-templates', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-change-package-tenancy')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'accounts');

    $this->getJson('/api/v2/packages/default', $headers)->assertOk()
        ->assertJsonPath('data.php', ['versions' => '8.3,8.4', 'profiles' => 'default,small', 'default_profile' => 'small',
            'max_memory' => '512M', 'max_upload' => null, 'max_execution_time' => 300, 'max_input_vars' => 10000]);
    $this->patchJson('/api/v2/packages/default', ['php' => ['versions' => '*', 'profiles' => 'medium', 'default_profile' => 'medium']], $headers)->assertOk();
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([
        ['default', 'PHP_VERSIONS', '*'],
        ['default', 'PHP_PROFILES', 'medium,small'],
        ['default', 'PHP_DEFAULT_PROFILE', 'medium'],
        ['default', 'PHP_PROFILES', 'medium'],
    ]);
    $this->patchJson('/api/v2/packages/default', ['php' => ['versions' => '8.3;id']], $headers)->assertStatus(422);
    $this->patchJson('/api/v2/packages/default', ['php' => ['profiles' => 'small', 'default_profile' => 'medium']], $headers)->assertStatus(422)
        ->assertJsonPath('error.fields', fn ($fields) => isset($fields['php.default_profile']));
});

it('sets the maximums of the per-domain PHP settings of a package (E19)', function () {
    $this->fake->on('v-list-package-tenancy', ['all', 'json'])->returnsJson([
        'default' => ['WEB_TEMPLATE' => 'default', 'NS' => 'ns1.example.com,ns2.example.com', 'PHP_VERSIONS' => '*', 'PHP_PROFILES' => 'default', 'PHP_DEFAULT_PROFILE' => 'default'],
    ]);
    $this->fake->on('v-add-user-package')->returns();
    $this->fake->on('v-update-user-package')->returns();
    $this->fake->on('v-list-web-templates', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-web-templates-backend', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-web-templates-proxy', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-dns-templates', ['json'])->returnsJson(['default']);
    $this->fake->on('v-list-sys-shells', ['json'])->returnsJson(['bash', 'nologin']);
    $this->fake->on('v-change-package-tenancy')->returns();
    $headers = apiAs($this->fake, 'arcadmin', 'admin', area: 'accounts');

    // a package without the keys has the default maximums
    $this->getJson('/api/v2/packages/default', $headers)->assertOk()
        ->assertJsonPath('data.php.max_memory', '512M')->assertJsonPath('data.php.max_upload', '256M')
        ->assertJsonPath('data.php.max_execution_time', 300)->assertJsonPath('data.php.max_input_vars', 10000);
    $this->patchJson('/api/v2/packages/default', ['php' => ['max_memory' => '1G', 'max_upload' => null, 'max_input_vars' => 20000]], $headers)->assertOk();
    $steps = collect($this->fake->calls('v-change-package-tenancy'))->pluck('args')->all();
    expect($steps)->toBe([
        ['default', 'PHP_MAX_MEMORY', '1G'], ['default', 'PHP_MAX_UPLOAD', 'unlimited'], ['default', 'PHP_MAX_INPUT_VARS', '20000'],
    ]);
    foreach (['max_memory' => '1T', 'max_upload' => 'unlimited', 'max_execution_time' => 0, 'max_input_vars' => 1000001] as $field => $value) {
        $this->patchJson('/api/v2/packages/default', ['php' => [$field => $value]], $headers)->assertStatus(422)
            ->assertJsonPath('error.fields', fn ($fields) => isset($fields['php.'.$field]));
    }
});
