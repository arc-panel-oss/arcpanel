<?php

namespace App\Http\Controllers\Users;

use App\Auth\Principal;
use App\Auth\SudoMode;
use App\Http\Controllers\Controller;
use App\Services\Users\TwoFactorService;
use App\Services\Users\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Turning two-factor authentication on, and new recovery codes (E39 W5
 * spec §11.3): the login user's own account only, not while impersonating,
 * in sudo mode. The secret and the codes are answered directly, never
 * stored in the session or a flash, as the new access keys are.
 */
final class TwoFactorSetupController extends Controller
{
    /** Codes a login user may type in a minute while turning 2FA on. */
    public const CODES_PER_MINUTE = 10;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly UserService $users,
    ) {}

    /** GET /account/two-factor: what turning it on means, with the button. */
    public function show(Principal $principal): View|RedirectResponse
    {
        $user = $this->ownAccount($principal);
        if ($this->users->find($user)->twoFactor()) {
            return redirect()->route('account.edit');
        }

        return view('account.two-factor', ['user' => $user, 'step' => 'intro']);
    }

    /** POST /account/two-factor: a new secret with its QR code and the code form. */
    public function start(Request $request, Principal $principal, SudoMode $sudo): View|RedirectResponse
    {
        $user = $this->ownAccount($principal);
        if ($sudo->required($principal, $user)) {
            return $sudo->challenge($request);
        }
        $enrolment = $this->twoFactor->start($user);
        if ($enrolment === null) {
            return redirect()->route('account.edit')->with('error', __('Two-factor authentication could not be set up.'));
        }

        return $this->setupPage($user, $enrolment['secret'], $enrolment['uri']);
    }

    /**
     * POST /account/two-factor/confirm {code, secret, uri}: the secret and
     * its URI come back from the page only to draw it again after a wrong
     * code; the command checks the code against its own pending secret.
     */
    public function confirm(Request $request, Principal $principal, SudoMode $sudo): View|RedirectResponse
    {
        $user = $this->ownAccount($principal);
        if ($sudo->required($principal, $user)) {
            return $sudo->challenge($request);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'secret' => ['required', 'string', 'regex:'.TwoFactorService::SECRET],
            'uri' => ['required', 'string', 'max:512'],
        ]);
        abort_unless(TwoFactorService::isUriOf($data['uri'], $data['secret']), 422);
        $limit = 'two-factor-setup:'.$user;
        abort_if(RateLimiter::tooManyAttempts($limit, self::CODES_PER_MINUTE), 429);
        RateLimiter::hit($limit, 60);

        $code = preg_replace('/\s+/', '', $data['code']) ?? '';
        $codes = preg_match('/^[0-9]{6}$/', $code) === 1 ? $this->twoFactor->confirm($user, $code) : null;
        if ($codes === null) {
            return $this->setupPage($user, $data['secret'], $data['uri'], __('The code is not right, or the setup took longer than 10 minutes. Try the next code, or start again.'));
        }

        return $this->codesPage($user, $codes, __('Two-factor authentication is on.'));
    }

    /** POST /account/two-factor/recovery-codes: ten new codes; the old ones stop working. */
    public function recoveryCodes(Request $request, Principal $principal, SudoMode $sudo): View|RedirectResponse
    {
        $user = $this->ownAccount($principal);
        if ($sudo->required($principal, $user)) {
            return $sudo->challenge($request);
        }
        $codes = $this->users->find($user)->twoFactor() ? $this->twoFactor->newRecoveryCodes($user) : null;
        if ($codes === null) {
            return redirect()->route('account.edit')->with('error', __('New recovery codes could not be created.'));
        }

        return $this->codesPage($user, $codes, __('New recovery codes are ready. The earlier codes no longer work.'));
    }

    /** The login user, refused while impersonating: 2FA belongs to whoever signs in. */
    private function ownAccount(Principal $principal): string
    {
        abort_if($principal->isImpersonating(), 403);

        return $principal->loginUser;
    }

    private function setupPage(string $user, string $secret, string $uri, ?string $error = null): View
    {
        return view('account.two-factor', [
            'user' => $user,
            'step' => 'scan',
            'secret' => $secret,
            'uri' => $uri,
            'qr' => TwoFactorService::qrDataUri($uri),
            'error' => $error,
        ]);
    }

    /** @param  list<string>  $codes */
    private function codesPage(string $user, array $codes, string $notice): View
    {
        return view('account.two-factor', [
            'user' => $user,
            'step' => 'codes',
            'codes' => $codes,
            'download' => TwoFactorService::codesFile($user, $codes),
            'notice' => $notice,
        ]);
    }
}
