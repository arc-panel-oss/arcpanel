<?php

use App\Arc\SysConfig;

beforeEach(function () {
    $this->fake = fakeArc(['API_ALLOWED_IP' => '192.0.2.1']);
    $this->stamp = tempnam(sys_get_temp_dir(), 'arc-stamp-');
    file_put_contents($this->stamp, "1760000000000000001\n");
    config()->set('arc.sys_config_stamp', $this->stamp);
});

afterEach(function () {
    @unlink($this->stamp);
});

/** SysConfig as the next request builds it: the cache survives, the instance does not. */
function nextRequestConfig(): SysConfig
{
    app()->forgetInstance(SysConfig::class);

    return app(SysConfig::class);
}

function answerSysConfig(object $test, array $config): void
{
    $test->fake->on('v-list-sys-config', ['json'])->returnsJson(sysConfig($config));
}

it('keeps the cached config while the stamp is unchanged', function () {
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
    answerSysConfig($this, ['API_ALLOWED_IP' => '192.0.2.1,198.51.100.7']);

    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
    $this->fake->assertRan('v-list-sys-config', times: 1);
});

it('reads the config again once a command writes a new stamp', function () {
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
    answerSysConfig($this, ['API_ALLOWED_IP' => '192.0.2.1,198.51.100.7']);
    file_put_contents($this->stamp, "1760000000000000002\n");

    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1,198.51.100.7');
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1,198.51.100.7');
    $this->fake->assertRan('v-list-sys-config', times: 2);
});

it('reads the config again when the stamp appears or disappears', function () {
    unlink($this->stamp);
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');

    answerSysConfig($this, ['API_ALLOWED_IP' => '198.51.100.7']);
    file_put_contents($this->stamp, "1760000000000000003\n");
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('198.51.100.7');

    answerSysConfig($this, ['API_ALLOWED_IP' => '203.0.113.9']);
    unlink($this->stamp);
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('203.0.113.9');
});

it('caches for the TTL without a stamp file', function () {
    config()->set('arc.sys_config_stamp', '');
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
    answerSysConfig($this, ['API_ALLOWED_IP' => '198.51.100.7']);

    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
    $this->travel(config('arc.sys_config_ttl') + 1)->seconds();
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('198.51.100.7');
});

it('replaces a cache entry written before the stamp existed', function () {
    cache()->put('arc.sys-config', ['API_ALLOWED_IP' => '10.9.9.9'], 60);

    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
});

it('forgets the cached config on request', function () {
    expect(nextRequestConfig()->get('API_ALLOWED_IP'))->toBe('192.0.2.1');
    answerSysConfig($this, ['API_ALLOWED_IP' => '198.51.100.7']);

    $config = app(SysConfig::class);
    $config->forget();

    expect($config->get('API_ALLOWED_IP'))->toBe('198.51.100.7');
});
