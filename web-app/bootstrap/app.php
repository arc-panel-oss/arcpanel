<?php

use App\Api\ApiException;
use App\Api\ErrorRenderer;
use App\Arc\ArcCommandException;
use App\Auth\ProtectedAdmin;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\Api\ApiEnabled;
use App\Http\Middleware\Api\AuthenticateAccessKey;
use App\Http\Middleware\Api\JournalApiRequest;
use App\Http\Middleware\Api\StartApiRequest;
use App\Http\Middleware\BindEffectiveUser;
use App\Http\Middleware\FilesEnabled;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\NotReadOnly;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\RedirectIfLoggedIn;
use App\Http\Middleware\RequirePrincipal;
use App\Http\Middleware\RequireSysConfig;
use App\Http\Middleware\ResolvePrincipal;
use App\Http\Middleware\SetLocale;
use App\Support\PanelHost;
use App\View\UiPreferences;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| The panel app is served under /app/ by arcpanel-nginx (ADR-0002). nginx passes
| SCRIPT_NAME=/app/index.php, so Laravel derives the /app base URL itself and
| routes are written without the prefix.
*/

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Outside the web group: no sessions, no cookies.
        then: function (): void {
            Route::get('/_health', HealthController::class)->name('health');

            // REST API v2 (E34 spec D1): stateless, authenticated by access key
            Route::prefix('api/v2')
                ->name('api.v2.')
                ->middleware([StartApiRequest::class, ApiEnabled::class, AuthenticateAccessKey::class, 'throttle:api-v2', JournalApiRequest::class])
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the configured names reach the panel, so no link or redirect
        // is built from a forged Host header (E39 W2 spec D6).
        $middleware->trustHosts(at: fn () => app(PanelHost::class)->trusted(), subdomains: false);
        // The legacy session cookie is PHP's own, never encrypted by Laravel;
        // arc_ui (theme, density) is written by the user menu script.
        $middleware->encryptCookies(except: [(string) ini_get('session.name'), UiPreferences::COOKIE]);
        // Form posts to legacy page paths answer 404 (E8 spec §5.3), not 419.
        // reset/mail is posted by the webmail clients of the server, without
        // a session (MailPasswordController).
        $middleware->validateCsrfTokens(except: ['list/*', 'add/*', 'edit/*', 'download/*', 'generate/*', 'fm', 'fm/*', 'reset/mail']);
        // File names and file content of REST API v2 keep their white space
        // (E34 batch 5): "a.txt " is another file than "a.txt".
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/v2/users/*/files', 'api/v2/users/*/files/*'),
            // The editor of the file manager saves the content as typed
            fn (Request $request) => $request->is('files/*/api/save'),
            // A secret value keeps its spaces (E38)
            fn (Request $request) => $request->is('secrets/*', 'api/v2/users/*/secrets', 'api/v2/users/*/secrets/*'),
        ]);
        $middleware->web(append: [
            ResolvePrincipal::class,
            SetLocale::class,
        ], replace: [
            // No XSRF-TOKEN cookie for the tool ports to read (E46 spec §6.3)
            Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class => PreventRequestForgery::class,
        ]);
        $middleware->alias([
            'auth.arc' => RequirePrincipal::class,
            'guest.arc' => RedirectIfLoggedIn::class,
            'not-readonly' => NotReadOnly::class,
            'no-referrer' => NoReferrer::class,
            'effective-user' => BindEffectiveUser::class,
            'files.enabled' => FilesEnabled::class,
            'sys-config' => RequireSysConfig::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ArcCommandException::class, ApiException::class]);

        // A change refused by the protected-administrator guard answers 404
        // under POLICY_SYSTEM_HIDE_ADMIN, on the pages and in API v2 (E39 W4 §5).
        $exceptions->map(fn (Throwable $e) => app()->resolved(ProtectedAdmin::class) ? app(ProtectedAdmin::class)->hide($e) : $e);

        // API v2 answers every error with its JSON error body (E34 spec D10).
        $exceptions->render(fn (Throwable $e, Request $request) => ErrorRenderer::handles($request) ? ErrorRenderer::render($e, $request) : null);

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
