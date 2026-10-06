<?php

use App\Auth\Principal;
use App\Auth\Role;
use App\View\AssetManifest;
use App\View\LogLine;
use App\View\Navigation;
use App\View\UiPreferences;
use App\View\WhiteLabel;

it('maps the legacy themes to panel app themes', function (?string $legacy, string $theme, string $density) {
    expect(UiPreferences::fromLegacyTheme($legacy))->toBe([$theme, $density]);
})->with([
    'dark' => ['dark', 'nocturne', 'comfortable'],
    'default' => ['default', 'light', 'comfortable'],
    'vestia' => ['vestia', 'light', 'comfortable'],
    'flat' => ['flat', 'light', 'compact'],
    'custom' => ['my-theme', 'nocturne', 'comfortable'],
    'none' => [null, 'nocturne', 'comfortable'],
    // THEME holds a panel theme since 2.0 (E8 spec D11)
    'nocturne' => ['nocturne', 'nocturne', 'comfortable'],
    'light' => ['light', 'light', 'comfortable'],
    'auto' => ['auto', 'auto', 'comfortable'],
]);

it('finds the custom White Label logo in data/white-label', function () {
    $root = sys_get_temp_dir().'/arc-wl-'.bin2hex(random_bytes(4));
    mkdir($root.'/data/white-label', 0755, true);
    config(['arc.install_dir' => $root]);
    WhiteLabel::forget();
    expect(WhiteLabel::customLogo())->toBeFalse();

    touch($root.'/data/white-label/logo-header.svg');
    WhiteLabel::forget();
    expect(WhiteLabel::customLogo())->toBeTrue()
        ->and(WhiteLabel::LOGO_URL)->toBe('/images/custom/logo-header.svg');

    unlink($root.'/data/white-label/logo-header.svg');
    rmdir($root.'/data/white-label');
    rmdir($root.'/data');
    rmdir($root);
    WhiteLabel::forget();
});

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

    expect($keys)->toBe(['main', 'accounts', 'hosting', 'server', 'account'])
        ->and($items)->toHaveKeys(['dashboard', 'users.index', 'packages.index', 'web.domains.index', 'server.services'])
        ->and($items)->not->toHaveKeys(['server.firewall', 'files.index'])
        ->and($items['web.domains.index']['active'])->toBeTrue()
        ->and($items['web.domains.index']['url'])->toBe(route('web.domains.index'))
        ->and($items['dns.index']['url'])->toBe(route('dns.index'))
        ->and($items['mail.index']['url'])->toBe(route('mail.index'));

    $user = collect($navigation->for(principal('bob', Role::User), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');
    expect($user)->toHaveKeys(['dashboard', 'web.domains.index', 'dns.index', 'account.edit'])
        ->and($user)->not->toHaveKeys(['users.index', 'server.services', 'packages.index']);

    $impersonating = collect($navigation->for(principal('arcadmin', Role::Admin, look: 'bob'), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');
    expect($impersonating)->not->toHaveKey('users.index');
});

it('gives resellers customers, packages and their own hosting', function () {
    fakeArc();
    $navigation = app(Navigation::class);

    $reseller = collect($navigation->for(principal('hostco', Role::Reseller), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');
    expect($reseller)->toHaveKeys(['dashboard', 'users.index', 'packages.index', 'web.domains.index', 'dns.index', 'account.edit'])
        ->and($reseller)->not->toHaveKeys(['server.services', 'server.settings'])
        ->and($reseller['users.index']['label'])->toBe('Customers');
});

it('shows the batch 2 pages by capability, API_SYSTEM and log policy', function () {
    $fake = fakeArc(['API_SYSTEM' => '1', 'POLICY_USER_VIEW_LOGS' => 'no']);
    $items = fn (Principal $principal) => collect(app(Navigation::class)->for($principal, null))->flatMap(fn ($group) => $group['items'])->keyBy('key');

    $admin = $items(principal('arcadmin', Role::Admin));
    expect($admin)->toHaveKeys(['server.ips', 'server.firewall', 'server.rrd', 'logs.index', 'stats.index', 'access-keys.index', 'ssh-keys.index']);

    // API_SYSTEM=1: only the ROOT_USER has access keys; the log policy hides Logs for users
    $user = $items(principal('bob', Role::User));
    expect($user)->toHaveKeys(['stats.index', 'ssh-keys.index'])
        ->and($user)->not->toHaveKeys(['server.ips', 'server.firewall', 'server.rrd', 'access-keys.index', 'logs.index']);

    withSysConfig($fake, ['API_SYSTEM' => '2']);
    expect($items(principal('hostco', Role::Reseller)))->toHaveKeys(['access-keys.index', 'ssh-keys.index', 'logs.index', 'stats.index']);
});

it('hides the web terminal while a reseller acts as a customer', function () {
    fakeArc(['WEB_TERMINAL' => 'true']);
    $withShell = fn (string $user, Role $role, ?string $look = null) => new Principal(
        $user, $look, $role, $look !== null ? Role::User : $role, 'bash', 'en', null, str_repeat('e', 32), false);
    $items = fn (Principal $principal) => collect(app(Navigation::class)->for($principal, null))->flatMap(fn ($group) => $group['items'])->keyBy('key');

    expect($items($withShell('bob', Role::User)))->toHaveKey('terminal.index')
        ->and($items($withShell('arcadmin', Role::Admin, 'bob')))->toHaveKey('terminal.index')
        ->and($items($withShell('hostco', Role::Reseller, 'bob')))->not->toHaveKey('terminal.index')
        ->and($items(principal('bob', Role::User)))->not->toHaveKey('terminal.index');
});

it('keeps a reseller acting as a customer on panel app pages', function () {
    fakeArc();
    $items = collect(app(Navigation::class)->for(principal('hostco', Role::Reseller, look: 'bob'), null))->flatMap(fn ($group) => $group['items'])->keyBy('key');

    // The file manager is a panel app page since E3, DNS and Mail since E7
    expect($items)->toHaveKeys(['dashboard', 'web.domains.index', 'files.index', 'dns.index', 'mail.index'])
        ->and($items)->not->toHaveKeys(['users.index']);
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
