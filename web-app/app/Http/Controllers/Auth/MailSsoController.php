<?php

namespace App\Http\Controllers\Auth;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /sso/mail from the Roundcube plugin arcpanel_sso of this server (E46
 * spec §7.5): exchanges a sign-on ticket for the address and a session
 * password of the mail account. Only the loopback address, without a proxy
 * header, gets an answer; anything else answers 404. The answer is never
 * logged or cached.
 */
final class MailSsoController extends Controller
{
    /** Exchanges a minute, for every ticket together. */
    public const ATTEMPTS = 30;

    public function __construct(private readonly ArcCommand $arc) {}

    public function __invoke(Request $request): JsonResponse
    {
        $remote = (string) $request->server('REMOTE_ADDR');
        abort_if($request->headers->has('X-Real-IP') || $request->headers->has('X-Forwarded-For')
            || ! in_array($remote, ['127.0.0.1', '::1'], true), 404);

        if (RateLimiter::tooManyAttempts('sso.mail', self::ATTEMPTS)) {
            return $this->answer(['error' => 'too many attempts'], 429);
        }
        RateLimiter::hit('sso.mail', 60);

        $ticket = (string) $request->input('ticket', '');
        $clientIp = (string) $request->input('client_ip', '');
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $ticket) !== 1 || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return $this->answer(['error' => 'forbidden'], 403);
        }

        $result = $this->arc->run('v-check-mail-account-sso', [Secret::file($ticket), $clientIp, 'json'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($data) || ! is_string($data['USER'] ?? null) || ! is_string($data['PASSWORD'] ?? null)) {
            return $this->answer(['error' => 'forbidden'], 403);
        }

        return $this->answer(['user' => $data['USER'], 'password' => $data['PASSWORD']]);
    }

    /** @param  array<string, string>  $body */
    private function answer(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
