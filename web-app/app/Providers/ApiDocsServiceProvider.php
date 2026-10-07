<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Scramble (a dev dependency) for `php artisan arcpanel:api-docs`, which
 * writes docs/api/v2/openapi.json (E34 spec D14, §7). Registered on the
 * console only, and without the documentation routes of the package: the
 * panel serves no documentation page.
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();
        $this->app->register(ScrambleServiceProvider::class);
    }

    public function boot(): void
    {
        config(['scramble.api_path' => 'api/v2']);
        Scramble::configure()->routes(fn (Route $route) => str_starts_with($route->uri(), 'api/v2/'));

        // One tag per operation: the `@tags` line of its controller
        Scramble::resolveTagsUsing(function (RouteInfo $route): array {
            $class = $route->className();
            $doc = $class !== null && class_exists($class) ? (string) (new ReflectionClass($class))->getDocComment() : '';

            return preg_match('/@tags\s+([^\n*]+)/', $doc, $m) === 1 ? [trim($m[1])] : [];
        });
    }
}
