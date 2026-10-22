<?php

use App\Http\Controllers\AppProcesses\AppProcessController;
use App\Http\Controllers\AppProcesses\AppProcessPageController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MailPasswordController;
use App\Http\Controllers\Auth\MailSsoController;
use App\Http\Controllers\Auth\ResetController;
use App\Http\Controllers\Auth\SudoController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Backups\BackupController;
use App\Http\Controllers\Backups\ExclusionController;
use App\Http\Controllers\Backups\SnapshotController;
use App\Http\Controllers\Cron\CronActionController;
use App\Http\Controllers\Cron\CronController;
use App\Http\Controllers\CsrController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Databases\DatabaseActionController;
use App\Http\Controllers\Databases\DatabaseController;
use App\Http\Controllers\Dns\DnsActionController;
use App\Http\Controllers\Dns\DnsController;
use App\Http\Controllers\Dns\DnsRecordController;
use App\Http\Controllers\Docker\DockerAppController;
use App\Http\Controllers\Docker\DockerAppPageController;
use App\Http\Controllers\Docker\DockerEngineController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Files\FileFetchController;
use App\Http\Controllers\Files\FilePageController;
use App\Http\Controllers\Files\FileTransferController;
use App\Http\Controllers\Files\SnapshotFileController;
use App\Http\Controllers\Firewall\FirewallActionController;
use App\Http\Controllers\Firewall\FirewallBanController;
use App\Http\Controllers\Firewall\FirewallController;
use App\Http\Controllers\Firewall\FirewallIpsetController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Ips\IpController;
use App\Http\Controllers\Keys\AccessKeyController;
use App\Http\Controllers\Keys\SecretController;
use App\Http\Controllers\Keys\SshKeyController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\Logs\LogController;
use App\Http\Controllers\Mail\MailAccountController;
use App\Http\Controllers\Mail\MailActionController;
use App\Http\Controllers\Mail\MailDomainController;
use App\Http\Controllers\Mail\MailDomainEditController;
use App\Http\Controllers\Malware\MalwareController;
use App\Http\Controllers\Node\NodeAppController;
use App\Http\Controllers\Node\NodeAppPageController;
use App\Http\Controllers\Node\NodeController;
use App\Http\Controllers\ObjectStorage\ObjectStoragePageController;
use App\Http\Controllers\ObjectStorage\ObjectStorageServerController;
use App\Http\Controllers\Packages\PackageController;
use App\Http\Controllers\Ports\PortController;
use App\Http\Controllers\Ports\ServerPortController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Server\PhpController;
use App\Http\Controllers\Server\PhpProfileController;
use App\Http\Controllers\Server\RrdPageController;
use App\Http\Controllers\Server\ServerSettingsController;
use App\Http\Controllers\Server\ServiceConfigController;
use App\Http\Controllers\Server\ServiceController;
use App\Http\Controllers\Server\SystemPackageController;
use App\Http\Controllers\Server\SystemTaskController;
use App\Http\Controllers\Server\UpdateController;
use App\Http\Controllers\Shell\LanguageController;
use App\Http\Controllers\Shell\NotificationController;
use App\Http\Controllers\Shell\PaletteController;
use App\Http\Controllers\Shell\RrdController;
use App\Http\Controllers\Shell\StyleguideController;
use App\Http\Controllers\Stats\StatsController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\Users\TwoFactorSetupController;
use App\Http\Controllers\Users\UserActionController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserEditController;
use App\Http\Controllers\WebDomains\QuickInstallController;
use App\Http\Controllers\WebDomains\WebDomainActionController;
use App\Http\Controllers\WebDomains\WebDomainController;
use App\Http\Controllers\WebDomains\WebDomainEditController;
use App\Http\Controllers\WebDomains\WebDomainLogController;
use App\Http\Controllers\WebDomains\WebDomainPhpController;
use App\Services\AppProcesses\AppProcessService;
use App\Services\Docker\DockerAppService;
use App\Services\Node\NodeAppService;
use App\Services\Server\ServerSettings;
use App\Services\Server\UpdateCenter;
use Illuminate\Support\Facades\Route;

// Served at the root URL since 2.0 (ADR-0002, E8 spec §5). Link with route
// names only.

// User names as func/main.sh accepts them; domains (IDN too) are checked by
// the commands, the route only keeps out slashes and spaces.
Route::pattern('user', '[[:alnum:]][-.\w]{0,28}[[:alnum:]]');
Route::pattern('domain', '[^/\s]{1,253}');
Route::pattern('package', '[[:alnum:]][-.\w]{0,99}');
Route::pattern('range', '\d{1,5}(-\d{1,5})?');
Route::pattern('name', '[a-z][a-z0-9-]{0,30}[a-z0-9]');
Route::pattern('slug', '[a-z][a-z0-9-]{1,31}');
Route::pattern('version', '\d{1,3}\.\d{1,3}\.\d{1,3}');
// Database names with the owner prefix (is_database_format_valid)
Route::pattern('database', '[\w-]{1,64}');
// Cron job ids (is_int_format_valid)
Route::pattern('job', '\d{1,9}');
// DNS record ids (is_int_format_valid)
Route::pattern('record', '\d{1,9}');
// Mail account names (is_localpart_format_valid)
Route::pattern('account', '[\w.-]{1,64}');
// Backup archives (USER.DATE_TIME.tar…) and restic snapshot ids
Route::pattern('backup', '[A-Za-z0-9][A-Za-z0-9_.-]{0,250}');
Route::pattern('snapshot', '[0-9a-f]{8,64}');

Route::middleware('guest.arc')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/login/2fa', [TwoFactorController::class, 'show'])->name('login.2fa');
    Route::post('/login/2fa', [TwoFactorController::class, 'store'])->name('login.2fa.store');

    // Password reset (E7 batch 3 spec §11); codes never leave in a Referer
    // and never stay in a URL (E39 W2 spec D9)
    Route::middleware('no-referrer')->group(function () {
        Route::get('/reset', [ResetController::class, 'show'])->name('reset');
        Route::post('/reset', [ResetController::class, 'request'])->name('reset.request');
        Route::get('/reset/code', [ResetController::class, 'code'])->name('reset.code');
        Route::post('/reset/code', [ResetController::class, 'enterCode'])->name('reset.code.store');
        Route::get('/reset/confirm', [ResetController::class, 'confirm'])->name('reset.confirm');
        Route::post('/reset/confirm', [ResetController::class, 'update'])->name('reset.update');
    });
});
// The 2FA reset by secret is gone: recovery codes took its place, and an
// administrator turns 2FA off for a user who has none (E39 W5 N20)
Route::redirect('/reset2fa', '/login');

// Mail password change for the webmail clients of this server (1.x
// web/reset/mail); no session, the controller checks the caller's address
Route::post('/reset/mail', MailPasswordController::class)->name('reset.mail');
// Webmail sign-on tickets from Roundcube's arcpanel_sso plugin (E46 spec §7.5);
// loopback only, the controller checks the caller's address
Route::post('/sso/mail', MailSsoController::class)->name('sso.mail');

Route::middleware('auth.arc')->group(function () {
    // Sudo mode: the password before a sensitive change of one's own account (E39 W2 spec §7)
    Route::get('/sudo', [SudoController::class, 'show'])->name('sudo.show');
    Route::post('/sudo', [SudoController::class, 'store'])->name('sudo.store');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
    Route::post('/impersonate', [ImpersonationController::class, 'fromForm'])->name('impersonate.form');
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate.start');

    // Shell (docs/design/05 §4.2)
    Route::get('/_palette', PaletteController::class)->name('shell.palette');
    // Search page (E7 batch 3 spec §12.1)
    Route::get('/search', SearchController::class)->name('search');
    Route::get('/_notifications', [NotificationController::class, 'index'])->name('shell.notifications');
    // Acknowledging and deleting are changes (E34 batch 6 D64)
    Route::middleware('not-readonly')->group(function () {
        Route::post('/_notifications/{id}/acknowledge', [NotificationController::class, 'acknowledge'])->whereNumber('id')->name('shell.notifications.acknowledge');
        Route::delete('/_notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id')->name('shell.notifications.destroy');
        Route::post('/_notifications/acknowledge', [NotificationController::class, 'acknowledgeAll'])->name('shell.notifications.acknowledge-all');
        Route::delete('/_notifications', [NotificationController::class, 'destroyAll'])->name('shell.notifications.destroy-all');
    });
    Route::post('/_language', LanguageController::class)->name('shell.language');
    Route::get('/_rrd', RrdController::class)->name('shell.rrd');
    Route::get('/_styleguide', StyleguideController::class)->name('styleguide');

    // Users and customers (E11 spec §7)
    Route::get('/account', [UserEditController::class, 'account'])->name('account.edit');
    // Two-factor authentication of the login user (E39 W5 spec §11.3)
    Route::get('/account/two-factor', [TwoFactorSetupController::class, 'show'])->name('account.two-factor');
    Route::middleware('not-readonly')->group(function () {
        Route::post('/account/two-factor', [TwoFactorSetupController::class, 'start'])->name('account.two-factor.start');
        Route::post('/account/two-factor/confirm', [TwoFactorSetupController::class, 'confirm'])->name('account.two-factor.confirm');
        Route::post('/account/two-factor/recovery-codes', [TwoFactorSetupController::class, 'recoveryCodes'])->name('account.two-factor.recovery');
    });
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::get('/quota', [UserController::class, 'quota'])->name('quota');
        Route::post('/', [UserController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [UserActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware('effective-user')->prefix('{user}')->group(function () {
            Route::get('/', [UserController::class, 'show'])->name('show');
            Route::get('/edit', [UserEditController::class, 'edit'])->name('edit');

            Route::middleware('not-readonly')->group(function () {
                foreach (['general', 'package', 'nameservers', 'security', 'administration'] as $tab) {
                    Route::post('/'.$tab, [UserEditController::class, $tab])->name('update.'.$tab);
                }
                Route::post('/suspend', [UserActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [UserActionController::class, 'unsuspend'])->name('unsuspend');
                Route::delete('/', [UserActionController::class, 'destroy'])->name('destroy');
            });
        });
    });

    // Packages (E11 spec §8)
    Route::prefix('packages')->name('packages.')->group(function () {
        Route::get('/', [PackageController::class, 'index'])->name('index');
        Route::get('/create', [PackageController::class, 'create'])->name('create');
        Route::get('/{package}/edit', [PackageController::class, 'edit'])->name('edit');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/', [PackageController::class, 'store'])->name('store');
            Route::post('/{package}', [PackageController::class, 'update'])->name('update');
            Route::post('/{package}/copy', [PackageController::class, 'copy'])->name('copy');
            Route::delete('/{package}', [PackageController::class, 'destroy'])->name('destroy');
        });
    });

    // Firewall (E7 batch 2 spec §6)
    Route::prefix('server/firewall')->middleware('sys-config:FIREWALL_SYSTEM')->group(function () {
        Route::get('/', [FirewallController::class, 'index'])->name('server.firewall');
        Route::name('server.firewall.')->group(function () {
            Route::get('/create', [FirewallController::class, 'create'])->name('create');
            Route::get('/{rule}/edit', [FirewallController::class, 'edit'])->whereNumber('rule')->name('edit');
            Route::get('/ipsets', [FirewallIpsetController::class, 'index'])->name('ipsets');
            Route::get('/ipsets/create', [FirewallIpsetController::class, 'create'])->name('ipsets.create');
            Route::middleware('sys-config:FIREWALL_EXTENSION')->group(function () {
                Route::get('/bans', [FirewallBanController::class, 'index'])->name('bans');
                Route::get('/bans/create', [FirewallBanController::class, 'create'])->name('bans.create');
            });

            Route::middleware('not-readonly')->group(function () {
                Route::post('/', [FirewallController::class, 'store'])->name('store');
                Route::post('/bulk', [FirewallActionController::class, 'bulk'])->name('bulk');
                Route::post('/reload', [FirewallController::class, 'reload'])->name('reload');
                Route::prefix('{rule}')->whereNumber('rule')->group(function () {
                    Route::post('/edit', [FirewallController::class, 'update'])->name('update');
                    Route::post('/move/{direction}', [FirewallActionController::class, 'move'])->whereIn('direction', ['up', 'down'])->name('move');
                    Route::post('/suspend', [FirewallActionController::class, 'suspend'])->name('suspend');
                    Route::post('/unsuspend', [FirewallActionController::class, 'unsuspend'])->name('unsuspend');
                    Route::delete('/', [FirewallActionController::class, 'destroy'])->name('destroy');
                });
                Route::post('/ipsets', [FirewallIpsetController::class, 'store'])->name('ipsets.store');
                Route::post('/ipsets/bulk', [FirewallIpsetController::class, 'bulk'])->name('ipsets.bulk');
                Route::delete('/ipsets/{name}', [FirewallIpsetController::class, 'destroy'])->where('name', '[\w.-]{1,255}')->name('ipsets.destroy');
                Route::middleware('sys-config:FIREWALL_EXTENSION')->group(function () {
                    Route::post('/bans', [FirewallBanController::class, 'store'])->name('bans.store');
                    Route::post('/bans/bulk', [FirewallBanController::class, 'bulk'])->name('bans.bulk');
                    Route::delete('/bans', [FirewallBanController::class, 'destroy'])->name('bans.destroy');
                });
            });
        });
    });

    // IP addresses (E7 batch 2 spec §7)
    Route::prefix('server/ips')->group(function () {
        Route::get('/', [IpController::class, 'index'])->name('server.ips');
        Route::name('server.ips.')->group(function () {
            Route::get('/create', [IpController::class, 'create'])->name('create');
            Route::get('/{ip}/edit', [IpController::class, 'edit'])->where('ip', '[0-9A-Fa-f:.]{2,45}')->name('edit');
            Route::middleware('not-readonly')->group(function () {
                Route::post('/', [IpController::class, 'store'])->name('store');
                Route::post('/bulk', [IpController::class, 'bulk'])->name('bulk');
                Route::post('/refresh', [IpController::class, 'refresh'])->name('refresh');
                Route::post('/{ip}/edit', [IpController::class, 'update'])->where('ip', '[0-9A-Fa-f:.]{2,45}')->name('update');
                Route::delete('/{ip}', [IpController::class, 'destroy'])->where('ip', '[0-9A-Fa-f:.]{2,45}')->name('destroy');
            });
        });
    });

    // Access keys and SSH keys (E7 batch 2 spec §8)
    Route::get('/access-keys', [AccessKeyController::class, 'entry'])->name('access-keys.index');
    Route::prefix('access-keys/{user}')->name('access-keys.')->middleware('effective-user')->group(function () {
        Route::get('/', [AccessKeyController::class, 'index'])->name('list');
        Route::get('/create', [AccessKeyController::class, 'create'])->name('create');
        Route::get('/{key}', [AccessKeyController::class, 'show'])->where('key', '[A-Za-z0-9]{20}')->name('show');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/', [AccessKeyController::class, 'store'])->name('store');
            Route::post('/bulk', [AccessKeyController::class, 'bulk'])->name('bulk');
            Route::delete('/{key}', [AccessKeyController::class, 'destroy'])->where('key', '[A-Za-z0-9]{20}')->name('destroy');
        });
    });
    Route::get('/ssh-keys', [SshKeyController::class, 'entry'])->name('ssh-keys.index');
    Route::prefix('ssh-keys/{user}')->name('ssh-keys.')->middleware('effective-user')->group(function () {
        Route::get('/', [SshKeyController::class, 'index'])->name('list');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/', [SshKeyController::class, 'store'])->name('store');
            Route::delete('/{id}', [SshKeyController::class, 'destroy'])->where('id', '[^/]{1,255}')->name('destroy');
        });
    });

    // Secret store (E38 spec §7); the secret name travels as `name`
    Route::get('/secrets', [SecretController::class, 'entry'])->name('secrets.index');
    Route::prefix('secrets/{user}')->name('secrets.')->middleware('effective-user')->group(function () {
        Route::get('/', [SecretController::class, 'index'])->name('list');
        Route::get('/item', [SecretController::class, 'show'])->name('show');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/', [SecretController::class, 'store'])->name('store');
            Route::post('/item/value', [SecretController::class, 'value'])->name('value');
            Route::post('/item/rollback', [SecretController::class, 'rollback'])->name('rollback');
            Route::patch('/item', [SecretController::class, 'describe'])->name('describe');
            Route::delete('/item', [SecretController::class, 'destroy'])->name('destroy');
        });
    });

    // Logs (E7 batch 2 spec §9.1)
    Route::get('/logs', [LogController::class, 'entry'])->name('logs.index');
    Route::prefix('logs/{user}')->name('logs.')->middleware('effective-user')->group(function () {
        Route::get('/', [LogController::class, 'show'])->name('show');
        Route::get('/auth', [LogController::class, 'logins'])->name('auth');
        Route::middleware('not-readonly')->group(function () {
            Route::delete('/', [LogController::class, 'destroy'])->name('destroy');
            Route::delete('/auth', [LogController::class, 'destroyLogins'])->name('auth.destroy');
        });
    });

    // Statistics and server graphs (E7 batch 2 spec §9.4, §9.5)
    Route::get('/stats', StatsController::class)->name('stats.index');
    Route::get('/server/rrd', RrdPageController::class)->name('server.rrd');

    // System task of the server settings and updates (E7 batch 3 spec §6.2)
    Route::get('/server/task', SystemTaskController::class)->name('server.task');

    // Server settings (E7 batch 3 spec §8)
    Route::prefix('server/settings')->group(function () {
        Route::get('/', [ServerSettingsController::class, 'index'])->name('server.settings');
        Route::get('/{tab}', [ServerSettingsController::class, 'show'])
            ->whereIn('tab', ServerSettings::TABS)->name('server.settings.tab');
        Route::middleware('not-readonly')->name('server.settings.')->group(function () {
            foreach (['general', 'web', 'mail', 'databases', 'backups', 'ssl', 'security', 'plugins'] as $tab) {
                Route::post('/'.$tab, [ServerSettingsController::class, $tab])->name('update.'.$tab);
            }
            Route::post('/white-label', [ServerSettingsController::class, 'whiteLabel'])->name('update.white-label');
            Route::post('/web/php', [ServerSettingsController::class, 'php'])->name('php');
            Route::post('/databases/hosts/{type}/{host}/password', [ServerSettingsController::class, 'databaseHostPassword'])
                ->where(['type' => 'mysql|pgsql', 'host' => '[A-Za-z0-9][A-Za-z0-9.:_-]{0,252}'])->name('dbhost');
            Route::post('/plugins/{plugin}', [ServerSettingsController::class, 'plugin'])
                ->whereIn('plugin', array_keys(ServerSettings::PLUGINS))->name('plugin');
            Route::post('/white-label/logo', [ServerSettingsController::class, 'logo'])->name('logo');
            Route::post('/ssl/csr', [CsrController::class, 'server'])->name('csr');
        });
    });

    // Updates: the Update Center (E33 phase 1 spec §8)
    Route::get('/server/updates', [UpdateController::class, 'index'])->name('server.updates');
    Route::get('/server/updates/releases/{version}/preflight', [UpdateController::class, 'preflight'])
        ->where('version', UpdateCenter::VERSION_PATTERN)->name('server.updates.preflight');
    Route::prefix('server/updates')->name('server.updates.')->middleware('not-readonly')->group(function () {
        Route::post('/check', [UpdateController::class, 'check'])->name('check');
        Route::post('/release', [UpdateController::class, 'release'])->name('release');
        Route::post('/update', [UpdateController::class, 'update'])->name('update');
        Route::post('/policy', [UpdateController::class, 'policy'])->name('policy');
        Route::post('/resume', [UpdateController::class, 'resume'])->name('resume');
        Route::post('/history/{id}/rollback', [UpdateController::class, 'rollback'])->whereNumber('id')->name('rollback');
        Route::post('/notifications', [UpdateController::class, 'notifications'])->name('notifications');
    });

    // System packages of the catalog (E5 spec 2026-10-06-006 §7)
    Route::get('/server/packages', [SystemPackageController::class, 'index'])->name('server.packages');
    Route::post('/server/packages/{slug}/{action}', [SystemPackageController::class, 'start'])->middleware('not-readonly')
        ->where(['slug' => '[a-z][a-z0-9-]{1,31}', 'action' => 'add|delete'])->name('server.packages.start');

    // PHP Manager (E41 spec 2026-10-12-001 §8): versions, extensions, php.ini and profiles
    Route::prefix('server/php')->name('server.php.')->group(function () {
        $version = ['version' => '\d{1,2}\.\d{1,2}'];
        $profile = ['profile' => '[a-z0-9][a-z0-9-]{0,31}'];
        Route::get('/', [PhpController::class, 'index'])->name('index');
        Route::get('/profiles', [PhpProfileController::class, 'index'])->name('profiles.index');
        Route::get('/profiles/create', [PhpProfileController::class, 'create'])->name('profiles.create');
        Route::get('/profiles/{profile}/edit', [PhpProfileController::class, 'edit'])->where($profile)->name('profiles.edit');
        Route::get('/{version}/{tab?}', [PhpController::class, 'show'])->where($version + ['tab' => 'overview|extensions|ini-fpm|ini-cli'])->name('show');
        Route::middleware('not-readonly')->group(function () use ($version, $profile) {
            Route::post('/versions', [PhpController::class, 'versions'])->name('versions');
            Route::post('/default', [PhpController::class, 'default'])->name('default');
            Route::post('/profiles', [PhpProfileController::class, 'store'])->name('profiles.store');
            Route::put('/profiles/{profile}', [PhpProfileController::class, 'update'])->where($profile)->name('profiles.update');
            Route::delete('/profiles/{profile}', [PhpProfileController::class, 'destroy'])->where($profile)->name('profiles.destroy');
            Route::post('/{version}/reload', [PhpController::class, 'reload'])->where($version)->name('reload');
            Route::post('/{version}/extensions', [PhpController::class, 'extensions'])->where($version)->name('extensions');
            Route::post('/{version}/extensions/{name}', [PhpController::class, 'toggle'])->where($version + ['name' => '[a-z0-9_]{1,32}'])->name('extensions.toggle');
            Route::post('/{version}/ini/{sapi}', [PhpController::class, 'iniValues'])->where($version + ['sapi' => 'fpm|cli'])->name('ini.values');
            Route::post('/{version}/ini/{sapi}/check', [PhpController::class, 'iniCheck'])->where($version + ['sapi' => 'fpm|cli'])->name('ini.check');
            Route::post('/{version}/ini/{sapi}/file', [PhpController::class, 'iniFile'])->where($version + ['sapi' => 'fpm|cli'])->name('ini.file');
            Route::post('/{version}/ini/{sapi}/backups/{backup}', [PhpController::class, 'iniRestore'])
                ->where($version + ['sapi' => 'fpm|cli', 'backup' => '\d{8}-\d{6}(-\d+)?'])->name('ini.restore');
        });
    });

    // Services (E7 batch 3 spec §7)
    Route::prefix('server/services')->group(function () {
        Route::get('/', [ServiceController::class, 'index'])->name('server.services');
        Route::get('/status/{kind}', [ServiceController::class, 'status'])->where('kind', '[a-z]{2,4}')->name('server.services.status');
        // Config editors (spec §9)
        Route::get('/{service}/config', [ServiceConfigController::class, 'show'])->where('service', '[a-z0-9-]{2,32}')->name('server.services.config');
        Route::middleware('not-readonly')->group(function () {
            Route::post('/bulk', [ServiceController::class, 'bulk'])->name('server.services.bulk');
            Route::post('/restart-server', [ServiceController::class, 'restartServer'])->name('server.services.restart-server');
            Route::post('/{service}/config', [ServiceConfigController::class, 'save'])->where('service', '[a-z0-9-]{2,32}')->name('server.services.config.save');
            Route::post('/{service}/{action}', [ServiceController::class, 'action'])
                ->where(['service' => '[A-Za-z0-9][\w.@-]{0,63}', 'action' => 'start|stop|restart'])->name('server.services.action');
        });
    });

    // Web terminal (E7 batch 2 spec §10.4)
    Route::get('/terminal', TerminalController::class)->middleware('sys-config:WEB_TERMINAL=true')->name('terminal.index');
    Route::post('/terminal/ticket', [TerminalController::class, 'ticket'])->name('terminal.ticket');

    // Port registry (E12 spec §7)
    Route::get('/server/ports', [ServerPortController::class, 'index'])->name('server.ports');
    Route::prefix('server/ports')->name('server.ports.')->middleware('not-readonly')->group(function () {
        Route::post('/allocate', [ServerPortController::class, 'allocate'])->name('allocate');
        Route::post('/range', [ServerPortController::class, 'range'])->name('range');
        Route::post('/reserved', [ServerPortController::class, 'storeReserved'])->name('reserved.store');
        Route::delete('/reserved/{range}', [ServerPortController::class, 'destroyReserved'])->name('reserved.destroy');
        Route::post('/repair', [ServerPortController::class, 'repair'])->name('repair');
        Route::post('/register', [ServerPortController::class, 'register'])->name('register');
        Route::post('/adopt', [ServerPortController::class, 'adopt'])->name('adopt');
        Route::post('/close-rule', [ServerPortController::class, 'closeRule'])->name('close-rule');
    });
    Route::prefix('ports')->name('ports.')->group(function () {
        Route::get('/', [PortController::class, 'index'])->name('index');
        Route::post('/request', [PortController::class, 'request'])->middleware('not-readonly')->name('request');
        Route::middleware(['effective-user', 'not-readonly'])->prefix('{user}')->group(function () {
            Route::post('/reserve', [PortController::class, 'reserve'])->name('reserve');
            Route::post('/give', [PortController::class, 'give'])->name('give');
            Route::post('/{range}/release', [PortController::class, 'release'])->name('release');
            Route::post('/{range}/public', [PortController::class, 'public'])->name('public');
            Route::post('/{range}/public-ok', [PortController::class, 'publicOk'])->name('public-ok');
            Route::post('/{range}/reclaim', [PortController::class, 'reclaim'])->name('reclaim');
            Route::delete('/{range}', [PortController::class, 'destroy'])->name('destroy');
        });
    });

    // App processes of the effective account (E42 spec §9)
    Route::get('/app-processes', [AppProcessPageController::class, 'entry'])->name('app-processes');
    // App processes of an account (E42 spec §8): the island's JSON routes,
    // then the pages (a web domain name always has a dot, so never "api")
    Route::prefix('app-processes/{user}')->middleware('effective-user')->name('app-processes.')->group(function () {
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/', [AppProcessController::class, 'index'])->name('index');
            Route::get('/options', [AppProcessController::class, 'options'])->name('options');
            Route::get('/task', [AppProcessController::class, 'task'])->name('task');
            Route::get('/{domain}/{name}', [AppProcessController::class, 'show'])->name('show');
            Route::get('/{domain}/{name}/env', [AppProcessController::class, 'env'])->name('env');
            Route::get('/{domain}/{name}/logs', [AppProcessController::class, 'logs'])->name('logs');
            Route::middleware('not-readonly')->group(function () {
                Route::post('/', [AppProcessController::class, 'store'])->name('store');
                Route::delete('/{domain}/{name}', [AppProcessController::class, 'destroy'])->name('destroy');
                Route::post('/{domain}/{name}/tasks', [AppProcessController::class, 'startTask'])->name('tasks.store');
                Route::post('/{domain}/{name}/{action}', [AppProcessController::class, 'action'])
                    ->whereIn('action', AppProcessService::ACTIONS)->name('action');
                Route::put('/{domain}/{name}/start', [AppProcessController::class, 'changeStart'])->name('start');
                Route::put('/{domain}/{name}/limits', [AppProcessController::class, 'changeLimits'])->name('limits');
                Route::put('/{domain}/{name}/env', [AppProcessController::class, 'saveEnv'])->name('env.update');
            });
        });
        Route::get('/', [AppProcessPageController::class, 'index'])->name('index');
        Route::get('/create', [AppProcessPageController::class, 'create'])->name('create');
        Route::get('/{domain}/{name}', [AppProcessPageController::class, 'show'])->where('domain', '[^/]*\.[^/]*')->name('show');
    });

    // Node.js runtimes and global packages (E13 spec §6.3)
    // Node.js apps of the effective account (E14); not under /node/, where a
    // user named "apps" has its Node.js page
    Route::get('/node-apps', [NodeAppPageController::class, 'entry'])->name('node-apps');
    Route::prefix('node')->name('node.')->group(function () {
        Route::get('/', [NodeController::class, 'index'])->name('index');
        Route::middleware('effective-user')->prefix('{user}')->group(function () {
            Route::get('/', [NodeController::class, 'show'])->name('show');
            // Node.js apps pages (E14 spec §7)
            Route::get('/apps', [NodeAppPageController::class, 'index'])->name('apps.index');
            Route::get('/apps/create', [NodeAppPageController::class, 'create'])->name('apps.create');
            Route::get('/apps/{domain}', [NodeAppPageController::class, 'show'])->name('apps.show');
            Route::prefix('api')->name('api.')->group(function () {
                Route::get('/overview', [NodeController::class, 'overview'])->name('overview');
                Route::get('/releases', [NodeController::class, 'releases'])->name('releases');
                Route::get('/task', [NodeController::class, 'task'])->name('task');
                Route::get('/versions/{version}/packages', [NodeController::class, 'packages'])->name('packages');
                Route::middleware('not-readonly')->group(function () {
                    Route::post('/enable', [NodeController::class, 'enable'])->name('enable');
                    Route::delete('/enable', [NodeController::class, 'disable'])->name('disable');
                    Route::post('/versions', [NodeController::class, 'install'])->name('install');
                    Route::post('/versions/{version}/default', [NodeController::class, 'setDefault'])->name('default');
                    Route::delete('/versions/{version}', [NodeController::class, 'destroyVersion'])->name('versions.destroy');
                    Route::post('/versions/{version}/packages', [NodeController::class, 'addPackage'])->name('packages.store');
                    Route::post('/versions/{version}/packages/update', [NodeController::class, 'updatePackages'])->name('packages.update');
                    Route::post('/versions/{version}/packages/outdated', [NodeController::class, 'checkOutdated'])->name('packages.outdated');
                    Route::delete('/versions/{version}/packages/{name}', [NodeController::class, 'destroyPackage'])
                        ->where('name', '(@[a-z0-9][a-z0-9._~-]*/)?[a-z0-9][a-z0-9._~-]*')->name('packages.destroy');
                });

                // Node.js apps (E14 spec §6.3)
                Route::prefix('apps')->name('apps.')->group(function () {
                    Route::get('/', [NodeAppController::class, 'index'])->name('index');
                    Route::get('/options', [NodeAppController::class, 'options'])->name('options');
                    Route::get('/detect/{domain}', [NodeAppController::class, 'detect'])->name('detect');
                    Route::get('/{domain}', [NodeAppController::class, 'show'])->name('show');
                    Route::get('/{domain}/env', [NodeAppController::class, 'env'])->name('env');
                    Route::get('/{domain}/logs', [NodeAppController::class, 'logs'])->name('logs');
                    Route::middleware('not-readonly')->group(function () {
                        Route::post('/', [NodeAppController::class, 'store'])->name('store');
                        Route::delete('/{domain}', [NodeAppController::class, 'destroy'])->name('destroy');
                        Route::post('/{domain}/install', [NodeAppController::class, 'install'])->name('install');
                        Route::post('/{domain}/build', [NodeAppController::class, 'build'])->name('build');
                        Route::post('/{domain}/{action}', [NodeAppController::class, 'action'])
                            ->whereIn('action', NodeAppService::ACTIONS)->name('action');
                        Route::put('/{domain}/start', [NodeAppController::class, 'changeStart'])->name('start');
                        Route::put('/{domain}/version', [NodeAppController::class, 'changeVersion'])->name('version');
                        Route::put('/{domain}/scale', [NodeAppController::class, 'scale'])->name('scale');
                        Route::put('/{domain}/env', [NodeAppController::class, 'saveEnv'])->name('env.update');
                    });
                });
            });
        });
    });

    // Docker apps (E4 spec §6.3); the list of the effective account is at
    // /docker-apps, the engine of the server at /server/docker
    Route::get('/docker-apps', [DockerAppPageController::class, 'entry'])->name('docker-apps');
    Route::get('/server/docker', [DockerEngineController::class, 'page'])->name('server.docker');
    Route::prefix('server/docker/api')->name('server.docker.api.')->group(function () {
        Route::get('/status', [DockerEngineController::class, 'status'])->name('status');
        Route::get('/task', [DockerEngineController::class, 'task'])->name('task');
        Route::post('/install', [DockerEngineController::class, 'install'])->middleware('not-readonly')->name('install');
    });
    Route::prefix('docker/{user}')->name('docker.')->middleware('effective-user')->group(function () {
        Route::get('/apps', [DockerAppPageController::class, 'index'])->name('apps.index');
        Route::get('/apps/{name}', [DockerAppPageController::class, 'show'])->name('apps.show');
        Route::get('/store', [DockerAppPageController::class, 'store'])->name('store');
        Route::get('/store/{slug}', [DockerAppPageController::class, 'detail'])->name('store.show');
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/catalog', [DockerAppController::class, 'catalog'])->name('catalog');
            Route::get('/catalog/{slug}', [DockerAppController::class, 'catalogApp'])->name('catalog.show');
            Route::get('/options', [DockerAppController::class, 'options'])->name('options');
            Route::get('/apps', [DockerAppController::class, 'index'])->name('apps.index');
            Route::get('/apps/{name}', [DockerAppController::class, 'show'])->name('apps.show');
            Route::get('/apps/{name}/env', [DockerAppController::class, 'env'])->name('apps.env');
            Route::get('/apps/{name}/logs', [DockerAppController::class, 'logs'])->name('apps.logs');
            Route::get('/apps/{name}/task', [DockerAppController::class, 'task'])->name('apps.task');
            Route::middleware('not-readonly')->group(function () {
                Route::post('/apps', [DockerAppController::class, 'store'])->name('apps.store');
                Route::delete('/apps/{name}', [DockerAppController::class, 'destroy'])->name('apps.destroy');
                Route::post('/apps/{name}/tasks', [DockerAppController::class, 'startTask'])->name('apps.tasks');
                Route::post('/apps/{name}/{action}', [DockerAppController::class, 'action'])
                    ->whereIn('action', DockerAppService::ACTIONS)->name('apps.action');
                Route::put('/apps/{name}/env', [DockerAppController::class, 'saveEnv'])->name('apps.env.update');
                Route::put('/apps/{name}/limits', [DockerAppController::class, 'limits'])->name('apps.limits');
                Route::put('/apps/{name}/domain', [DockerAppController::class, 'domain'])->name('apps.domain');
            });
        });
    });

    // Object storage (E44 spec §11); the page of the effective account is at
    // /object-storage, the service of the server at /server/object-storage
    Route::get('/server/object-storage', [ObjectStorageServerController::class, 'page'])->name('server.object-storage');
    Route::middleware('not-readonly')->name('server.object-storage.')->group(function () {
        Route::post('/server/object-storage/install', [ObjectStorageServerController::class, 'install'])->name('install');
        Route::delete('/server/object-storage', [ObjectStorageServerController::class, 'remove'])->middleware('sys-config:OBJECT_STORAGE_SYSTEM=silo')->name('remove');
        Route::post('/server/object-storage/endpoint', [ObjectStorageServerController::class, 'endpoint'])->middleware('sys-config:OBJECT_STORAGE_SYSTEM=silo')->name('endpoint');
    });
    Route::middleware('sys-config:OBJECT_STORAGE_SYSTEM=silo')->group(function () {
        Route::get('/object-storage', [ObjectStoragePageController::class, 'entry'])->name('storage');
        Route::prefix('object-storage/{user}')->name('storage.')->middleware('effective-user')->group(function () {
            $bucket = ['bucket' => '[a-z0-9][a-z0-9-]{1,61}[a-z0-9]'];
            Route::get('/', [ObjectStoragePageController::class, 'show'])->name('show');
            Route::middleware('not-readonly')->group(function () use ($bucket) {
                Route::post('/buckets', [ObjectStoragePageController::class, 'storeBucket'])->name('buckets.store');
                Route::post('/buckets/{bucket}', [ObjectStoragePageController::class, 'updateBucket'])->where($bucket)->name('buckets.update');
                Route::delete('/buckets/{bucket}', [ObjectStoragePageController::class, 'destroyBucket'])->where($bucket)->name('buckets.destroy');
                Route::post('/keys', [ObjectStoragePageController::class, 'storeKey'])->name('keys.store');
                Route::delete('/keys/{key}', [ObjectStoragePageController::class, 'destroyKey'])->where('key', '[A-Za-z0-9]{3,64}')->name('keys.destroy');
            });
        });
    });

    // File manager (E3 spec §6.4)
    Route::prefix('files')->name('files.')->middleware('files.enabled')->group(function () {
        Route::get('/', [FilePageController::class, 'entry'])->name('index');
        Route::middleware('effective-user')->prefix('{user}')->group(function () {
            Route::get('/', [FilePageController::class, 'show'])->name('show');
            Route::get('/download/{ticket}', [FileTransferController::class, 'show'])->where('ticket', '[a-f0-9]{32}')->name('download');
            Route::prefix('api')->name('api.')->group(function () {
                Route::get('/list', [FileController::class, 'list'])->name('list');
                Route::get('/info', [FileController::class, 'info'])->name('info');
                Route::get('/read', [FileController::class, 'read'])->name('read');
                Route::get('/size', [FileController::class, 'size'])->name('size');
                Route::get('/search', [FileController::class, 'search'])->name('search');
                // A download changes nothing: read-only sessions may download
                Route::post('/download', [FileTransferController::class, 'download'])->name('download');
                // Snapshot mode (E7 batch 1 spec §10.3)
                Route::get('/snapshots/{snapshot}/list', [SnapshotFileController::class, 'list'])->name('snapshots.list');
                Route::post('/snapshots/{snapshot}/restore', [SnapshotFileController::class, 'restore'])->middleware('not-readonly')->name('snapshots.restore');
                Route::middleware('not-readonly')->group(function () {
                    Route::put('/save', [FileController::class, 'save'])->name('save');
                    foreach (['mkdir', 'touch', 'rename', 'copy', 'move', 'delete', 'chmod', 'compress', 'extract'] as $action) {
                        Route::post('/'.$action, [FileController::class, $action])->name($action);
                    }
                    Route::post('/upload', [FileTransferController::class, 'init'])->name('upload');
                    Route::put('/upload/{token}/{index}', [FileTransferController::class, 'chunk'])
                        ->where(['token' => '[a-f0-9]{32}', 'index' => '[0-9]{1,6}'])->middleware('throttle:files-upload')->name('upload.chunk');
                    Route::post('/upload/{token}/finish', [FileTransferController::class, 'finish'])->where('token', '[a-f0-9]{32}')->name('upload.finish');
                    Route::delete('/upload/{token}', [FileTransferController::class, 'cancel'])->where('token', '[a-f0-9]{32}')->name('upload.cancel');
                });
                // Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §6.1)
                Route::middleware('files.fetch')->group(function () {
                    Route::get('/fetches', [FileFetchController::class, 'index'])->name('fetches.index');
                    Route::middleware('not-readonly')->group(function () {
                        Route::post('/fetches', [FileFetchController::class, 'store'])->name('fetches.store');
                        Route::delete('/fetches/{id}', [FileFetchController::class, 'destroy'])->where('id', '[0-9]{10,14}')->name('fetches.destroy');
                    });
                });
            });
        });
    });

    // Backups (E7 batch 1 spec §10)
    Route::get('/backups', [BackupController::class, 'entry'])->name('backups.index');
    Route::prefix('backups/{user}')->name('backups.')->middleware('effective-user')->group(function () {
        Route::get('/', [BackupController::class, 'index'])->name('archives.index');
        Route::get('/archives/{backup}', [BackupController::class, 'show'])->name('archives.show');
        // A download changes nothing (web/download/backup)
        Route::get('/archives/{backup}/download', [BackupController::class, 'download'])->name('archives.download');
        Route::get('/snapshots', [SnapshotController::class, 'index'])->name('snapshots.index');
        Route::get('/snapshots/{snapshot}', [SnapshotController::class, 'show'])->name('snapshots.show');
        Route::get('/exclusions', [ExclusionController::class, 'edit'])->name('exclusions.edit');

        Route::middleware('not-readonly')->group(function () {
            Route::post('/', [BackupController::class, 'store'])->name('archives.store');
            Route::post('/bulk', [BackupController::class, 'bulk'])->name('archives.bulk');
            Route::post('/archives/{backup}/restore', [BackupController::class, 'restore'])->name('archives.restore');
            // Copies a remote archive to the server (E39 W5 L22)
            Route::post('/archives/{backup}/download', [BackupController::class, 'fetch'])->name('archives.fetch');
            Route::delete('/archives/{backup}', [BackupController::class, 'destroy'])->name('archives.destroy');
            Route::post('/snapshots', [SnapshotController::class, 'store'])->name('snapshots.store');
            Route::post('/snapshots/{snapshot}/restore', [SnapshotController::class, 'restore'])->name('snapshots.restore');
            Route::delete('/snapshots/{snapshot}', [SnapshotController::class, 'destroy'])->name('snapshots.destroy');
            Route::post('/exclusions', [ExclusionController::class, 'update'])->name('exclusions.update');
        });
    });

    // Malware scan (E25 batch 1 spec §8). While the plugin is off the two
    // pages say so (links of earlier notifications land there) and every
    // other route answers 404.
    Route::get('/malware', [MalwareController::class, 'entry'])->name('malware.index');
    Route::prefix('malware/{user}')->name('malware.')->middleware('effective-user')->group(function () {
        $finding = ['finding' => '[a-f0-9]{16}'];
        $quarantined = ['id' => '[0-9]{14}'];
        Route::get('/', [MalwareController::class, 'show'])->name('show');
        Route::middleware('sys-config:MALWARE_SCAN=yes')->group(function () use ($finding, $quarantined) {
            Route::get('/task', [MalwareController::class, 'task'])->name('task');
            Route::middleware('not-readonly')->group(function () use ($finding, $quarantined) {
                Route::post('/scan', [MalwareController::class, 'scan'])->name('scan');
                Route::post('/findings/{finding}/quarantine', [MalwareController::class, 'quarantine'])->where($finding)->name('quarantine');
                Route::post('/findings/{finding}/ignore', [MalwareController::class, 'ignore'])->where($finding)->name('ignore');
                Route::post('/quarantine/{id}/restore', [MalwareController::class, 'restore'])->where($quarantined)->name('restore');
                Route::delete('/quarantine/{id}', [MalwareController::class, 'destroyQuarantined'])->where($quarantined)->name('quarantine.destroy');
                Route::delete('/ignores/{sha256}', [MalwareController::class, 'destroyIgnore'])->where('sha256', '[a-f0-9]{64}')->name('ignores.destroy');
            });
        });
    });

    // Databases (E7 batch 1 spec §6)
    Route::prefix('db')->name('db.')->group(function () {
        Route::get('/', [DatabaseController::class, 'index'])->name('index');
        Route::get('/create', [DatabaseController::class, 'create'])->name('create');
        Route::post('/', [DatabaseController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [DatabaseActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware('effective-user')->prefix('{user}/{database}')->group(function () {
            Route::get('/edit', [DatabaseController::class, 'edit'])->name('edit');

            Route::middleware('not-readonly')->group(function () {
                Route::post('/edit', [DatabaseController::class, 'update'])->name('update');
                Route::post('/suspend', [DatabaseActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [DatabaseActionController::class, 'unsuspend'])->name('unsuspend');
                Route::post('/download', [DatabaseActionController::class, 'download'])->name('download');
                Route::post('/phpmyadmin', [DatabaseActionController::class, 'phpMyAdmin'])->middleware('no-referrer')->name('phpmyadmin');
                Route::delete('/', [DatabaseActionController::class, 'destroy'])->name('destroy');
            });
        });
    });

    // Cron jobs (E7 batch 1 spec §7)
    Route::prefix('cron')->name('cron.')->group(function () {
        Route::get('/', [CronController::class, 'index'])->name('index');
        Route::get('/create', [CronController::class, 'create'])->name('create');
        Route::post('/', [CronController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [CronActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware(['effective-user', 'not-readonly'])->prefix('{user}/reports')->name('reports.')->group(function () {
            Route::post('/', [CronActionController::class, 'enableReports'])->name('enable');
            Route::delete('/', [CronActionController::class, 'disableReports'])->name('disable');
        });

        Route::middleware('effective-user')->prefix('{user}/{job}')->group(function () {
            Route::get('/edit', [CronController::class, 'edit'])->name('edit');

            Route::middleware('not-readonly')->group(function () {
                Route::post('/edit', [CronController::class, 'update'])->name('update');
                Route::post('/suspend', [CronActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [CronActionController::class, 'unsuspend'])->name('unsuspend');
                Route::delete('/', [CronActionController::class, 'destroy'])->name('destroy');
            });
        });
    });

    // DNS zones and records (E7 batch 1 spec §8)
    Route::prefix('dns')->name('dns.')->group(function () {
        Route::get('/', [DnsController::class, 'index'])->name('index');
        Route::get('/create', [DnsController::class, 'create'])->name('create');
        Route::post('/', [DnsController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [DnsActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware('effective-user')->prefix('{user}/{domain}')->group(function () {
            Route::get('/edit', [DnsController::class, 'edit'])->name('edit');
            Route::get('/dnssec', [DnsController::class, 'dnssec'])->name('dnssec');
            Route::get('/records', [DnsRecordController::class, 'index'])->name('records.index');

            Route::middleware('not-readonly')->group(function () {
                Route::post('/edit', [DnsController::class, 'update'])->name('update');
                Route::post('/suspend', [DnsActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [DnsActionController::class, 'unsuspend'])->name('unsuspend');
                Route::delete('/', [DnsActionController::class, 'destroy'])->name('destroy');

                Route::prefix('records')->name('records.')->group(function () {
                    Route::post('/', [DnsRecordController::class, 'store'])->name('store');
                    Route::post('/bulk', [DnsActionController::class, 'bulkRecords'])->name('bulk');
                    Route::post('/{record}', [DnsRecordController::class, 'update'])->name('update');
                    Route::post('/{record}/suspend', [DnsActionController::class, 'suspendRecord'])->name('suspend');
                    Route::post('/{record}/unsuspend', [DnsActionController::class, 'unsuspendRecord'])->name('unsuspend');
                    Route::delete('/{record}', [DnsActionController::class, 'destroyRecord'])->name('destroy');
                });
            });
        });
    });

    // Mail domains and accounts (E7 batch 1 spec §9)
    Route::prefix('mail')->name('mail.')->group(function () {
        Route::get('/', [MailDomainController::class, 'index'])->name('index');
        Route::get('/create', [MailDomainController::class, 'create'])->name('create');
        Route::post('/', [MailDomainController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [MailActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware('effective-user')->prefix('{user}/{domain}')->group(function () {
            Route::get('/edit', [MailDomainController::class, 'edit'])->name('edit');
            Route::get('/dns', [MailDomainController::class, 'dns'])->name('dns');
            Route::get('/accounts', [MailAccountController::class, 'index'])->name('accounts.index');
            Route::get('/accounts/create', [MailAccountController::class, 'create'])->name('accounts.create');
            Route::get('/accounts/{account}/edit', [MailAccountController::class, 'edit'])->name('accounts.edit');

            Route::middleware('not-readonly')->group(function () {
                foreach (['general', 'ssl', 'relay'] as $tab) {
                    Route::post('/edit/'.$tab, [MailDomainEditController::class, $tab])->name('update.'.$tab);
                }
                Route::post('/suspend', [MailActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [MailActionController::class, 'unsuspend'])->name('unsuspend');
                Route::delete('/', [MailActionController::class, 'destroy'])->name('destroy');

                Route::prefix('accounts')->name('accounts.')->group(function () {
                    Route::post('/', [MailAccountController::class, 'store'])->name('store');
                    Route::post('/bulk', [MailActionController::class, 'bulkAccounts'])->name('bulk');
                    foreach (['general', 'forwarding', 'autoreply'] as $tab) {
                        Route::post('/{account}/edit/'.$tab, [MailAccountController::class, $tab])->name('update.'.$tab);
                    }
                    Route::post('/{account}/webmail', [MailAccountController::class, 'webmail'])->middleware('no-referrer')->name('webmail');
                    Route::post('/{account}/suspend', [MailActionController::class, 'suspendAccount'])->name('suspend');
                    Route::post('/{account}/unsuspend', [MailActionController::class, 'unsuspendAccount'])->name('unsuspend');
                    Route::delete('/{account}', [MailActionController::class, 'destroyAccount'])->name('destroy');
                });
            });
        });
    });

    // Web domains (E1-S2 spec §8)
    Route::prefix('web/domains')->name('web.domains.')->group(function () {
        Route::get('/', [WebDomainController::class, 'index'])->name('index');
        Route::get('/create', [WebDomainController::class, 'create'])->name('create');
        Route::post('/', [WebDomainController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [WebDomainActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware('effective-user')->prefix('{user}/{domain}')->group(function () {
            Route::get('/edit', [WebDomainEditController::class, 'edit'])->name('edit');
            Route::get('/logs/{type}', [WebDomainLogController::class, 'show'])->whereIn('type', ['access', 'error'])->name('logs');
            Route::get('/logs/{type}/download', [WebDomainLogController::class, 'download'])->whereIn('type', ['access', 'error'])->name('logs.download');
            // Quick Install (E7 batch 2 spec §10.2)
            Route::get('/install', [QuickInstallController::class, 'index'])->name('install');
            Route::get('/install/{app}', [QuickInstallController::class, 'form'])->where('app', '[A-Za-z][A-Za-z0-9]{0,63}')->name('install.form');
            Route::get('/install-task', [QuickInstallController::class, 'task'])->name('install.task');
            Route::post('/install/{app}', [QuickInstallController::class, 'start'])->where('app', '[A-Za-z][A-Za-z0-9]{0,63}')->middleware('not-readonly')->name('install.start');

            Route::middleware('not-readonly')->group(function () {
                foreach (['general', 'ssl', 'redirect', 'advanced', 'ftp', 'stats'] as $tab) {
                    Route::post('/'.$tab, [WebDomainEditController::class, $tab])->name('update.'.$tab);
                }
                // PHP version and PHP-FPM profile (E41 §8)
                Route::post('/php', [WebDomainPhpController::class, 'update'])->middleware('throttle:php-switch')->name('update.php');
                // CSR generator of the SSL tab (E7 batch 3 spec §12.2)
                Route::post('/ssl/generate', [CsrController::class, 'webDomain'])->name('ssl.generate');
                Route::post('/suspend', [WebDomainActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [WebDomainActionController::class, 'unsuspend'])->name('unsuspend');
                Route::post('/rebuild', [WebDomainActionController::class, 'rebuild'])->name('rebuild');
                Route::post('/purge-cache', [WebDomainActionController::class, 'purgeCache'])->name('purge-cache');
                Route::delete('/', [WebDomainActionController::class, 'destroy'])->name('destroy');
            });
        });
    });
});

// Old URLs (E8 spec §5.3, still in 2.1): the /app prefix of 1.x and the
// legacy page paths answer 301. Registered last, so no panel route is shadowed.
Route::get('/app/{path?}', [LegacyRedirectController::class, 'app'])->where('path', '.*')->name('legacy.app');
Route::get('/logout', [LegacyRedirectController::class, 'logout'])->name('legacy.logout');
Route::any('/{legacy}', [LegacyRedirectController::class, 'page'])
    ->where('legacy', '(list|add|edit|download|generate|fm)(/.*)?')
    ->name('legacy.page');
