<?php

use App\Arc\ArcCommandException;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\BindEffectiveUser;
use App\Http\Middleware\FilesEnabled;
use App\Http\Middleware\NotReadOnly;
use App\Http\Middleware\RedirectIfLoggedIn;
use App\Http\Middleware\RequirePrincipal;
use App\Http\Middleware\RequireSysConfig;
use App\Http\Middleware\ResolvePrincipal;
use App\Http\Middleware\SetLocale;
use App\View\UiPreferences;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| The panel app is served under /app/ by hestia-nginx (ADR-0002). nginx passes
| SCRIPT_NAME=/app/index.php, so Laravel derives the /app base URL itself and
| routes are written without the prefix.
*/

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Outside the web group: no sessions, no cookies.
        then: fn () => Route::get('/_health', HealthController::class)->name('health'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The legacy session cookie is PHP's own, never encrypted by Laravel;
        // arc_ui (theme, density) is written by the user menu script.
        $middleware->encryptCookies(except: [(string) ini_get('session.name'), UiPreferences::COOKIE]);
        // Form posts to legacy page paths answer 404 (E8 spec §5.3), not 419.
        $middleware->validateCsrfTokens(except: ['list/*', 'add/*', 'edit/*', 'download/*', 'generate/*', 'fm', 'fm/*']);
        $middleware->web(append: [
            ResolvePrincipal::class,
            SetLocale::class,
        ]);
        $middleware->alias([
            'auth.arc' => RequirePrincipal::class,
            'guest.arc' => RedirectIfLoggedIn::class,
            'not-readonly' => NotReadOnly::class,
            'effective-user' => BindEffectiveUser::class,
            'files.enabled' => FilesEnabled::class,
            'sys-config' => RequireSysConfig::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ArcCommandException::class);

        $exceptions->render(function (ArcCommandException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $e->exit()?->errorCode() ?? 'E_COMMAND',
                        'message' => $e->userMessage(),
                    ],
                ], $e->httpStatus());
            }

            // A page that cannot load shows the error; a failed change goes back to the form.
            if ($request->isMethodSafe()) {
                return response()->view('errors.command', [
                    'message' => $e->userMessage(),
                    'status' => $e->httpStatus(),
                ], $e->httpStatus());
            }

            return back()->withInput($request->except(['password', 'key']))->with('error', $e->userMessage());
        });
    })->create();

// Production configuration lives with the server configuration, written by
// v-add-sys-arcpanel (install/upgrade). ARCPANEL_ENV_FILE overrides it (the
// test suite); without either, Laravel's default .env is used (development).
$envFile = getenv('ARCPANEL_ENV_FILE') ?: '/etc/arcpanel/arcpanel.env';
if (! str_starts_with($envFile, '/')) {
    $envFile = $app->basePath($envFile);
}
if (is_readable($envFile)) {
    $app->useEnvironmentPath(dirname($envFile));
    $app->loadEnvironmentFrom(basename($envFile));
}

return $app;
