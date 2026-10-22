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

it('reads the sidebar layout from the cookie, full by default', function () {
    expect(UiPreferences::resolve('{"theme":"light","density":"compact","sidebar":"rail"}', null, true)->sidebar)->toBe('rail')
        ->and(UiPreferences::resolve('{"theme":"light"}', null, true)->sidebar)->toBe('full')
        ->and(UiPreferences::resolve('{"sidebar":"hidden"}', null, true)->sidebar)->toBe('full')
        ->and(UiPreferences::resolve(null, null, true)->sidebar)->toBe('full');
});

it('ignores the theme of the cookie when the policy forbids changing it', function () {
    $prefs = UiPreferences::resolve('{"theme":"light","density":"compact"}', 'dark', false);

    expect([$prefs->theme, $prefs->density, $prefs->canChangeTheme])->toBe(['nocturne', 'compact', false]);
});

it('builds the sidebar from the capabilities of the acting role', function () {
    fakeArc(['FIREWALL_SYSTEM' => '', 'FILE_MANAGER' => 'false']);
    $navigation = app(Navigation::class);

    $keys = collect($navigation->for(principal('arcadmin', Role::Admin), 'web.domains.edit'))->pluck('key')->all();
    $items = navItems(principal('arcadmin', Role::Admin), 'web.domains.edit');

    expect($keys)->toBe(['main', 'accounts', 'hosting', 'server', 'account'])
        ->and($items)->toHaveKeys(['dashboard', 'users.index', 'packages.index', 'web.domains.index', 'server.services'])
        ->and($items)->not->toHaveKeys(['server.firewall', 'files.index'])
        ->and($items['web.domains.index']['active'])->toBeTrue()
        ->and($items['web.domains.index']['url'])->toBe(route('web.domains.index'))
        ->and($items['dns.index']['url'])->toBe(route('dns.index'))
        ->and($items['mail.index']['url'])->toBe(route('mail.index'));

    $user = navItems(principal('bob', Role::User));
    // My account is in the user menu of the top bar (sidebar hubs spec H6)
    expect($user)->toHaveKeys(['dashboard', 'web.domains.index', 'dns.index'])
        ->and($user)->not->toHaveKeys(['users.index', 'server.services', 'packages.index', 'account.edit']);

    $impersonating = navItems(principal('arcadmin', Role::Admin, look: 'bob'));
    expect($impersonating)->not->toHaveKey('users.index');
});

it('offers the create pages of the visible hosting sections as quick actions', function () {
    fakeArc(['DNS_SYSTEM' => '', 'FILE_MANAGER' => 'true']);
    $navigation = app(Navigation::class);

    $keys = collect($navigation->quickActions(principal('bob', Role::User)))->pluck('key')->all();
    expect($keys)->toBe(['web.domains.create', 'mail.create', 'db.create', 'cron.create', 'files.index']);

    $first = $navigation->quickActions(principal('bob', Role::User))[0];
    expect($first)->toBe(['key' => 'web.domains.create', 'label' => 'Add Web Domain', 'icon' => 'globe', 'url' => route('web.domains.create')]);

    expect($navigation->quickActions(principal('bob', Role::User, readOnly: true)))->toBe([]);
});

it('gives resellers customers, packages and their own hosting', function () {
    fakeArc();
    $navigation = app(Navigation::class);

    $reseller = navItems(principal('hostco', Role::Reseller));
    expect($reseller)->toHaveKeys(['dashboard', 'users.index', 'packages.index', 'web.domains.index', 'dns.index'])
        ->and($reseller)->not->toHaveKeys(['server.services', 'server.settings'])
        ->and($reseller['users.index']['label'])->toBe('Customers');
});

it('shows the batch 2 pages by capability, API_SYSTEM and log policy', function () {
    $fake = fakeArc(['API_SYSTEM' => '1', 'POLICY_USER_VIEW_LOGS' => 'no']);
    $items = fn (Principal $principal) => navItems($principal);

    $admin = $items(principal('arcadmin', Role::Admin));
    expect($admin)->toHaveKeys(['server.ips', 'server.firewall', 'server.rrd', 'logs.index', 'stats.index', 'access-keys.index', 'ssh-keys.index']);

    // API_SYSTEM=1: only the ROOT_USER has access keys; the log policy hides Logs for users
    $user = $items(principal('bob', Role::User));
    expect($user)->toHaveKeys(['stats.index', 'ssh-keys.index', 'secrets.index'])
        ->and($user)->not->toHaveKeys(['server.ips', 'server.firewall', 'server.rrd', 'access-keys.index', 'logs.index']);

    withSysConfig($fake, ['API_SYSTEM' => '2']);
    expect($items(principal('hostco', Role::Reseller)))->toHaveKeys(['access-keys.index', 'ssh-keys.index', 'secrets.index', 'logs.index', 'stats.index']);
});

it('hides the web terminal while a reseller acts as a customer', function () {
    fakeArc(['WEB_TERMINAL' => 'true']);
    $withShell = fn (string $user, Role $role, ?string $look = null) => new Principal(
        $user, $look, $role, $look !== null ? Role::User : $role, 'bash', 'en', null, str_repeat('e', 32), false);
    $items = fn (Principal $principal) => navItems($principal);

    expect($items($withShell('bob', Role::User)))->toHaveKey('terminal.index')
        ->and($items($withShell('arcadmin', Role::Admin, 'bob')))->toHaveKey('terminal.index')
        ->and($items($withShell('hostco', Role::Reseller, 'bob')))->not->toHaveKey('terminal.index')
        ->and($items(principal('bob', Role::User)))->not->toHaveKey('terminal.index');
});

it('keeps a reseller acting as a customer on panel app pages', function () {
    fakeArc();
    $items = navItems(principal('hostco', Role::Reseller, look: 'bob'));

    // The file manager is a panel app page since E3, DNS and Mail since E7
    expect($items)->toHaveKeys(['dashboard', 'web.domains.index', 'files.index', 'dns.index', 'mail.index'])
        ->and($items)->not->toHaveKeys(['users.index']);
});

it('hides modules the server does not run', function () {
    fakeArc(['DNS_SYSTEM' => '', 'MAIL_SYSTEM' => '']);
    $items = navItems(principal('bob', Role::User));

    expect($items)->not->toHaveKeys(['dns.index', 'mail.index'])->and($items)->toHaveKey('db.index');
});

it('folds related pages into hubs (sidebar hubs spec H4 to H6)', function () {
    fakeArc(['WEB_TERMINAL' => 'true', 'DOCKER_SYSTEM' => 'yes', 'MALWARE_SCAN' => 'yes', 'API_SYSTEM' => '2']);
    $admin = new Principal('arcadmin', null, Role::Admin, Role::Admin, 'bash', 'en', null, str_repeat('e', 32), false);
    $groups = collect(app(Navigation::class)->for($admin, null))->mapWithKeys(fn ($group) => [$group['key'] => collect($group['items'])->pluck('key')->all()]);

    expect($groups['hosting'])->toBe(['web.domains.index', 'dns.index', 'mail.index', 'db.index', 'cron.index', 'backups.index', 'malware.index', 'hub.files', 'hub.node', 'app-processes', 'hub.docker', 'server.object-storage'])
        ->and($groups['server'])->toBe(['server.settings', 'hub.server-backups', 'hub.services', 'hub.network', 'server.php.index', 'hub.updates'])
        ->and($groups['account'])->toBe(['logs.index', 'stats.index', 'hub.keys', 'secrets.index']);

    $items = navItems($admin);
    $children = fn (string $hub) => collect($items[$hub]['children'])->map(fn ($child) => [$child['key'], $child['tab']])->all();
    expect($items['hub.node']['label'])->toBe('Node apps')
        ->and($children('hub.node'))->toBe([['node-apps', 'Apps'], ['node.index', 'Runtimes']])
        ->and($children('hub.files'))->toBe([['files.index', 'File manager'], ['terminal.index', 'Terminal']])
        ->and($children('hub.docker'))->toBe([['docker-apps', 'Apps'], ['server.docker', 'Engine']])
        ->and($children('hub.services'))->toBe([['server.services', 'Services'], ['server.rrd', 'Task monitor']])
        ->and($children('hub.network'))->toBe([['server.ips', 'IP addresses'], ['server.firewall', 'Firewall'], ['server.ports', 'Ports']])
        ->and($children('hub.updates'))->toBe([['server.updates', 'ArcPanel'], ['server.packages', 'System packages']])
        ->and($children('hub.keys'))->toBe([['access-keys.index', 'Access keys'], ['ssh-keys.index', 'SSH keys']])
        ->and($items)->not->toHaveKey('account.edit');
});

it('links a hub to its first visible page and marks it on any of its pages', function () {
    fakeArc();
    $items = navItems(principal('arcadmin', Role::Admin), 'server.firewall.bans');

    expect($items['hub.network'])->toMatchArray(['label' => 'Network', 'icon' => 'hash', 'url' => route('server.ips'), 'active' => true])
        ->and($items['server.firewall']['active'])->toBeTrue()
        ->and($items['server.ips']['active'])->toBeFalse()
        ->and($items['hub.services']['active'])->toBeFalse();

    // Without a firewall the hub keeps IP addresses and Ports
    fakeArc(['FIREWALL_SYSTEM' => '']);
    expect(collect(navItems(principal('arcadmin', Role::Admin))['hub.network']['children'])->pluck('key')->all())->toBe(['server.ips', 'server.ports']);
});

it('shows a hub with one visible page as that page, without tabs', function () {
    fakeArc(['WEB_TERMINAL' => 'true']);
    $bob = principal('bob', Role::User); // nologin shell: no web terminal
    $items = navItems($bob, 'files.show');

    expect($items)->toHaveKey('files.index')->not->toHaveKey('hub.files')
        ->and($items['files.index'])->toMatchArray(['label' => 'File manager', 'icon' => 'folder', 'url' => route('files.index'), 'active' => true])
        ->and(app(Navigation::class)->tabs($bob, 'files.show', ['user' => 'bob']))->toBeNull();
});

it('puts the tabs of a hub on the landing pages of its pages only', function () {
    fakeArc();
    $navigation = app(Navigation::class);
    $admin = principal('arcadmin', Role::Admin);

    expect($navigation->tabs($admin, 'server.firewall.bans', []))->toBe([
        'label' => 'Network',
        'items' => [
            ['key' => 'server.ips', 'label' => 'IP addresses', 'url' => route('server.ips'), 'current' => false],
            ['key' => 'server.firewall', 'label' => 'Firewall', 'url' => route('server.firewall'), 'current' => true],
            ['key' => 'server.ports', 'label' => 'Ports', 'url' => route('server.ports'), 'current' => false],
        ],
    ]);
    expect($navigation->tabs($admin, 'server.firewall.create', []))->toBeNull()
        ->and($navigation->tabs($admin, 'web.domains.index', []))->toBeNull()
        ->and($navigation->tabs($admin, null, []))->toBeNull();
});

it('keeps the account of a per-user page in the tabs of its hub', function () {
    fakeArc();
    $tabs = app(Navigation::class)->tabs(principal('arcadmin', Role::Admin), 'node.show', ['user' => 'bob']);

    expect(collect($tabs['items'])->pluck('url', 'key')->all())->toBe([
        'node-apps' => route('node.apps.index', ['user' => 'bob']),
        'node.index' => route('node.show', ['user' => 'bob']),
    ])->and(collect($tabs['items'])->pluck('current')->all())->toBe([false, true]);
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

it('puts every table in a horizontal scroll box, so narrow screens do not scroll the page', function () {
    $bare = [];
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));
    foreach ($views as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            if (! preg_match('/<table class="ap-table[ "]/', $line)) {
                continue;
            }
            $previous = '';
            for ($j = $i - 1; $j >= 0 && $previous === ''; $j--) {
                $previous = trim($lines[$j]);
            }
            if (! str_contains($previous, 'class="ap-table-wrap')) {
                $bare[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
            }
        }
    }
    sort($bare);

    expect($bare)->toBe([]);
});

it('keeps epic codes such as E12 out of translated UI strings', function () {
    $found = [];
    foreach ([app_path(), resource_path('views'), resource_path('js')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.(php|vue|js)$/', $file->getFilename())) {
                continue;
            }
            preg_match_all('/\b(?:__|t)\(\s*(\'[^\']*\'|"[^"]*")/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $string) {
                if (preg_match('/\bE\d{1,2}\b/', $string)) {
                    $found[] = $string;
                }
            }
        }
    }

    expect($found)->toBe([]);
});
