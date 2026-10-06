<?php

use App\Arc\ExitCode;
use App\Policies\Capabilities;

// Search page and the CSR generator (E7 batch 3 spec §12).

beforeEach(function () {
    $this->fake = fakeArc();
    fakeServerUsers($this->fake);
});

// Search

it('shows every group of the search with links', function () {
    $this->fake->on('v-search-object')->returnsJson([]);
    $this->fake->on('v-search-object', ['example', 'json'])->returnsFixture('v-search-object/example.json');
    signIn('arcadmin', 'admin');

    $this->get('/search?q=example')->assertOk()
        ->assertSee('Web domains')->assertSee('example.com')
        ->assertSee(route('web.domains.edit', ['user' => 'bob', 'domain' => 'example.com']), false);
    $this->get('/search?q=e')->assertOk()->assertSee('Type at least 2 characters');
    $this->get('/search?q=nothing')->assertOk()->assertSee('No results');
});

it('searches a user\'s own objects only', function () {
    $this->fake->on('v-search-user-object', ['bob', 'example', 'json'])->returnsFixture('v-search-object/example.json');
    signIn('bob', 'user');

    $this->get('/search?q=example')->assertOk()->assertSee('example.com');
    $this->fake->assertNotRan('v-search-object');
});

it('links the palette to the search page', function () {
    signIn('bob', 'user');

    $this->get('/search')->assertOk()->assertSee('View all results')
        ->assertSee('searchUrl: ', false)->assertSee(str_replace('/', '\/', route('search')), false);
});

// CSR generator

function csrForm(array $overrides = []): array
{
    return array_merge([
        'domain' => 'bob.example', 'aliases' => "www.bob.example\nshop.bob.example", 'email' => 'bob@example.com',
        'country' => 'de', 'state' => 'Berlin', 'locality' => 'Berlin', 'org' => 'Bob & Co', 'key_algo' => 'ecdsa-384',
    ], $overrides);
}

it('generates a CSR for a domain of the owner and answers it once, uncached', function () {
    fakeWebDomains($this->fake);
    $this->fake->on('v-generate-ssl-cert')->returnsJson(['bob.example' => [
        'CRT' => "-----BEGIN CERTIFICATE-----\nC\n-----END CERTIFICATE-----", 'KEY' => "-----BEGIN PRIVATE KEY-----\nK\n-----END PRIVATE KEY-----",
        'CSR' => "-----BEGIN CERTIFICATE REQUEST-----\nR\n-----END CERTIFICATE REQUEST-----", 'DIR' => '/tmp/x',
    ]]);
    signIn('bob', 'user');

    $this->postJson('/web/domains/bob/bob.example/ssl/generate', csrForm())->assertOk()
        ->assertJsonPath('csr', "-----BEGIN CERTIFICATE REQUEST-----\nR\n-----END CERTIFICATE REQUEST-----")
        ->assertJsonPath('key', "-----BEGIN PRIVATE KEY-----\nK\n-----END PRIVATE KEY-----")
        ->assertJsonMissingPath('DIR')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->fake->assertRan('v-generate-ssl-cert', fn ($a) => $a === [
        'bob.example', 'bob@example.com', 'DE', 'Berlin', 'Berlin', 'Bob & Co', 'IT', 'www.bob.example,shop.bob.example', 'json', 'ecdsa-384',
    ]);
});

it('validates the CSR fields and reports the command error', function () {
    fakeWebDomains($this->fake);
    $this->fake->on('v-generate-ssl-cert')->returnsExit(ExitCode::Invalid, 'Error: invalid state format :: Berlin;');
    signIn('bob', 'user');

    $this->postJson('/web/domains/bob/bob.example/ssl/generate', csrForm(['country' => 'Germany', 'email' => 'x']))
        ->assertStatus(422)->assertJsonValidationErrors(['country', 'email']);
    $this->postJson('/web/domains/bob/bob.example/ssl/generate', csrForm(['aliases' => 'not a domain']))
        ->assertStatus(422)->assertJsonValidationErrors(['aliases']);
    $this->fake->assertNotRan('v-generate-ssl-cert');

    $this->postJson('/web/domains/bob/bob.example/ssl/generate', csrForm())
        ->assertStatus(422)->assertJsonPath('error.message', 'Error: invalid state format :: Berlin;');
});

it('keeps the CSR generator to owners and administrators', function () {
    fakeResellers($this->fake);
    fakeWebDomains($this->fake);
    $this->fake->on('v-generate-ssl-cert')->returnsJson(['x' => ['CRT' => 'c', 'KEY' => 'k', 'CSR' => 'r']]);

    signIn('hostco', 'reseller');
    $this->postJson('/web/domains/carol/carol.example/ssl/generate', csrForm(['domain' => 'carol.example']))->assertNotFound();
    signIn('bob', 'user');
    $this->postJson('/server/settings/ssl/csr', csrForm(['domain' => 'srv1.example.net']))->assertForbidden();
    $this->fake->assertNotRan('v-generate-ssl-cert');

    signIn('arcadmin', 'admin');
    $this->postJson('/server/settings/ssl/csr', csrForm(['domain' => 'srv1.example.net', 'aliases' => '']))->assertOk()->assertJsonPath('crt', 'c');
    $this->fake->assertRan('v-generate-ssl-cert', fn ($a) => $a[0] === 'srv1.example.net' && $a[7] === '');
});

it('has capabilities for the search page and the CSR generator', function () {
    expect(Capabilities::names())->toContain('search.use')->toContain('web.ssl.generate');
});
