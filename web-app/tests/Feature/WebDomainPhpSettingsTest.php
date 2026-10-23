<?php

use App\Arc\ExitCode;

// The PHP settings card of a web domain's PHP tab (E19 spec §6).

/** The settings form with the values of domainPhpSettingsRow(), changed by $changes. */
function phpSettingsInput(array $changes = []): array
{
    return ['settings' => array_replace([
        'memory_limit' => '', 'upload_max_filesize' => '64M', 'post_max_size' => '64M', 'max_execution_time' => '',
        'max_input_vars' => '', 'display_errors' => '', 'date_timezone' => '',
    ], $changes)];
}

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-web-domain-php')->returnsJson(domainPhpRow());
    $this->fake->on('v-list-web-domain-php-settings')->returnsJson(domainPhpSettingsRow());
});

it('shows the settings with their maximums and inherited values', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('PHP settings')
        ->assertSee('name="settings[memory_limit]"', false)->assertSee('name="settings[date_timezone]"', false)
        ->assertSee('placeholder="128M"', false)->assertSee('value="64M"', false)
        ->assertSee('Maximum 512M')
        ->assertSee('Reset all');
});

it('saves the changed settings, post_max_size before a larger upload_max_filesize, with one reload', function () {
    $this->fake->on('v-change-web-domain-php-setting')->returns();
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput([
        'memory_limit' => '256M', 'upload_max_filesize' => '128M', 'post_max_size' => '128M', 'display_errors' => 'on', 'date_timezone' => 'Asia/Ho_Chi_Minh',
    ]))->assertRedirect('/web/domains/bob/example.com/edit#php')->assertSessionHas('ok', 'Changes have been saved.');
    $calls = collect($this->fake->calls('v-change-web-domain-php-setting'))->pluck('args')->all();
    expect($calls)->toBe([
        ['bob', 'example.com', 'memory_limit', '256M', 'no'],
        ['bob', 'example.com', 'post_max_size', '128M', 'no'],
        ['bob', 'example.com', 'upload_max_filesize', '128M', 'no'],
        ['bob', 'example.com', 'display_errors', 'on', 'no'],
        ['bob', 'example.com', 'date.timezone', 'Asia/Ho_Chi_Minh', 'yes'],
    ]);
});

it('lowers upload_max_filesize before post_max_size and resets emptied settings', function () {
    $this->fake->on('v-change-web-domain-php-setting')->returns();
    $this->fake->on('v-delete-web-domain-php-setting')->returns();
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['upload_max_filesize' => '16M', 'post_max_size' => '32M']))
        ->assertSessionHas('ok', 'Changes have been saved.');
    expect(collect($this->fake->calls('v-change-web-domain-php-setting'))->pluck('args')->all())->toBe([
        ['bob', 'example.com', 'upload_max_filesize', '16M', 'no'],
        ['bob', 'example.com', 'post_max_size', '32M', 'yes'],
    ]);

    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['upload_max_filesize' => '', 'post_max_size' => '']))
        ->assertSessionHas('ok', 'Changes have been saved.');
    expect(collect($this->fake->calls('v-delete-web-domain-php-setting'))->pluck('args')->all())->toBe([
        ['bob', 'example.com', 'upload_max_filesize', 'no'],
        ['bob', 'example.com', 'post_max_size', 'yes'],
    ]);

    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput())->assertSessionHas('ok', 'Nothing has changed.');
});

it('refuses invalid values, values above the package maximum and post_max_size below upload_max_filesize', function () {
    $this->fake->on('v-change-web-domain-php-setting')->returns();
    signIn('bob', 'user');

    // one request: the changes share the limiter of 6 a minute
    $invalid = ['memory_limit' => '1T', 'max_execution_time' => '86401', 'max_input_vars' => '0', 'display_errors' => 'stderr', 'date_timezone' => 'Mars/Olympus'];
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput($invalid))->assertRedirect('/web/domains/bob/example.com/edit#php')
        ->assertSessionHasErrors(array_map(fn ($field) => 'settings.'.$field, array_keys($invalid)), null, 'phpSettings');
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '600M']))
        ->assertSessionHasErrors(['settings.memory_limit' => 'At most 512M on the package of this domain.'], null, 'phpSettings');
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['post_max_size' => '32M']))
        ->assertSessionHasErrors('settings.post_max_size', null, 'phpSettings');
    $this->fake->assertNotRan('v-change-web-domain-php-setting');
});

it('resets every setting', function () {
    $this->fake->on('v-delete-web-domain-php-setting')->returns();
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/php/settings/reset')->assertRedirect('/web/domains/bob/example.com/edit#php')
        ->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-delete-web-domain-php-setting', fn ($a) => $a === ['bob', 'example.com', 'all']);
});

it('reports a command that fails', function () {
    $this->fake->on('v-change-web-domain-php-setting')->returnsExit(ExitCode::Restart, 'Error: PHP-FPM 8.3 did not run with memory_limit 256M; the previous value was restored');
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '256M']))
        ->assertSessionHas('error', 'The PHP settings were not saved.')
        ->assertSessionHas('error_detail', 'Error: PHP-FPM 8.3 did not run with memory_limit 256M; the previous value was restored');
});

it('shows the warnings, capped values and a pool shared by the user', function () {
    $this->fake->on('v-list-web-domain-php-settings')->returnsJson(domainPhpSettingsRow(['POOL' => 'user', 'WARNINGS' => ['memory_limit 768M is above the package maximum 512M and applies as 512M']], [
        'memory_limit' => ['VALUE' => '768M', 'MAX' => '512M', 'INI' => '128M', 'PROFILE' => '128M', 'EFFECTIVE' => '512M', 'SOURCE' => 'capped'],
    ]));
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('memory_limit 768M is above the package maximum 512M and applies as 512M')
        ->assertSee('Capped')
        ->assertSee('These settings apply to every web domain of this user');
});

it('shows a notice instead of the settings when the backend template takes none', function () {
    $this->fake->on('v-list-web-domain-php-settings')->returnsJson(domainPhpSettingsRow(['SUPPORTED' => 'no']));
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('This backend template takes no PHP settings.')
        ->assertDontSee('name="settings[memory_limit]"', false);
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '256M']))->assertStatus(409);
});

it('lets the reseller of the owner change them and answers 404 outside the tree', function () {
    $this->fake->on('v-change-web-domain-php-setting')->returns();

    signIn('hostco', 'reseller');
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '256M']))->assertRedirect('/web/domains/bob/example.com/edit#php');

    signIn('resb', 'reseller');
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '256M']))->assertNotFound();
    $this->post('/web/domains/bob/example.com/php/settings/reset')->assertNotFound();

    signIn('carol', 'user');
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '256M']))->assertNotFound();
    $this->fake->assertRan('v-change-web-domain-php-setting', null, 1);
});

it('refuses changes in read-only mode', function () {
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeResellers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('PHP settings')->assertDontSee('Reset all');
    $this->post('/web/domains/bob/example.com/php/settings', phpSettingsInput(['memory_limit' => '256M']))->assertForbidden();
    $this->post('/web/domains/bob/example.com/php/settings/reset')->assertForbidden();
    $this->fake->assertNotRan('v-change-web-domain-php-setting');
});
