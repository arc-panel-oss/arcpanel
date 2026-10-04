<?php

namespace App\Http\Controllers\Auth;

use App\Arc\SysConfig;
use App\Auth\LoginService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Second login step. The pending state lives in the panel-app session only
 * and never holds the password (the legacy login kept it in the session).
 */
final class TwoFactorController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly LoginResponder $responder,
        private readonly SysConfig $config,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor', [
            'appName' => $this->config->get('APP_NAME', 'ArcPanel'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()->route('login')->with('error', __(LoginService::INVALID_2FA));
        }

        $context = $this->responder->context($request);
        $result = $this->login->verifySecondFactor(
            $pending['user'],
            trim((string) $request->input('twofa', '')),
            $pending['failures'],
            $context,
        );

        if ($result->succeeded()) {
            return $this->responder->complete($request, $result->username, $result->user, $context);
        }

        $pending['failures']++;
        if ($pending['failures'] > LoginService::MAX_2FA_FAILURES) {
            $request->session()->forget('pending_2fa');

            return redirect()->route('login')->with('error', __($result->error));
        }
        $request->session()->put('pending_2fa', $pending);

        return redirect()->route('login.2fa')->with('error', __($result->error));
    }

    /** @return array{user: string, expires_at: int, failures: int}|null */
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
            'failures' => (int) ($pending['failures'] ?? 0),
        ];
    }
}
