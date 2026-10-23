<?php

namespace App\Http\Controllers\Auth;

use App\Arc\SysConfig;
use App\Audit\AuditRecorder;
use App\Auth\LoginService;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Second login step. The pending state lives in the panel-app session only
 * and never holds the password (the legacy login kept it in the session).
 * Wrong codes are counted per user in LoginThrottle, not in the session, so
 * a new login does not start a new count (E39 W2 spec D3). A recovery code
 * may stand in for the 2FA code and shares that count (E39 W5 §11.3).
 */
final class TwoFactorController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly LoginResponder $responder,
        private readonly SysConfig $config,
        private readonly LoginThrottle $throttle,
        private readonly AuditRecorder $audit,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect()->route('login');
        }

        return $this->page(recovery: $request->boolean('recovery'));
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()->route('login')->with('error', __(LoginService::INVALID_2FA));
        }

        $wait = $this->throttle->attemptSecondFactor($pending['user']);
        if ($wait !== null) {
            $request->session()->forget('pending_2fa');
            $this->audit->explicit($request, 'auth.2fa.verify', 'denied', null, $pending['user']);

            return response($this->page(LoginResponder::tooMany($wait)), 429);
        }

        $context = $this->responder->context($request);
        $recovery = $request->has('recovery_code');
        if ($recovery) {
            [$result, $left] = $this->login->verifyRecoveryCode($pending['user'], (string) $request->input('recovery_code', ''), $context);
        } else {
            $result = $this->login->verifySecondFactor(
                $pending['user'],
                trim((string) $request->input('twofa', '')),
                $context,
            );
        }

        $this->audit->explicit($request, 'auth.2fa.verify', $result->succeeded() ? 'ok' : 'denied', null, $pending['user'], $result->user['ROLE'] ?? null);
        if ($result->succeeded()) {
            $this->throttle->clearSecondFactor($pending['user']);
            $response = $this->responder->complete($request, $result->username, $result->user, $context);

            return $recovery
                ? $response->with('warn', sprintf(__('You signed in with a recovery code. Recovery codes left: %d.'), $left))
                : $response;
        }

        return redirect()->route('login.2fa', $recovery ? ['recovery' => 1] : [])->with('error', __($result->error));
    }

    private function page(?string $tooMany = null, bool $recovery = false): View
    {
        return view('auth.two-factor', [
            'appName' => $this->config->get('APP_NAME', 'ArcPanel'),
            'tooMany' => $tooMany,
            'recovery' => $recovery,
        ]);
    }

    /** @return array{user: string, expires_at: int}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('pending_2fa');
        if (! is_array($pending) || ! is_string($pending['user'] ?? null)) {
            return null;
        }
        if ((int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget('pending_2fa');

            return null;
        }

        return [
            'user' => $pending['user'],
            'expires_at' => (int) $pending['expires_at'],
        ];
    }
}
