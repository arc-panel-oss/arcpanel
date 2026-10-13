<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;
use Illuminate\Support\Facades\Route;

// PHP Manager pages (E41 spec 2026-10-12-001 §8): versions, extensions,
// php.ini and PHP-FPM profiles.

function phpExtensionRows(): array
{
    $row = fn (string $package, string $group, array $extra = []) => array_replace([
        'PACKAGE' => $package, 'MODULE' => '', 'GROUP' => $group, 'REQUIRED' => 'no', 'CONFLICTS' => '', 'NOTE' => '',
        'CATALOG' => 'yes', 'TOGGLE_ONLY' => $package === '' ? 'yes' : 'no', 'INSTALLED' => 'no', 'AVAILABLE' => 'yes', 'FPM' => 'off', 'CLI' => 'off',
    ], $extra);

    return [
        'curl' => $row('php8.4-curl', 'network', ['MODULE' => 'curl', 'REQUIRED' => 'yes', 'INSTALLED' => 'yes', 'FPM' => 'on', 'CLI' => 'on']),
        'redis' => $row('php8.4-redis', 'cache', ['MODULE' => 'redis']),
        'opcache' => $row('', 'cache', ['MODULE' => 'opcache', 'INSTALLED' => 'yes', 'FPM' => 'on', 'CLI' => 'off']),
        'xdebug' => $row('php8.4-xdebug', 'debug', ['MODULE' => 'xdebug', 'NOTE' => 'Slows every request; do not enable on production sites', 'CONFLICTS' => 'pcov']),
        'ctype' => $row('', 'other', ['MODULE' => 'ctype', 'CATALOG' => 'no', 'INSTALLED' => 'yes', 'FPM' => 'on', 'CLI' => 'on', 'TOGGLE_ONLY' => 'no']),
    ];
}

function phpIniRow(string $sapi = 'fpm', array $values = []): array
{
    $value = fn (string $v, string $source = 'file', int $line = 400, int $overrides = 0) => ['VALUE' => $v, 'SOURCE' => $source, 'LINE' => $line, 'PROFILE_OVERRIDES' => $overrides];
    $rows = [
        'memory_limit' => $value('128M', 'file', 435, 2), 'max_execution_time' => $value('60'), 'max_input_time' => $value('60'),
        'max_input_vars' => $value('4000'), 'upload_max_filesize' => $value('100M'), 'post_max_size' => $value('100M'),
        'max_file_uploads' => $value('20'), 'display_errors' => $value('Off'), 'error_reporting' => $value('E_ALL & ~E_DEPRECATED'),
        'log_errors' => $value('On'), 'date.timezone' => $value('UTC'), 'default_socket_timeout' => $value('60'), 'allow_url_fopen' => $value('On'),
        'short_open_tag' => $value('On'), 'cgi.fix_pathinfo' => $value('1', 'default', 0), 'session.gc_maxlifetime' => $value('1440'),
        'opcache.enable' => $value('1'), 'opcache.memory_consumption' => $value('512'), 'opcache.max_accelerated_files' => $value('100000'),
        'opcache.validate_timestamps' => $value('1', 'default', 0), 'opcache.revalidate_freq' => $value('2', 'default', 0),
        'disable_functions' => $value('exec,system'),
    ];

    return ['VERSION' => '8.4', 'SAPI' => $sapi, 'FILE' => "/etc/php/8.4/{$sapi}/php.ini", 'SIZE' => '72000', 'MTIME' => '2026-10-12 10:00:00',
        'VALUES' => array_replace($rows, $values)];
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    $this->fake->on('v-list-sys-task')->returnsJson(['ID' => '', 'KIND' => '', 'ARGS' => '', 'STATUS' => '', 'EXIT' => '', 'START' => '', 'END' => '', 'LOG' => '']);
    $this->fake->on('v-list-sys-php-extensions')->returnsJson(phpExtensionRows());
    $this->fake->on('v-list-sys-php-ini')->using(fn (array $args) => ($args[2] ?? '') === 'raw'
        ? [0, "[PHP]\nmemory_limit = 128M\n"]
        : [0, (string) json_encode(phpIniRow($args[1]))]);
    $this->fake->on('v-list-sys-php-ini-backups')->returnsJson(['20261012-100000' => ['DATE' => '2026-10-12 10:00:00', 'SIZE' => '71000']]);
    $this->fake->on('v-list-sys-services')->returnsJson(['php8.4-fpm' => ['SYSTEM' => 'php interpreter', 'STATE' => 'running', 'CPU' => '0', 'MEM' => '1', 'RTIME' => '1']]);
});

// ---------------------------------------------------------------- versions

it('lists the versions of the catalog with support, end of life, usage and actions', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/php')->assertOk()
        ->assertSeeInOrder(['PHP 8.5', 'Active support', 'PHP 8.4', 'Installed', 'PHP 8.3', 'Default', 'PHP 8.2', 'Installed', 'Security fixes only', '2026-12-31'])
        ->assertSee('Install PHP 8.5 from the PHP repository now?')
        // gone for good: named in the footer only
        ->assertSee('Not shown: PHP 7.3, which reached their end of life and can no longer be installed.')
        ->assertDontSee('No longer installable')
        ->assertDontSee('Install PHP 7.3')
        ->assertSee('Remove PHP 8.4 with its extensions?')
        ->assertDontSee('Remove PHP 8.3 with its extensions?')
        ->assertSee('href="'.route('server.php.show', ['version' => '8.4']).'"', false)
        ->assertSee(route('server.php.profiles.index'), false);
});

it('installs and removes a version as the system task', function () {
    $this->fake->on('v-start-sys-task')->returns("1\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/php/versions', ['action' => 'install', 'version' => '8.5'])
        ->assertRedirect('/server/php')->assertSessionHas('ok', 'PHP 8.5 is being installed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php', '8.5', '-']);

    $this->post('/server/php/versions', ['action' => 'remove', 'version' => '8.2'])->assertSessionHas('ok', 'PHP 8.2 is being removed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php', '-', '8.2']);

    $this->fake->on('v-start-sys-task')->returnsExit(ExitCode::Forbidden, 'Error: PHP 8.3 is the default version');
    $this->post('/server/php/versions', ['action' => 'remove', 'version' => '8.3'])->assertSessionHas('error', 'Error: PHP 8.3 is the default version');

    $this->post('/server/php/versions', ['action' => 'install', 'version' => '9.9'])->assertNotFound();
    $this->post('/server/php/versions', ['action' => 'purge', 'version' => '8.5'])->assertSessionHasErrors('action');
});

it('sets the default version', function () {
    $this->fake->on('v-change-sys-php')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/php/default', ['version' => '8.4'])->assertRedirect('/server/php')->assertSessionHas('ok', 'PHP 8.4 is now the default version.');
    $this->fake->assertRan('v-change-sys-php', fn ($a) => $a === ['8.4']);
    $this->post('/server/php/default', ['version' => '8.5'])->assertSessionHasErrors('version');
});

it('shows the overview of an installed version and reloads it', function () {
    $this->fake->on('v-restart-web-backend')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/php/8.4')->assertOk()
        ->assertSee('php8.4-fpm')->assertSee('Running')->assertSee('/etc/php/8.4/cli/php.ini')->assertSee('Reload PHP-FPM');
    $this->post('/server/php/8.4/reload')->assertRedirect('/server/php/8.4')->assertSessionHas('ok', 'PHP-FPM 8.4 has been reloaded.');
    $this->fake->assertRan('v-restart-web-backend', fn ($a) => $a === ['yes', '8.4']);

    $this->get('/server/php/8.5')->assertNotFound();
    $this->get('/server/php/7.3/extensions')->assertNotFound();
    $this->get('/server/php/8.4/nosuch')->assertNotFound();
});

// -------------------------------------------------------------- extensions

it('lists the extensions by group with their notes and toggles', function () {
    signIn('arcadmin', 'admin');

    $this->get('/server/php/8.4/extensions')->assertOk()
        ->assertSeeInOrder(['Caching', 'opcache', 'redis', 'Network', 'curl', 'Required', 'Debugging', 'xdebug', 'Slows every request', 'Cannot be enabled together with pcov', 'Other', 'ctype', 'Not managed by the panel'])
        ->assertSee('Install redis for PHP 8.4?')
        ->assertDontSee('Remove curl from PHP 8.4?')
        ->assertDontSee('Remove opcache from PHP 8.4?')
        ->assertSee('php\/8.4\/extensions\/opcache', false)
        // the filter knows every extension and whether it is installed
        ->assertSee('apPhpExtensionFilter', false)
        ->assertSee('Filter extensions');
});

it('shows a module built into PHP as on and locked', function () {
    $this->fake->on('v-list-sys-php-extensions')->returnsJson(phpExtensionRows() + [
        'sodium' => ['PACKAGE' => '', 'MODULE' => 'sodium', 'GROUP' => 'security', 'REQUIRED' => 'no', 'CONFLICTS' => '', 'NOTE' => '', 'CATALOG' => 'yes',
            'TOGGLE_ONLY' => 'yes', 'INSTALLED' => 'yes', 'AVAILABLE' => 'yes', 'FPM' => 'on', 'CLI' => 'on', 'BUILTIN' => 'yes'],
    ]);
    signIn('arcadmin', 'admin');

    $html = $this->get('/server/php/8.4/extensions')->assertOk()
        ->assertSeeInOrder(['Security', 'sodium', 'Built in'])
        ->assertDontSee('Not available for this version')
        ->getContent();
    preg_match('/<li class="ap-php-extension" data-extension="sodium".*?<\/li>/s', $html, $row);
    expect($row[0] ?? '')->toContain('aria-label="sodium for PHP-FPM"')->toContain('x-bind:disabled="busy || true"');
});

it('installs and removes an extension as the system task', function () {
    $this->fake->on('v-start-sys-task')->returns("1\n");
    signIn('arcadmin', 'admin');

    $this->post('/server/php/8.4/extensions', ['action' => 'install', 'name' => 'redis'])
        ->assertRedirect('/server/php/8.4/extensions')->assertSessionHas('ok', 'redis is being installed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php-ext', '8.4', 'redis', '-']);
    $this->post('/server/php/8.4/extensions', ['action' => 'remove', 'name' => 'xdebug'])->assertSessionHas('ok', 'xdebug is being removed.');
    $this->fake->assertRan('v-start-sys-task', fn ($a) => $a === ['php-ext', '8.4', '-', 'xdebug']);

    // a module of the server that the catalog does not list is not the panel's
    $this->post('/server/php/8.4/extensions', ['action' => 'remove', 'name' => 'ctype'])->assertNotFound();
    $this->post('/server/php/8.4/extensions', ['action' => 'install', 'name' => 'nosuch'])->assertNotFound();
});

it('turns a module on and off per SAPI and reports a refusal', function () {
    $this->fake->on('v-change-sys-php-extension')->returns();
    signIn('arcadmin', 'admin');

    $this->postJson('/server/php/8.4/extensions/opcache', ['state' => 'on', 'sapi' => 'cli'])->assertOk()->assertJsonStructure(['fpm', 'cli', 'message']);
    $this->fake->assertRan('v-change-sys-php-extension', fn ($a) => $a === ['8.4', 'opcache', 'on', 'cli']);
    $this->postJson('/server/php/8.4/extensions/opcache', ['state' => 'off'])->assertOk();
    $this->fake->assertRan('v-change-sys-php-extension', fn ($a) => $a === ['8.4', 'opcache', 'off']);

    $this->fake->on('v-change-sys-php-extension')->returnsExit(ExitCode::Forbidden, 'Error: the extension curl is required and cannot be turned off');
    $this->postJson('/server/php/8.4/extensions/curl', ['state' => 'off', 'sapi' => 'fpm'])->assertStatus(422)
        ->assertJsonPath('error.message', 'Error: the extension curl is required and cannot be turned off');

    $this->postJson('/server/php/8.4/extensions/curl', ['state' => 'maybe'])->assertStatus(422);
    $this->postJson('/server/php/8.4/extensions/ctype', ['state' => 'off'])->assertNotFound();
});

// ----------------------------------------------------------------- php.ini

it('shows the php.ini form with sources, profile overrides, the editor and the backups', function () {
    signIn('arcadmin', 'admin');

    $response = $this->get('/server/php/8.4/ini-fpm')->assertOk()
        ->assertSee('/etc/php/8.4/fpm/php.ini')
        ->assertSee('Line 435 of the file')
        ->assertSee('2 web domains override it through their PHP-FPM profile')
        ->assertSee('Not set in the file: PHP default')
        ->assertSee('data-island="php-ini-editor"', false)
        ->assertSee('2026-10-12 10:00:00')
        ->assertSee(route('server.php.ini.restore', ['version' => '8.4', 'sapi' => 'fpm', 'backup' => '20261012-100000']), false);
    $response->assertSee(route('server.php.ini.check', ['version' => '8.4', 'sapi' => 'fpm']), false);

    // the CLI file does not count profile overrides
    $this->get('/server/php/8.4/ini-cli')->assertOk()->assertSee('/etc/php/8.4/cli/php.ini')->assertDontSee('override it through');
});

it('saves only the changed directives of the form', function () {
    $this->fake->on('v-change-sys-php-ini-values')->returns();
    signIn('arcadmin', 'admin');

    $form = [];
    foreach (phpIniRow()['VALUES'] as $directive => $value) {
        $form[str_replace('.', '__', $directive)] = $value['VALUE'];
    }
    $form['memory_limit'] = '256M';
    $form['display_errors'] = '1';
    $form['disable_functions_editor'] = '1';
    $form['disable_functions_list'] = ['exec', 'system', 'passthru'];
    foreach (['log_errors', 'allow_url_fopen', 'short_open_tag', 'cgi__fix_pathinfo', 'opcache__enable', 'opcache__validate_timestamps'] as $switch) {
        $form[$switch] = '1';
    }

    $this->post('/server/php/8.4/ini/fpm', $form)->assertRedirect('/server/php/8.4/ini-fpm')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-php-ini-values', fn ($a) => $a[0] === '8.4' && $a[1] === 'fpm'
        && $a[2] === "memory_limit=256M\ndisplay_errors=On\ndisable_functions=exec,system,passthru\n");

    $this->fake->on('v-change-sys-php-ini-values')->returnsExit(ExitCode::Invalid, "line 435: error: memory_limit must be a size such as 128M\nError: the php.ini was not saved");
    $this->fake->on('v-check-sys-php-ini')->returnsJson([['line' => 435, 'level' => 'error', 'directive' => 'memory_limit', 'message' => 'memory_limit must be a size such as 128M']]);
    $form['memory_limit'] = 'lots';
    // the findings of the form's directives go to their fields
    $this->post('/server/php/8.4/ini/fpm', $form)->assertSessionHas('error', 'The php.ini was not saved: 1 setting needs a change.')
        ->assertSessionHasErrors(['memory_limit' => 'memory_limit must be a size such as 128M']);
    $this->fake->assertRan('v-check-sys-php-ini', fn ($a) => $a[0] === '8.4' && $a[1] === 'fpm' && $a[4] === 'values' && str_contains($a[2], 'memory_limit=lots'));

    // a failure the check cannot place keeps the output of the command
    $this->fake->on('v-check-sys-php-ini')->returnsJson([]);
    $this->post('/server/php/8.4/ini/fpm', $form)->assertSessionHas('error', 'The php.ini was not saved.')
        ->assertSessionHas('error_detail', fn ($detail) => str_contains($detail, 'memory_limit must be a size'));

    $form['memory_limit'] = '1"M';
    $this->post('/server/php/8.4/ini/fpm', $form)->assertSessionHasErrors('memory_limit');
});

it('checks the file and saves it only without errors', function () {
    $findings = [['line' => 3, 'level' => 'warning', 'directive' => 'arcbats.x', 'message' => 'PHP does not know arcbats.x and ignores it']];
    $this->fake->on('v-check-sys-php-ini')->returnsJson($findings);
    $this->fake->on('v-change-sys-php-ini')->returns();
    signIn('arcadmin', 'admin');

    $this->postJson('/server/php/8.4/ini/fpm/check', ['content' => "[PHP]\narcbats.x = 1\n"])->assertOk()
        ->assertJsonPath('findings.0.line', 3)->assertJsonPath('findings.0.level', 'warning');
    $this->fake->assertRan('v-check-sys-php-ini', fn ($a) => $a === ['8.4', 'fpm', "[PHP]\narcbats.x = 1\n", 'json']);

    $this->postJson('/server/php/8.4/ini/fpm/file', ['content' => "[PHP]\r\narcbats.x = 1\r\n"])->assertOk()->assertJsonPath('message', 'Changes have been saved.');
    $this->fake->assertRan('v-change-sys-php-ini', fn ($a) => $a === ['8.4', 'fpm', "[PHP]\narcbats.x = 1\n"]);

    $this->fake->on('v-check-sys-php-ini')->returnsJson([['line' => 2, 'level' => 'error', 'directive' => '', 'message' => 'syntax error']]);
    $this->postJson('/server/php/8.4/ini/cli/file', ['content' => "[PHP\n"])->assertStatus(422)
        ->assertJsonPath('error.code', 'E_INVALID')->assertJsonPath('findings.0.line', 2);
    // only the save of the valid file ran
    $this->fake->assertRan('v-change-sys-php-ini', null, 1);
});

it('restores a backup', function () {
    $this->fake->on('v-rollback-sys-php-ini')->returns();
    signIn('arcadmin', 'admin');

    $this->post('/server/php/8.4/ini/fpm/backups/20261012-100000')->assertRedirect('/server/php/8.4/ini-fpm')
        ->assertSessionHas('ok', 'Backup 20261012-100000 has been restored.');
    $this->fake->assertRan('v-rollback-sys-php-ini', fn ($a) => $a === ['8.4', 'fpm', '20261012-100000']);
    $this->post('/server/php/8.4/ini/fpm/backups/..%2Fx')->assertNotFound();
});

// ---------------------------------------------------------------- profiles

it('lists the profiles with their usage', function () {
    $this->fake->on('v-list-sys-php-profiles')->returnsJson(phpProfileRows(['shop' => array_replace(phpProfileRows()['medium'], [
        'NAME' => 'shop', 'BUILTIN' => 'no', 'DOMAINS' => '3', 'PACKAGES' => 'gold', 'DESCRIPTION' => 'Shops',
    ])]));
    signIn('arcadmin', 'admin');

    $this->get('/server/php/profiles')->assertOk()
        ->assertSeeInOrder(['default', 'Shipped', 'small', 'medium', 'large', 'shop', 'Shops', 'Dynamic', '512M', '3', 'gold'])
        ->assertSee(route('server.php.profiles.edit', ['profile' => 'shop']), false)
        ->assertSee('Profile shop is used by 3 web domains and named by packages gold. Delete it and move them to the default profile?')
        ->assertSee('Delete profile small?')
        ->assertDontSee('Delete profile default?');
});

it('creates, changes and deletes a profile', function () {
    $this->fake->on('v-add-sys-php-profile')->returns();
    $this->fake->on('v-change-sys-php-profile')->returns();
    $this->fake->on('v-delete-sys-php-profile')->returns();
    signIn('arcadmin', 'admin');

    $this->get('/server/php/profiles/create')->assertOk()->assertSee('apPhpProfile', false);
    $this->post('/server/php/profiles', [
        'name' => 'shop', 'description' => 'Shops', 'pm' => 'static', 'max_children' => '6', 'max_requests' => '500',
        'start_servers' => '3', 'idle_timeout' => '10s', 'memory_limit' => '256M',
    ])->assertRedirect('/server/php/profiles')->assertSessionHas('ok', 'Profile shop has been created.');
    $this->fake->assertRan('v-add-sys-php-profile', fn ($a) => $a[0] === 'shop'
        && str_contains($a[1], "PM='static'") && str_contains($a[1], "MAX_CHILDREN='6'") && str_contains($a[1], "START_SERVERS=''")
        && str_contains($a[1], "IDLE_TIMEOUT=''") && str_contains($a[1], "MEMORY_LIMIT='256M'"));

    $this->get('/server/php/profiles/medium/edit')->assertOk()->assertSee('Busy sites');
    $this->put('/server/php/profiles/medium', [
        'pm' => 'dynamic', 'max_children' => '30', 'max_requests' => '1000', 'start_servers' => '4', 'min_spare' => '2', 'max_spare' => '6',
    ])->assertRedirect('/server/php/profiles');
    $this->fake->assertRan('v-change-sys-php-profile', fn ($a) => $a[0] === 'medium' && str_contains($a[1], "MAX_CHILDREN='30'"));

    // the relations of the fields are checked before the command runs, each at its field
    $this->from('/server/php/profiles/medium/edit')->put('/server/php/profiles/medium', [
        'pm' => 'dynamic', 'max_children' => '3', 'max_requests' => '1000', 'start_servers' => '4', 'min_spare' => '5', 'max_spare' => '6',
        'upload_max_filesize' => '1G', 'post_max_size' => '512M',
    ])->assertRedirect('/server/php/profiles/medium/edit')
        ->assertSessionHasErrors(['min_spare' => 'At most the processes at start.', 'max_spare' => 'At most the maximum processes.', 'post_max_size' => 'At least the upload size.']);
    $this->put('/server/php/profiles/medium', ['pm' => 'dynamic', 'max_children' => '30', 'max_requests' => '1', 'start_servers' => '8', 'min_spare' => '2', 'max_spare' => '6'])
        ->assertSessionHasErrors(['start_servers' => 'At most the maximum spare processes.']);

    // what the form cannot check stays the command's
    $this->fake->on('v-change-sys-php-profile')->returnsExit(ExitCode::Invalid, 'Error: the profile is not valid');
    $this->from('/server/php/profiles/medium/edit')->put('/server/php/profiles/medium', [
        'pm' => 'dynamic', 'max_children' => '30', 'max_requests' => '1000', 'start_servers' => '4', 'min_spare' => '2', 'max_spare' => '6',
    ])->assertRedirect('/server/php/profiles/medium/edit')->assertSessionHas('error');

    $this->put('/server/php/profiles/medium', ['pm' => 'dynamic', 'max_children' => '30', 'max_requests' => '1'])->assertSessionHasErrors(['start_servers', 'min_spare', 'max_spare']);
    $this->post('/server/php/profiles', ['name' => 'Bad Name', 'pm' => 'static', 'max_children' => '4', 'max_requests' => '0'])->assertSessionHasErrors('name');

    $this->delete('/server/php/profiles/small')->assertSessionHas('ok', 'Profile small has been deleted.');
    $this->fake->assertRan('v-delete-sys-php-profile', fn ($a) => $a === ['small']);
    $this->delete('/server/php/profiles/large', ['force' => '1'])->assertSessionHas('ok');
    $this->fake->assertRan('v-delete-sys-php-profile', fn ($a) => $a === ['large', 'yes']);

    $this->fake->on('v-delete-sys-php-profile')->returnsExit(ExitCode::InUse, 'Error: profile medium is in use by web domains bob/example.com');
    $this->delete('/server/php/profiles/medium')->assertSessionHas('error', 'Profile medium is in use. Confirm moving its domains to the default profile.');
    $this->delete('/server/php/profiles/nosuch')->assertNotFound();
});

// ------------------------------------------------------------------ access

it('keeps the PHP pages for administrators and read-only in read-only mode', function () {
    fakeResellers($this->fake);
    foreach ([['bob', 'user'], ['hostco', 'reseller'], ['resb', 'reseller']] as [$user, $role]) {
        signIn($user, $role);
        $this->get('/server/php')->assertForbidden();
        $this->get('/server/php/8.4/ini-fpm')->assertForbidden();
        $this->get('/server/php/profiles')->assertForbidden();
        $this->post('/server/php/versions', ['action' => 'install', 'version' => '8.5'])->assertForbidden();
        $this->postJson('/server/php/8.4/extensions/opcache', ['state' => 'on'])->assertForbidden();
    }

    withSysConfig($this->fake, ['POLICY_SYSTEM_PROTECTED_ADMIN' => 'yes']);
    fakeServerUsers($this->fake, ['admin2' => ['ROLE' => 'admin']]);
    signIn('admin2', 'admin', ['look' => 'arcadmin']);
    $this->get('/server/php')->assertOk()->assertDontSee('Install PHP 8.5');
    $this->get('/server/php/8.4/extensions')->assertOk()->assertDontSee('Install redis for PHP 8.4?');
    $this->post('/server/php/versions', ['action' => 'install', 'version' => '8.5'])->assertForbidden();
    $this->post('/server/php/profiles', ['name' => 'x', 'pm' => 'static', 'max_children' => '4', 'max_requests' => '0'])->assertForbidden();
    $this->fake->assertNotRan('v-start-sys-task');
});

it('shows the page in the server navigation of administrators and links it from the settings', function () {
    signIn('arcadmin', 'admin');
    $this->get('/server/php')->assertSee('href="'.route('server.php.index').'"', false);

    fakeResellers($this->fake);
    signIn('hostco', 'reseller');
    $this->get('/web/domains')->assertDontSee('href="'.route('server.php.index').'"', false);
});

it('has a capability for every PHP route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'server.php.'));

    expect($routes->count())->toBeGreaterThan(15)
        ->and(Capabilities::names())->toContain('server.php')->toContain('web.domains.php');
});
