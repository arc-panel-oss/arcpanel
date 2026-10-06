<?php

namespace App\Http\Controllers\Auth;

use App\Auth\MailPasswordChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /reset/mail/ from the webmail clients of this server (see
 * MailPasswordChange). Plain-text answers; requests through a proxy or from
 * another host get an empty 403.
 */
final class MailPasswordController extends Controller
{
    public function __construct(private readonly MailPasswordChange $change) {}

    public function __invoke(Request $request): Response
    {
        if ($request->headers->has('X-Real-IP') || $request->headers->has('X-Forwarded-For')
            || ! $this->change->allowedFrom((string) $request->server('REMOTE_ADDR'), (string) $request->server('SERVER_ADDR'))) {
            return $this->text('', 403);
        }

        $email = trim((string) $request->input('email', ''));
        $old = (string) $request->input('password', '');
        $new = (string) $request->input('new', '');
        if ($email === '') {
            return $this->text('error email address not provided');
        }
        if ($old === '') {
            return $this->text('error old password not provided');
        }
        if ($new === '') {
            return $this->text('error new password not provided');
        }

        $key = 'reset.mail:'.strtolower($email);
        if (RateLimiter::tooManyAttempts($key, MailPasswordChange::ATTEMPTS)) {
            return $this->text('error too many attempts', 429);
        }

        $answer = $this->change->change($email, $old, $new);
        if ($answer === '==ok==') {
            RateLimiter::clear($key);
        } else {
            RateLimiter::hit($key, MailPasswordChange::DECAY);
        }

        return $this->text($answer);
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
