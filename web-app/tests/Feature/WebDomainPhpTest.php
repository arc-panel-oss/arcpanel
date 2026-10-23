<?php

use App\Arc\ExitCode;

// The PHP tab of a web domain and the backend templates of a package (E41 §7.5, §8).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-list-web-domain-php')->returnsJson(domainPhpRow());
});

it('shows the PHP tab with the allowed versions, the profiles and the effective limits', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('name="php_version"', false)
        ->assertSee('PHP 8.4')
        ->assertSee('Supported until 2027-12-31.')
        ->assertSee('value="small"', false)
        ->assertDontSee('value="large"', false)
        ->assertSee('Small sites with little traffic')
        ->assertSee('PHP-FPM profile')->assertSee('php.ini')
        ->assertDontSee('Outside the package');
});

it('marks values outside the package and templates without the profile line', function () {
    $this->fake->on('v-list-web-domain-php')->returnsJson(domainPhpRow([
        'VERSION' => '8.2', 'BACKEND' => 'custom-PHP-8_2', 'PROFILE' => 'large', 'PROFILE_APPLIED' => 'no',
        'VERSION_ALLOWED' => 'no', 'PROFILE_ALLOWED' => 'no', 'ALLOWED' => 'no',
    ]));
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('Outside the package')
        ->assertSee('PHP 8.2 · Outside the package')
        ->assertSee('value="large"', false)
        ->assertSee('Profile not applied')
        ->assertSee('custom-PHP-8_2');
});

it('changes the version and the profile within the package', function () {
    $this->fake->on('v-change-web-domain-php')->returns();
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'default'])
        ->assertRedirect('/web/domains/bob/example.com/edit#php')->assertSessionHas('ok', 'Changes have been saved.');
    $this->fake->assertRan('v-change-web-domain-php', fn ($a) => $a === ['bob', 'example.com', '8.4', 'default']);

    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.3', 'php_profile' => 'small'])->assertSessionHas('ok', 'Nothing has changed.');
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.2', 'php_profile' => 'small'])->assertSessionHasErrors('php_version', null, 'php');
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'large'])
        ->assertSessionHasErrors(['php_profile' => 'The package of this domain does not offer this profile.'], null, 'php');
    $this->fake->assertRan('v-change-web-domain-php', null, 1);

    $this->fake->on('v-change-web-domain-php')->returnsExit(ExitCode::Forbidden, 'Error: PHP 8.4 is not allowed by the package default');
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'small'])
        ->assertSessionHas('error', 'The PHP settings were not saved.')->assertSessionHas('error_detail', 'Error: PHP 8.4 is not allowed by the package default');
});

it('limits PHP changes to 6 a minute per account (E39 W5 P7)', function () {
    $this->fake->on('v-change-web-domain-php')->returns();
    signIn('bob', 'user');

    foreach (range(1, 6) as $i) {
        $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'default'])->assertRedirect();
    }
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'default'])->assertStatus(429);
    $this->fake->assertRan('v-change-web-domain-php', null, 6);
});

it('lets the reseller of the owner change it and answers 404 outside the tree', function () {
    $this->fake->on('v-change-web-domain-php')->returns();

    signIn('hostco', 'reseller');
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'small'])->assertRedirect('/web/domains/bob/example.com/edit#php');

    signIn('resb', 'reseller');
    $this->get('/web/domains/bob/example.com/edit')->assertNotFound();
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'small'])->assertNotFound();

    signIn('carol', 'user');
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'small'])->assertNotFound();
    $this->fake->assertRan('v-change-web-domain-php', null, 1);
});

it('refuses changes in read-only mode', function () {
    // a suspended user may look but not change
    withSysConfig($this->fake, ['POLICY_USER_VIEW_SUSPENDED' => 'yes']);
    fakeResellers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');
    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('name="php_version"', false);
    $this->post('/web/domains/bob/example.com/php', ['php_version' => '8.4', 'php_profile' => 'small'])->assertForbidden();
    $this->fake->assertNotRan('v-change-web-domain-php');
});

it('offers users only the backend templates of PHP versions their package allows', function () {
    withSysConfig($this->fake, ['POLICY_USER_EDIT_WEB_TEMPLATES' => 'yes']);
    $this->fake->on('v-list-web-templates-backend', ['json'])->returnsJson(['PHP-8_2', 'PHP-8_3', 'PHP-8_4', 'default', 'no-php']);
    $this->fake->on('v-list-package-tenancy', ['default', 'json'])->returnsJson(['default' => ['PHP_VERSIONS' => '8.3,8.4']]);
    $this->fake->on('v-change-web-domain-backend-tpl')->returns();
    $this->fake->on('v-restart-web')->returns();
    $this->fake->on('v-restart-web-backend')->returns();
    $this->fake->on('v-restart-proxy')->returns();
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('value="PHP-8_4"', false)->assertDontSee('value="PHP-8_2"', false)->assertSee('value="no-php"', false);
    $this->post('/web/domains/bob/example.com/advanced', ['backend_template' => 'PHP-8_2', 'template' => 'default', 'proxy_template' => 'default'])
        ->assertSessionHasErrors('backend_template', null, 'advanced');
    $this->fake->assertNotRan('v-change-web-domain-backend-tpl');

    // administrators see every template
    signIn('arcadmin', 'admin');
    $this->get('/web/domains/bob/example.com/edit')->assertOk()->assertSee('value="PHP-8_2"', false);
});
