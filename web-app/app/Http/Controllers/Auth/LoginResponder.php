<?php

namespace App\Http\Controllers\Auth;

use App\Auth\ClientIp;
use App\Auth\Landing;
use App\Auth\LegacySession;
use App\Auth\LoginContext;
use App\Auth\LoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shared by the password and 2FA steps: the request facts of a login attempt
 * and what happens once it succeeded.
 */
final class LoginResponder
{
    public function __construct(
        private readonly LoginService $login,
        private readonly LegacySession $legacy,
    ) {}

    public function context(Request $request): LoginContext
    {
        return new LoginContext(
            ClientIp::of($request),
            (string) $request->userAgent(),
            $this->fingerprint($request),
        );
    }

    /**
     * Identifier of this login attempt in the auth log (legacy: the login
     * form's token). It becomes the legacy session's CSRF token.
     */
    public function fingerprint(Request $request): string
    {
        $fingerprint = $request->session()->get('login.fingerprint');
        if (! is_string($fingerprint) || preg_match('/^[0-9a-f]{32}$/', $fingerprint) !== 1) {
            $fingerprint = bin2hex(random_bytes(16));
            $request->session()->put('login.fingerprint', $fingerprint);
        }

        return $fingerprint;
    }

    /**
     * Write the legacy session and send the user to their legacy landing page.
     *
     * @param  array<string, string>  $user
     */
    public function complete(Request $request, string $username, array $user, LoginContext $context): RedirectResponse
    {
        $this->legacy->establish($this->login->complete($username, $user, $context));

        $request->session()->forget(['login', 'pending_2fa']);
        $request->session()->regenerate();

        return redirect()->away(Landing::for($user));
    }
}
