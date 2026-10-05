<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Node\NodeAppController;
use App\Http\Controllers\Node\NodeController;
use App\Http\Controllers\Packages\PackageController;
use App\Http\Controllers\Ports\PortController;
use App\Http\Controllers\Ports\ServerPortController;
use App\Http\Controllers\Shell\LanguageController;
use App\Http\Controllers\Shell\NotificationController;
use App\Http\Controllers\Shell\PaletteController;
use App\Http\Controllers\Shell\RrdController;
use App\Http\Controllers\Shell\StyleguideController;
use App\Http\Controllers\Users\UserActionController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserEditController;
use App\Http\Controllers\WebDomains\WebDomainActionController;
use App\Http\Controllers\WebDomains\WebDomainController;
use App\Http\Controllers\WebDomains\WebDomainEditController;
use App\Http\Controllers\WebDomains\WebDomainLogController;
use App\Services\Node\NodeAppService;
use Illuminate\Support\Facades\Route;

// Served under /app/ (ADR-0002). Link with route names only, so the prefix
// can be dropped at 2.0 without touching views.

// User names as func/main.sh accepts them; domains (IDN too) are checked by
// the commands, the route only keeps out slashes and spaces.
Route::pattern('user', '[[:alnum:]][-.\w]{0,28}[[:alnum:]]');
Route::pattern('domain', '[^/\s]{1,253}');
Route::pattern('package', '[[:alnum:]][-.\w]{0,99}');
Route::pattern('range', '\d{1,5}(-\d{1,5})?');
Route::pattern('version', '\d{1,3}\.\d{1,3}\.\d{1,3}');

Route::middleware('guest.arc')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/login/2fa', [TwoFactorController::class, 'show'])->name('login.2fa');
    Route::post('/login/2fa', [TwoFactorController::class, 'store'])->name('login.2fa.store');
});

Route::middleware('auth.arc')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
    Route::post('/impersonate', [ImpersonationController::class, 'fromForm'])->name('impersonate.form');
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate.start');

    // Shell (docs/design/05 §4.2)
    Route::get('/_palette', PaletteController::class)->name('shell.palette');
    Route::get('/_notifications', [NotificationController::class, 'index'])->name('shell.notifications');
    Route::post('/_notifications/{id}/acknowledge', [NotificationController::class, 'acknowledge'])->whereNumber('id')->name('shell.notifications.acknowledge');
    Route::delete('/_notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id')->name('shell.notifications.destroy');
    Route::post('/_language', LanguageController::class)->name('shell.language');
    Route::get('/_rrd', RrdController::class)->name('shell.rrd');
    Route::get('/_styleguide', StyleguideController::class)->name('styleguide');

    // Users and customers (E11 spec §7)
    Route::get('/account', [UserEditController::class, 'account'])->name('account.edit');
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

    // Node.js runtimes and global packages (E13 spec §6.3)
    Route::prefix('node')->name('node.')->group(function () {
        Route::get('/', [NodeController::class, 'index'])->name('index');
        Route::middleware('effective-user')->prefix('{user}')->group(function () {
            Route::get('/', [NodeController::class, 'show'])->name('show');
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

    // Web domains (E1-S2 spec §8)
    Route::prefix('web/domains')->name('web.domains.')->group(function () {
        Route::get('/', [WebDomainController::class, 'index'])->name('index');
        Route::get('/create', [WebDomainController::class, 'create'])->name('create');
        Route::post('/', [WebDomainController::class, 'store'])->middleware('not-readonly')->name('store');
        Route::post('/bulk', [WebDomainActionController::class, 'bulk'])->middleware('not-readonly')->name('bulk');

        Route::middleware('effective-user')->prefix('{user}/{domain}')->group(function () {
            Route::get('/edit', [WebDomainEditController::class, 'edit'])->name('edit');
            Route::get('/logs/{type}', [WebDomainLogController::class, 'show'])->whereIn('type', ['access', 'error'])->name('logs');

            Route::middleware('not-readonly')->group(function () {
                foreach (['general', 'ssl', 'redirect', 'advanced', 'ftp', 'stats'] as $tab) {
                    Route::post('/'.$tab, [WebDomainEditController::class, $tab])->name('update.'.$tab);
                }
                Route::post('/suspend', [WebDomainActionController::class, 'suspend'])->name('suspend');
                Route::post('/unsuspend', [WebDomainActionController::class, 'unsuspend'])->name('unsuspend');
                Route::post('/rebuild', [WebDomainActionController::class, 'rebuild'])->name('rebuild');
                Route::post('/purge-cache', [WebDomainActionController::class, 'purgeCache'])->name('purge-cache');
                Route::delete('/', [WebDomainActionController::class, 'destroy'])->name('destroy');
            });
        });
    });
});
