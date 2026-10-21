<?php

namespace App\Http\Controllers\Auth;

use App\Arc\SysConfig;
use App\Auth\LoginThrottle;
use App\Auth\PasswordReset;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Password reset for guests (web/reset), E7
 * batch 3 spec §11: the same answer for every user name, rate limits per IP
 * and per user, single-use codes. A reset code never stays in a URL: the
 * link of the e-mail and the code form put it into the session, and the
 * confirm page reads it from there (E39 W2 spec §5.3).
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
        private readonly LoginThrottle $throttle,
    ) {}

    /**
     * The request form. The links of the reset e-mail keep the 1.x form
     * (/reset/?action=confirm&user=…&code=…, also in templates that
     * administrators customised), so ?action= leads to the matching step.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $this->requireEnabled();

        $action = (string) $request->query('action', '');
        $user = (string) $request->query('user', '');
        $code = (string) $request->query('code', '');
        if ($action === 'confirm' && $user !== '' && $code !== '') {
            return $this->holdCode($request, $user, $code);
        }
        if ($action === 'code') {
            return redirect()->route('reset.code', ['user' => $user]);
        }

        return $this->page('auth.reset');
    }

    public function request(Request $request): RedirectResponse|Response
    {
        $this->requireEnabled();
        $data = $request->validate(['user' => ['required', 'string', 'max:64'], 'email' => ['required', 'string', 'max:254']]);
        if ($limited = $this->limited($request, $data['user'], 'auth.reset')) {
            return $limited;
        }

        // The lookup and the mail run after the answer is sent, so the
        // answer takes the same time for every user name (L6).
        $user = $data['user'];
        $email = $data['email'];
        defer(fn () => $this->reset->request($user, $email));

        return redirect()->route('reset.code', ['user' => $data['user']])->with('ok', __(self::SENT));
    }

    public function code(Request $request): View
    {
        $this->requireEnabled();

        return $this->page('auth.reset-code', ['user' => (string) $request->query('user', '')]);
    }

    /** The code typed on the code page. */
    public function enterCode(Request $request): RedirectResponse
    {
        $this->requireEnabled();
        $data = $request->validate(['user' => ['required', 'string', 'max:64'], 'code' => ['required', 'string', 'max:64']]);

        return $this->holdCode($request, $data['user'], $data['code']);
    }

    /** The new password form, for the code held in the session. */
    public function confirm(Request $request): View|RedirectResponse
    {
        $this->requireEnabled();

        // A link straight to this page (older mails) is cleaned up first
        $user = (string) $request->query('user', '');
        $code = (string) $request->query('code', '');
        if ($user !== '' && $code !== '') {
            return $this->holdCode($request, $user, $code);
        }

        $held = $this->heldCode($request);
        if ($held === null) {
            return redirect()->route('reset.code');
        }

        return $this->page('auth.reset-confirm', $held);
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
        $request->session()->put('reset', ['user' => $data['user'], 'code' => $data['code']]);
        $back = redirect()->route('reset.confirm');
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

            return $back->with('error', $state === 'expired' ? __('Code has been expired') : __('Invalid username or code'));
        }
        if (! $this->reset->reset($data['user'], $data['password'])) {
            return $back->with('error', __('An internal error occurred'));
        }

        $request->session()->forget('reset');
        $this->throttle->clear($data['user']);

        return redirect()->route('login')->with('ok', __('Your password has been changed. You can now log in.'));
    }

    private function holdCode(Request $request, string $user, string $code): RedirectResponse
    {
        $request->session()->put('reset', ['user' => mb_substr($user, 0, 64), 'code' => mb_substr($code, 0, 64)]);

        return redirect()->route('reset.confirm');
    }

    /** @return array{user: string, code: string}|null */
    private function heldCode(Request $request): ?array
    {
        $held = $request->session()->get('reset');
        if (! is_array($held) || ! is_string($held['user'] ?? null) || ! is_string($held['code'] ?? null)) {
            return null;
        }

        return ['user' => $held['user'], 'code' => $held['code']];
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
