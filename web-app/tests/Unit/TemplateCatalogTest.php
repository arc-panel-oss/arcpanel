<?php

use App\Services\Ports\PortAccount;
use App\Services\WebDomains\BackendPorts;
use App\Services\WebDomains\Tabs\TemplateVarsTab;
use App\Services\WebDomains\TemplateCatalog;
use App\Services\WebDomains\TemplateMeta;
use App\Services\WebDomains\WebDomainRecord;

/** Catalog on a fake server; $config overrides v-list-sys-config. */
function templateCatalog(array $config = []): array
{
    $fake = fakeArc($config);
    $fake->on('v-list-web-templates-meta', ['web', 'json'])->returnsFixture('v-list-web-templates-meta/web.json');
    $fake->on('v-list-web-templates-meta', ['proxy', 'json'])->returnsFixture('v-list-web-templates-meta/proxy.json');

    return [$fake, app(TemplateCatalog::class)];
}

function nodeMeta(): TemplateMeta
{
    return TemplateMeta::from(commandFixture('v-list-web-templates-meta/proxy.json')[4]);
}

function domainRecord(array $overrides = []): WebDomainRecord
{
    $row = array_merge(commandFixture('v-list-web-domain/bob-example.com.json')['example.com'], $overrides);

    return WebDomainRecord::fromArray('bob', 'example.com', $row);
}

/** @param  list<array<string, string>>  $entries */
function portPool(array $entries, array $overrides = []): PortAccount
{
    return PortAccount::from('bob', portAccount('bob', $overrides, $entries));
}

// TemplateCatalog (spec §7.1)

it('reads the metadata of each kind once and caches it', function () {
    [$fake, $catalog] = templateCatalog();

    expect($catalog->names('proxy'))->toBe(['caching', 'custom-tpl', 'default', 'hosting', 'nodejs-proxy', 'static', 'suspended'])
        ->and($catalog->find('proxy', 'nodejs-proxy')->portVariable())->toBe('BACKEND_PORT')
        ->and($catalog->find('proxy', 'default')->portVariable())->toBeNull()
        ->and($catalog->find('proxy', 'custom-tpl')->hasMeta)->toBeFalse()
        ->and($catalog->portTemplates('proxy'))->toBe(['nodejs-proxy']);

    app()->forgetInstance(TemplateCatalog::class);
    app(TemplateCatalog::class)->names('proxy');
    $fake->assertRan('v-list-web-templates-meta', fn ($a) => $a[0] === 'proxy', times: 1);
});

it('leaves internal templates out of the picker unless they are current, sorted by title', function () {
    [, $catalog] = templateCatalog();

    $options = $catalog->pickerOptions('proxy');
    expect($options)->not->toHaveKey('suspended')
        ->and(array_keys($options))->toBe(['caching', 'custom-tpl', 'nodejs-proxy', 'hosting', 'default', 'static'])
        ->and($options['nodejs-proxy'])->toMatchArray(['title' => 'Node.js app', 'category' => 'nodejs', 'badge' => 'Node.js', 'meta' => 'nodejs-proxy'])
        ->and($options['static']['badge'])->toBeNull()
        ->and($catalog->pickerOptions('proxy', 'suspended'))->toHaveKey('suspended');
});

it('knows which kind faces visitors on each stack', function () {
    expect(templateCatalog()[1]->activeKind())->toBe('proxy')
        ->and(templateCatalog(['WEB_SYSTEM' => 'nginx', 'PROXY_SYSTEM' => ''])[1]->activeKind())->toBe('web')
        ->and(templateCatalog(['WEB_SYSTEM' => 'apache2', 'PROXY_SYSTEM' => ''])[1]->activeKind())->toBeNull();

    [$fake, $catalog] = templateCatalog(['PROXY_SYSTEM' => '']);
    expect($catalog->templates('proxy'))->toBe([]);
    $fake->assertNotRan('v-list-web-templates-meta');
});

it('titles templates from their metadata and falls back to the name', function () {
    [, $catalog] = templateCatalog();

    expect($catalog->title('proxy', 'default'))->toBe('Standard proxy')
        ->and($catalog->title('proxy', 'custom-tpl'))->toBe('custom-tpl')
        ->and($catalog->title('proxy', 'gone'))->toBe('gone');
});

// TemplateVarsTab (spec §7.2)

it('sets the port when the new template takes one and nothing is stored', function () {
    $tab = new TemplateVarsTab;

    $steps = $tab->plan(domainRecord(), nodeMeta(), null, [], true);
    expect($steps)->toHaveCount(1)
        ->and($steps[0]->command)->toBe('v-change-web-domain-tpl-vars')
        ->and($steps[0]->args)->toBe(['bob', 'example.com', 'BACKEND_PORT=auto']);

    expect($tab->plan(domainRecord(), nodeMeta(), '30005', ['BACKEND_PORT' => '30001'], false)[0]->args[2])->toBe('BACKEND_PORT=30005');
});

it('keeps a stored port when nothing changed and releases it for a template without port', function () {
    $tab = new TemplateVarsTab;
    $stored = ['BACKEND_PORT' => '30001'];

    expect($tab->plan(domainRecord(), nodeMeta(), 'auto', $stored, false))->toBe([])
        ->and($tab->plan(domainRecord(), nodeMeta(), '30001', $stored, false))->toBe([])
        ->and($tab->plan(domainRecord(), nodeMeta(), 'auto', $stored, true))->toHaveCount(1);

    $default = TemplateMeta::from(commandFixture('v-list-web-templates-meta/proxy.json')[2]);
    $steps = $tab->plan(domainRecord(), $default, null, $stored, true);
    expect($steps[0]->command)->toBe('v-delete-web-domain-tpl-vars')
        ->and($tab->plan(domainRecord(), $default, null, [], true))->toBe([])
        ->and($tab->plan(domainRecord(), null, null, $stored, true)[0]->command)->toBe('v-delete-web-domain-tpl-vars');
});

it('finds the visitor-facing template after a change', function () {
    $record = domainRecord(['PROXY' => 'default', 'TPL' => 'default']);

    expect(TemplateVarsTab::activeTemplate('proxy', $record, ['proxy' => true, 'proxy_template' => 'nodejs-proxy']))->toBe('nodejs-proxy')
        ->and(TemplateVarsTab::activeTemplate('proxy', $record, ['proxy' => true, 'proxy_template' => '']))->toBe('default')
        ->and(TemplateVarsTab::activeTemplate('proxy', $record, ['proxy' => false]))->toBeNull()
        ->and(TemplateVarsTab::activeTemplate('web', $record, ['template' => 'static']))->toBe('static')
        ->and(TemplateVarsTab::activeTemplate(null, $record, []))->toBeNull()
        ->and(TemplateVarsTab::currentTemplate('proxy', $record))->toBe('default')
        ->and(TemplateVarsTab::currentTemplate('proxy', domainRecord(['PROXY' => ''])))->toBeNull();
});

it('words the refusals of the port command for the port field', function () {
    expect(TemplateVarsTab::error(10, 'Error: port 30020 is not in the pool of bob', 'bob'))->toBe('This port is not in the port pool of bob.')
        ->and(TemplateVarsTab::error(8, 'Error: no free ports', 'bob'))->toContain('Ask your provider')
        ->and(TemplateVarsTab::error(7, 'Error: port 30001 is used by web:shop.example', 'bob'))->toContain('web:shop.example')
        ->and(TemplateVarsTab::error(20, 'Error: the web server refused', 'bob'))->toBe('the web server refused');
});

// BackendPorts (spec §5.5)

it('offers auto, the current port, usable app ports and free ports', function () {
    $account = portPool([
        portEntry('30000-30002'),
        portEntry('30003', ['APP' => 'manual', 'COMMENT' => 'by hand']),
        portEntry('30004', ['APP' => 'web:shop.example']),
        portEntry('30005', ['APP' => 'docker:gitea']),
        portEntry('30006', ['APP' => 'node:example.com']),
    ]);

    expect(BackendPorts::choices($account, 'example.com', '30009'))->toBe([
        'auto' => 'Assign automatically from my pool',
        '30009' => '30009 (current)',
        '30000' => '30000',
        '30001' => '30001',
        '30002' => '30002',
        '30003' => '30003 · manual · by hand',
        '30005' => '30005 · docker:gitea',
        '30006' => '30006 · node:example.com',
    ]);

    expect(BackendPorts::accepts($account, 'example.com', '', 'auto'))->toBeTrue()
        ->and(BackendPorts::accepts($account, 'example.com', '', '30001'))->toBeTrue()
        ->and(BackendPorts::accepts($account, 'example.com', '', '30003'))->toBeTrue()
        ->and(BackendPorts::accepts($account, 'example.com', '', '30004'))->toBeFalse()
        ->and(BackendPorts::accepts($account, 'shop.example', '', '30004'))->toBeTrue()
        ->and(BackendPorts::accepts($account, 'example.com', '', '30006'))->toBeTrue()
        ->and(BackendPorts::accepts($account, 'example.com', '', '39999'))->toBeFalse()
        ->and(BackendPorts::accepts($account, 'example.com', '39999', '39999'))->toBeTrue()
        ->and(BackendPorts::accepts($account, 'example.com', '', '80x'))->toBeFalse();
});

it('lists at most fifty free ports and knows an exhausted pool', function () {
    $big = portPool([portEntry('30000-30199')], ['FREE' => '200', 'ALLOCATED' => '200', 'LIMIT' => '200']);
    expect(count(BackendPorts::choices($big, 'example.com')))->toBe(BackendPorts::FREE_LIMIT + 1)
        ->and(BackendPorts::poolExhausted($big))->toBeFalse()
        ->and(BackendPorts::poolExhausted(portPool([], ['LIMIT' => '0'])))->toBeTrue()
        ->and(BackendPorts::poolExhausted(portPool([], ['LIMIT' => 'unlimited'])))->toBeFalse();
});

it('colours the runtime badge and adds a note when the runtime is missing (E5)', function () {
    [, $catalog] = templateCatalog();

    $options = $catalog->pickerOptions('proxy', null, fn (string $runtime): ?bool => $runtime === 'php');
    expect($options['nodejs-proxy'])->toMatchArray(['badge' => 'Node.js', 'badgeTone' => 'danger', 'note' => 'Node.js is not installed on this server.'])
        ->and($options['default'])->toMatchArray(['badgeTone' => 'ok', 'note' => null])
        ->and($options['static'])->toMatchArray(['badge' => null, 'badgeTone' => null, 'note' => null])
        ->and($catalog->pickerOptions('proxy')['nodejs-proxy'])->toMatchArray(['badgeTone' => null, 'note' => null])
        ->and($catalog->categories())->toHaveKeys(['go', 'ruby']);
});
