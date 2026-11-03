<?php

use App\Arc\SysConfig;

// The FTP tab of a web domain: a table of the additional accounts with row
// actions, bulk delete and a dialog to add one.

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
    fakeWebDomains($this->fake);
});

it('lists the FTP accounts in a table with row actions, bulk delete and an add dialog', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('id="ftp-accounts"', false)
        ->assertSee('bob_deploy')->assertSee('releases')
        ->assertSee('FTP account')
        ->assertSee("\$dispatch('ap-modal', { id: 'ftp-add' })", false)
        ->assertSee('Add FTP account')
        ->assertSee('Edit FTP account')
        ->assertSee('Are you sure you want to delete FTP account bob_deploy?')
        ->assertSee('id="ftp-accounts-bulk"', false)->assertSee('value="delete"', false)
        // the old inline form is gone
        ->assertDontSee('Delete this account');
});

it('shows an empty state with the add button without FTP accounts', function () {
    signIn('bob', 'user');

    $this->get('/web/domains/bob/shop.example/edit')->assertOk()
        ->assertSee('No additional FTP accounts')
        ->assertDontSee('id="ftp-accounts-bulk"', false);
});

it('deletes the selected FTP accounts, and only accounts of the domain', function () {
    signIn('bob', 'user');
    $this->fake->on('v-delete-web-domain-ftp')->returns();

    $this->post('/web/domains/bob/example.com/ftp', ['action' => 'delete', 'items' => ['bob_deploy', 'bob_other']])
        ->assertRedirect()->assertSessionHas('ok');

    $this->fake->assertRan('v-delete-web-domain-ftp', fn ($a) => $a === ['bob', 'example.com', 'bob_deploy']);
    $this->fake->assertRan('v-delete-web-domain-ftp', fn ($a) => $a[2] === 'bob_other', 0);
});

it('refuses an unknown bulk action', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/ftp', ['action' => 'suspend', 'items' => ['bob_deploy']])
        ->assertSessionHasErrors('action', null, 'ftp');
    $this->fake->assertNotRan('v-delete-web-domain-ftp');
});

it('changes only the password from the edit dialog when the path stays', function () {
    signIn('bob', 'user');
    $this->fake->on('v-change-web-domain-ftp-password')->returns();

    $this->post('/web/domains/bob/example.com/ftp', ['accounts' => [['user' => 'bob_deploy', 'path' => 'releases', 'password' => 'Secret-456']]])
        ->assertSessionHas('ok');

    $this->fake->assertRan('v-change-web-domain-ftp-password', fn ($a) => $a[2] === 'bob_deploy');
    $this->fake->assertNotRan('v-change-web-domain-ftp-path');
});

it('takes a path with a leading slash for the same path', function () {
    $domain = json_decode((string) file_get_contents(base_path('tests/Fixtures/commands/v-list-web-domain/bob-example.com.json')), true);
    $domain['example.com']['FTP_PATH'] = '/releases';
    $this->fake->on('v-list-web-domain', ['bob', 'example.com', 'json'])->returnsJson($domain);
    signIn('bob', 'user');
    $this->fake->on('v-change-web-domain-ftp-path')->returns();

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('example.com/</span>releases', false)->assertDontSee('example.com/</span>/releases', false);
    $this->post('/web/domains/bob/example.com/ftp', ['accounts' => [['user' => 'bob_deploy', 'path' => 'releases']]])->assertSessionHas('ok');
    $this->fake->assertNotRan('v-change-web-domain-ftp-path');
});

it('opens the add dialog again with the errors of a refused account', function () {
    signIn('bob', 'user');

    $this->post('/web/domains/bob/example.com/ftp', ['new' => ['user' => 'web']])
        ->assertSessionHasErrors('new.password', null, 'ftp');

    $this->followingRedirects()->post('/web/domains/bob/example.com/ftp', ['new' => ['user' => 'web']])->assertOk()
        ->assertSee("apModal({ id: 'ftp-add', open: true })", false)
        ->assertSee("apModal({ id: 'ftp-edit', open: false })", false);
});

it('keeps the FTP actions away from a read-only view', function () {
    $this->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig(['POLICY_USER_VIEW_SUSPENDED' => 'yes']));
    cache()->flush();
    app()->forgetInstance(SysConfig::class);
    fakeServerUsers($this->fake, ['bob' => ['SUSPENDED' => 'yes']]);
    signIn('bob', 'user');

    $this->get('/web/domains/bob/example.com/edit')->assertOk()
        ->assertSee('bob_deploy')
        ->assertDontSee("\$dispatch('ap-modal', { id: 'ftp-add' })", false)
        ->assertDontSee('id="ftp-accounts-bulk"', false);
});
