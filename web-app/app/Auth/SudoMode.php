<?php

namespace App\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sudo mode (E39 W2 spec §7, D12): sensitive changes to the login user's own
 * account need a password confirmed in the last ten minutes. The time lives
 * in the legacy session, so a new login starts without it.
 */
final class SudoMode
{
    /** How long a confirmation lasts. */
    public const SECONDS = 600;

    /** Legacy session key: Unix time until which the confirmation holds. */
    public const KEY = 'SUDO_UNTIL';

    public function __construct(private readonly LegacySession $session) {}

    public function active(): bool
    {
        $data = $this->session->read();
        $this->session->close();

        return (int) ($data[self::KEY] ?? 0) > now()->getTimestamp();
    }

    public function confirm(): void
    {
        $this->session->write([self::KEY => now()->getTimestamp() + self::SECONDS]);
    }

    /**
     * Whether a sensitive change of $target must be confirmed first: only for
     * the login user's own account, not while impersonating (the account is
     * then someone else's), and not when a confirmation still holds.
     */
    public function required(Principal $principal, string $target): bool
    {
        return $target === $principal->loginUser
            && ! $principal->isImpersonating()
            && ! $this->active();
    }

    /** Send the user to the password form; they come back to the form they came from. */
    public function challenge(Request $request): RedirectResponse
    {
        $request->session()->put('sudo.intended', url()->previous());

        return redirect()->route('sudo.show');
    }
}
