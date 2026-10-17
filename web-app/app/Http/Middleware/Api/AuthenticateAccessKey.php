<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\KeyPermissions;
use App\Api\KeyScopedArcCommand;
use App\Arc\ArcCommand;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Auth\ClientIp;
use App\Auth\Principal;
use App\Policies\AccessKeyPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Authorization: Bearer ACCESS_KEY_ID:SECRET_ACCESS_KEY` (E34 spec D3, D4):
 * the key is checked by `v-check-access-key-permissions`, its owner becomes
 * the Principal and, for a key with permission sets, every command of the
 * request is checked against them. Ten failed attempts per minute and
 * address are answered 429 (D16). An expired key fails like a wrong
 * secret; a key used from outside its address list answers 403
 * E_API_DISABLED (E43 B6, D4).
 */
final class AuthenticateAccessKey
{
    public const FAILURES_PER_MINUTE = 10;

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = ClientIp::of($request);
        $limiter = 'api-v2-auth:'.$ip;
        if (RateLimiter::tooManyAttempts($limiter, self::FAILURES_PER_MINUTE)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($limiter));
        }

        $credentials = self::credentials((string) $request->headers->get('Authorization', ''));
        if ($credentials === null) {
            RateLimiter::hit($limiter, 60);
            throw ApiException::unauthenticated();
        }
        [$keyId, $secret] = $credentials;

        $result = $this->arc->run('v-check-access-key-permissions', [$keyId, Secret::file($secret), $ip ?: '127.0.0.1', 'json'], Options::allowFailure());
        if (! $result->ok()) {
            if ($result->exit() === ExitCode::Suspended) {
                throw new ApiException(ErrorCode::Suspended, 'The owner of the access key is suspended.', 403);
            }
            if ($result->exit() === ExitCode::Forbidden) {
                throw new ApiException(ErrorCode::Forbidden, 'The access key has no permission sets.', 403);
            }
            if ($result->exit() === ExitCode::Disabled) {
                throw ApiException::apiDisabled('The access key does not accept requests from this address.');
            }
            RateLimiter::hit($limiter, 60);
            throw ApiException::unauthenticated();
        }
        $data = json_decode($result->stdout, true);
        $key = KeyPermissions::fromCommand(is_array($data) ? $data : []);
        if ($key->user === '') {
            throw ApiException::unauthenticated();
        }

        // API_SYSTEM 1: the administrator's keys only (as API v1)
        if (! AccessKeyPolicy::apiAvailable($this->config, $key->user)) {
            throw ApiException::apiDisabled('The API is enabled for the administrator only.');
        }

        app()->instance(KeyPermissions::class, $key);
        app()->instance(Principal::class, new Principal(
            loginUser: $key->user,
            impersonated: null,
            loginRole: $key->role,
            effectiveRole: $key->role,
            loginShell: '',
            language: 'en',
            theme: null,
            legacyToken: '',
            readOnly: false,
            accessKey: $key->keyId,
        ));
        if (! $key->unrestricted()) {
            app()->instance(ArcCommand::class, new KeyScopedArcCommand($this->arc, $key));
        }
        Log::shareContext(['access_key' => $key->keyId]);

        return $next($request);
    }

    /** @return array{0: string, 1: string}|null key id and secret */
    public static function credentials(string $header): ?array
    {
        if (preg_match('/^Bearer\s+([[:alnum:]]{20}):([[:alnum:]_.+\/^~=%-]{40})$/', trim($header), $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
