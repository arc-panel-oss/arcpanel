<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Packages\PackageController;
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
use Illuminate\Support\Facades\Route;

// Served under /app/ (ADR-0002). Link with route names only, so the prefix
// can be dropped at 2.0 without touching views.

// User names as func/main.sh accepts them; domains (IDN too) are checked by
// the commands, the route only keeps out slashes and spaces.
Route::pattern('user', '[[:alnum:]][-.\w]{0,28}[[:alnum:]]');
Route::pattern('domain', '[^/\s]{1,253}');
Route::pattern('package', '[[:alnum:]][-.\w]{0,99}');

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
