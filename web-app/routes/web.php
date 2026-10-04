<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;

// Served under /app/ (ADR-0002). Link with route names only, so the prefix
// can be dropped at 2.0 without touching views.

Route::middleware('guest.arc')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/login/2fa', [TwoFactorController::class, 'show'])->name('login.2fa');
    Route::post('/login/2fa', [TwoFactorController::class, 'store'])->name('login.2fa.store');
});

Route::middleware('auth.arc')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->where('user', '[[:alnum:]][-.\w]{0,28}[[:alnum:]]')
        ->name('impersonate.start');
});
