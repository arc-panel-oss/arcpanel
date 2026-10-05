<?php

use App\Auth\Role;
use App\View\AssetManifest;
use App\View\LogLine;
use App\View\Navigation;
use App\View\UiPreferences;

it('maps the legacy themes to panel app themes', function (?string $legacy, string $theme, string $density) {
    expect(UiPreferences::fromLegacyTheme($legacy))->toBe([$theme, $density]);
})->with([
    'dark' => ['dark', 'nocturne', 'comfortable'],
    'default' => ['default', 'light', 'comfortable'],
    'vestia' => ['vestia', 'light', 'comfortable'],
    'flat' => ['flat', 'light', 'compact'],
    'custom' => ['my-theme', 'nocturne', 'comfortable'],
    'none' => [null, 'nocturne', 'comfortable'],
]);

it('prefers the arc_ui cookie and ignores invalid values', function () {
    $prefs = UiPreferences::resolve('{"theme":"light","density":"compact"}', 'dark', true);
    expect([$prefs->theme, $prefs->density])->toBe(['light', 'compact']);

    $prefs = UiPreferences::resolve('{"theme":"pink","density":"huge"}', 'flat', true);
    expect([$prefs->theme, $prefs->density])->toBe(['light', 'compact']);

    $prefs = UiPreferences::resolve('not json', null, true);
    expect([$prefs->theme, $prefs->density])->toBe(['nocturne', 'comfortable']);
});

it('ignores the theme of the cookie when the policy forbids changing it', function () {
    $prefs = UiPreferences::resolve('{"theme":"light","density":"compact"}', 'dark', false);

    expect([$prefs->theme, $prefs->density, $prefs->canChangeTheme])->toBe(['nocturne', 'compact', false]);
});

it('builds the sidebar from the capabilities of the acting role', function () {
    fakeArc(['FIREWALL_SYSTEM' => '', 'FILE_MANAGER' => 'false']);
    $navigation = app(Navigation::class);

    $admin = $navigation->for(principal('arcadmin', Role::Admin), 'web.domains.edit');
    $keys = collect($admin)->pluck('key')->all();
    $items = collect($admin)->flatMap(fn ($group) => $group['items'])->keyBy('key');

    expect($keys)->toBe(['main', 'hosting', 'server', 'account'])
        ->and($items)->toHaveKeys(['dashboard', 'users.index', 'web.domains.index', 'server.services'])
        ->and($items)->not->toHaveKeys(['server.firewall', 'files.index'])
        ->and($items['web.domains.index']['active'])->toBeTrue()
        ->and($items['web.domains.index']['url'])->toBe(route('web.domains.index'))
        ->and($items['dns.index']['url'])->toBe('/list/dns/');

    $user = collect($navigation->for(principal('bob', Role::User), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');
    expect($user)->toHaveKeys(['dashboard', 'web.domains.index', 'dns.index', 'account.edit'])
        ->and($user)->not->toHaveKeys(['users.index', 'server.services', 'packages.index']);

    $impersonating = collect($navigation->for(principal('arcadmin', Role::Admin, look: 'bob'), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');
    expect($impersonating)->not->toHaveKey('users.index');
});

it('hides modules the server does not run', function () {
    fakeArc(['DNS_SYSTEM' => '', 'MAIL_SYSTEM' => '']);
    $items = collect(app(Navigation::class)->for(principal('bob', Role::User), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');

    expect($items)->not->toHaveKeys(['dns.index', 'mail.index'])->and($items)->toHaveKey('db.index');
});

it('resolves hashed asset names from the manifest', function () {
    $file = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($file, json_encode(['app.js' => 'app.ABC123.js']));

    $manifest = new AssetManifest($file);
    expect($manifest->url('app.js'))->toEndWith('/build/app.ABC123.js')
        ->and($manifest->url('missing.css'))->toEndWith('/build/missing.css');

    unlink($file);
});

it('classifies log lines like the log script', function () {
    expect(LogLine::level('[error] connect() failed'))->toBe('is-error')
        ->and(LogLine::level('1.2.3.4 - - "GET / HTTP/1.1" 502 10'))->toBe('is-error')
        ->and(LogLine::level('1.2.3.4 - - "GET /x HTTP/1.1" 404 10'))->toBe('is-warn')
        ->and(LogLine::level('1.2.3.4 - - "GET / HTTP/1.1" 200 10'))->toBe('');
});
