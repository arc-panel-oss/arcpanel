<?php

use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\PackageController;
use App\Http\Controllers\Api\V2\UserController;
use App\Http\Controllers\Api\V2\WebDomainController;
use Illuminate\Support\Facades\Route;

/*
| REST API v2 (docs/features/specs/2026-10-07-002-e34-api-v2-design.md),
| loaded by bootstrap/app.php under /api/v2 with the names api.v2.*:
| stateless, authenticated by an access key, answers JSON only. Every
| operation has a stable operationId (the @operationId tag of its
| controller method), which the SDK turns into method names.
*/

// The parameters of routes/web.php, for the API routes too
Route::pattern('user', '[[:alnum:]][-.\w]{0,28}[[:alnum:]]');
Route::pattern('domain', '[^/\s]{1,253}');
Route::pattern('package', '[[:alnum:]][-.\w]{0,99}');
Route::pattern('alias', '[^/\s]{1,253}');

Route::get('/me', MeController::class)->name('me');

// Users (spec §6.1); {user} outside the principal's tree answers 404
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::post('/users', [UserController::class, 'store'])->middleware('not-readonly')->name('users.store');
Route::middleware('effective-user')->group(function () {
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::middleware('not-readonly')->group(function () {
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/package', [UserController::class, 'package'])->name('users.package');
        Route::put('/users/{user}/password', [UserController::class, 'password'])->name('users.password');
        Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/unsuspend', [UserController::class, 'unsuspend'])->name('users.unsuspend');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Web domains (spec §6.3)
    Route::get('/users/{user}/web-domains', [WebDomainController::class, 'index'])->name('web-domains.index');
    Route::get('/users/{user}/web-domains/{domain}', [WebDomainController::class, 'show'])->name('web-domains.show');
    Route::middleware('not-readonly')->prefix('/users/{user}/web-domains')->name('web-domains.')->group(function () {
        Route::post('/', [WebDomainController::class, 'store'])->name('store');
        Route::patch('/{domain}', [WebDomainController::class, 'update'])->name('update');
        Route::delete('/{domain}', [WebDomainController::class, 'destroy'])->name('destroy');
        Route::post('/{domain}/suspend', [WebDomainController::class, 'suspend'])->name('suspend');
        Route::post('/{domain}/unsuspend', [WebDomainController::class, 'unsuspend'])->name('unsuspend');
        Route::post('/{domain}/rebuild', [WebDomainController::class, 'rebuild'])->name('rebuild');
        Route::post('/{domain}/purge-cache', [WebDomainController::class, 'purgeCache'])->name('purge-cache');
        Route::post('/{domain}/aliases', [WebDomainController::class, 'addAlias'])->name('aliases.store');
        Route::delete('/{domain}/aliases/{alias}', [WebDomainController::class, 'deleteAlias'])->name('aliases.destroy');
        Route::put('/{domain}/ssl', [WebDomainController::class, 'setSsl'])->name('ssl.update');
        Route::delete('/{domain}/ssl', [WebDomainController::class, 'deleteSsl'])->name('ssl.destroy');
        Route::post('/{domain}/letsencrypt', [WebDomainController::class, 'letsEncrypt'])->name('letsencrypt');
    });
});

// Packages (spec §6.2); a package the principal may not assign answers 404
Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
Route::get('/packages/{package}', [PackageController::class, 'show'])->name('packages.show');
Route::middleware('not-readonly')->group(function () {
    Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
    Route::patch('/packages/{package}', [PackageController::class, 'update'])->name('packages.update');
    Route::post('/packages/{package}/copy', [PackageController::class, 'copy'])->name('packages.copy');
    Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
});
