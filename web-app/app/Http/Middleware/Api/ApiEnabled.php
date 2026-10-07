<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiException;
use App\Arc\SysConfig;
use App\Auth\ClientIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API v2 answers only when API_SYSTEM is 1 or 2 and the client's address
 * passes API_ALLOWED_IP, with the rules of API v1 (lib/api/index.php, E34
 * spec D8). Whether a non-admin key may use the API is checked once the key
 * is known (AuthenticateAccessKey).
 */
final class ApiEnabled
{
    public function __construct(private readonly SysConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $this->config->get('API_SYSTEM', '0') < 1) {
            throw ApiException::apiDisabled();
        }
        if (! self::ipAllowed($this->config->get('API_ALLOWED_IP'), ClientIp::of($request))) {
            throw ApiException::apiDisabled('The API does not accept requests from this address.');
        }

        return $next($request);
    }

    /** API_ALLOWED_IP: "allow-all", or a comma list in which 0.0.0.0 allows every address. */
    public static function ipAllowed(string $allowed, string $ip): bool
    {
        if ($allowed === 'allow-all') {
            return true;
        }
        $list = array_map('trim', explode(',', $allowed));

        return in_array('0.0.0.0', $list, true) || ($ip !== '' && in_array($ip, $list, true));
    }
}
