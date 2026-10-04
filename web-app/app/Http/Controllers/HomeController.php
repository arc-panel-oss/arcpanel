<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * /app/ has no page of its own yet (dashboard: E1 slice S2); the legacy
 * /login/ sends a logged-in session to its landing page.
 */
final class HomeController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->away('/login/');
    }
}
