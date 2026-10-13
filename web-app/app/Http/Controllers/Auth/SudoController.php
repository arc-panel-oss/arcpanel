<?php

namespace App\Http\Controllers\Auth;

use App\Arc\SysConfig;
use App\Auth\LoginService;
use App\Auth\LoginThrottle;
use App\Auth\Principal;
use App\Auth\SudoMode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The password confirmation of sudo mode (E39 W2 spec §7). It checks the
 * login user's password, with the limits of a login.
 */
final class SudoController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly LoginThrottle $throttle,
        private readonly LoginResponder $responder,
        private readonly SudoMode $sudo,
        private readonly SysConfig $config,
    ) {}

    public function show(Principal $principal): View
    {
        return $this->page($principal);
    }

    public function store(Request $request, Principal $principal): RedirectResponse|Response
    {
        $user = $principal->loginUser;
        $context = $this->responder->context($request);

        $wait = $this->throttle->attempt($user, $context->ip, fn () => false);
        if ($wait !== null) {
            return response($this->page($principal, LoginResponder::tooMany($wait)), 429);
        }

        if (! $this->login->verifyPassword($user, (string) $request->input('password', ''), $context)) {
            $this->throttle->failed($user, $context->ip);

            return redirect()->route('sudo.show')->with('error', __(LoginService::INVALID_CREDENTIALS));
        }

        $this->sudo->confirm();

        return redirect()->to((string) $request->session()->pull('sudo.intended', route('dashboard')));
    }

    private function page(Principal $principal, ?string $tooMany = null): View
    {
        return view('auth.sudo', [
            'appName' => $this->config->get('APP_NAME', 'ArcPanel'),
            'user' => $principal->loginUser,
            'tooMany' => $tooMany,
        ]);
    }
}
