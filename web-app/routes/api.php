<?php

use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\PackageController;
use App\Http\Controllers\Api\V2\UserController;
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
