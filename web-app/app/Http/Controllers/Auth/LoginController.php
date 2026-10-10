<?php

namespace App\Http\Controllers\Auth;

use App\Arc\SysConfig;
use App\Auth\LoginService;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class LoginController extends Controller
{
    /** Minutes a password-verified user has to enter the 2FA code. */
    public const SECOND_FACTOR_MINUTES = 5;

    public function __construct(
        private readonly LoginService $login,
        private readonly LoginResponder $responder,
        private readonly SysConfig $config,
        private readonly LoginThrottle $throttle,
    ) {}

    public function show(Request $request): View
    {
        $this->responder->fingerprint($request);

        return $this->page();
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $username = (string) $request->string('user');
        $context = $this->responder->context($request);

        // The limits answer before any command runs (E39 W2 spec §4.2).
        $wait = $this->throttle->attempt($username, $context->ip, fn () => $this->login->allowListed($username, $context->ip));
        if ($wait !== null) {
            return response($this->page(LoginResponder::tooMany($wait)), 429);
        }

        $result = $this->login->attempt($username, (string) $request->input('password', ''), $context);

        if ($result->needsSecondFactor()) {
            $request->session()->put('pending_2fa', [
                'user' => $result->username,
                'expires_at' => now()->addMinutes(self::SECOND_FACTOR_MINUTES)->getTimestamp(),
            ]);

            return redirect()->route('login.2fa');
        }

        if (! $result->succeeded()) {
            $this->throttle->failed($username, $context->ip);

            return redirect()->route('login')
                ->withInput($request->only('user'))
                ->with('error', __($result->error));
        }

        return $this->responder->complete($request, $result->username, $result->user, $context);
    }

    private function page(?string $tooMany = null): View
    {
        return view('auth.login', [
            'appName' => $this->config->get('APP_NAME', 'ArcPanel'),
            'passwordReset' => $this->config->get('POLICY_SYSTEM_PASSWORD_RESET') !== 'no',
            'tooMany' => $tooMany,
        ]);
    }
}
