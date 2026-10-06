<?php

namespace App\Http\Controllers\Auth;

use App\Arc\SysConfig;
use App\Auth\PasswordReset;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Password reset and 2FA reset for guests (web/reset, web/reset2fa), E7
 * batch 3 spec §11: the same answer for every user name, rate limits per IP
 * and per user, single-use codes.
 */
final class ResetController extends Controller
{
    /** Requests per address and per user name in DECAY seconds. */
    public const ATTEMPTS = 5;

    public const DECAY = 900;

    private const SENT = 'Password reset instructions have been sent to the email address associated with this account.';

    public function __construct(
        private readonly PasswordReset $reset,
        private readonly LoginResponder $responder,
        private readonly SysConfig $config,
    ) {}

    public function show(): View
    {
        $this->requireEnabled();

        return $this->page('auth.reset');
    }

    public function request(Request $request): RedirectResponse|Response
    {
        $this->requireEnabled();
        $data = $request->validate(['user' => ['required', 'string', 'max:64'], 'email' => ['required', 'string', 'max:254']]);
        if ($limited = $this->limited($request, $data['user'], 'auth.reset')) {
            return $limited;
        }

        $this->reset->request($data['user'], $data['email'], $request->getHttpHost());

        return redirect()->route('reset.code', ['user' => $data['user']])->with('ok', __(self::SENT));
    }

    public function code(Request $request): View
    {
        $this->requireEnabled();

        return $this->page('auth.reset-code', ['user' => (string) $request->query('user', '')]);
    }

    /** The link of the e-mail: the new password form. */
    public function confirm(Request $request): View
    {
        $this->requireEnabled();

        return $this->page('auth.reset-confirm', [
            'user' => (string) $request->query('user', ''),
            'code' => (string) $request->query('code', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse|Response
    {
        $this->requireEnabled();
        $data = $request->validate([
            'user' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:256'],
            'password_confirm' => ['required', 'string', 'max:256'],
        ]);
        $back = redirect()->route('reset.confirm', ['user' => $data['user'], 'code' => $data['code']]);
        if ($limited = $this->limited($request, $data['user'], 'auth.reset-confirm', ['user' => $data['user'], 'code' => $data['code']])) {
            return $limited;
        }
        if ($data['password'] !== $data['password_confirm']) {
            return $back->with('error', __('Passwords do not match'));
        }

        $context = $this->responder->context($request);
        $state = $this->reset->check($data['user'], $data['code']);
        if ($state !== 'ok') {
            $this->reset->logFailure($data['user'], $context, $state === 'expired' ? 'Reset code has been expired' : 'Invalid Username or Code');
            PasswordReset::delay();

            return $back->with('error', $state === 'expired' ? __('Code has been expired') : __('Invalid username or code'));
        }
        if (! $this->reset->reset($data['user'], $data['password'])) {
            PasswordReset::delay();

            return $back->with('error', __('An internal error occurred'));
        }

        return redirect()->route('login')->with('ok', __('Your password has been changed. You can now log in.'));
    }

    public function twoFactor(): View
    {
        return $this->page('auth.reset2fa', ['unlocked' => false]);
    }

    public function twoFactorStore(Request $request): View|RedirectResponse|Response
    {
        $data = $request->validate(['user' => ['required', 'string', 'max:64'], 'twofa' => ['required', 'string', 'max:128']]);
        if ($limited = $this->limited($request, $data['user'], 'auth.reset2fa', ['unlocked' => false])) {
            return $limited;
        }

        if (! $this->reset->resetTwoFactor($data['user'], trim($data['twofa']))) {
            $this->reset->logFailure($data['user'], $this->responder->context($request), 'Failed to enter correct 2FA reset key');
            PasswordReset::delay();

            return redirect()->route('reset2fa')->withInput($request->only('user'))->with('error', __('Invalid username or code'));
        }

        return $this->page('auth.reset2fa', ['unlocked' => true]);
    }

    /** 404 when POLICY_SYSTEM_PASSWORD_RESET is "no", as the login page hides the link. */
    private function requireEnabled(): void
    {
        abort_if($this->config->get('POLICY_SYSTEM_PASSWORD_RESET') === 'no', 404);
    }

    /**
     * 429 with the form again once an address or a user name made too many
     * requests; every request counts.
     *
     * @param  array<string, mixed>  $data
     */
    private function limited(Request $request, string $user, string $view, array $data = []): ?Response
    {
        $keys = ['reset:ip:'.$request->ip(), 'reset:user:'.strtolower($user)];
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                return response($this->page($view, $data + ['tooMany' => sprintf(__('Too many attempts. Try again in %d minutes.'), $minutes)]), 429);
            }
        }
        foreach ($keys as $key) {
            RateLimiter::hit($key, self::DECAY);
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function page(string $view, array $data = []): View
    {
        return view($view, $data + ['appName' => $this->config->get('APP_NAME', 'ArcPanel'), 'tooMany' => null]);
    }
}
