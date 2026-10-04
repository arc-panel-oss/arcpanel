<?php

namespace App\Http\Middleware;

use App\Arc\SysConfig;
use App\Auth\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the logged-in user, or for guests the server language
 * (web/login/index.php, 435-445). Unknown languages fall back to English.
 */
final class SetLocale
{
    public function __construct(private readonly SysConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $language = app()->bound(Principal::class)
            ? app(Principal::class)->language
            : $this->config->get('LANGUAGE', 'en');

        if (preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $language) !== 1
            || ($language !== 'en' && ! is_file(lang_path($language.'.json')))) {
            $language = 'en';
        }
        app()->setLocale($language);

        return $next($request);
    }
}
