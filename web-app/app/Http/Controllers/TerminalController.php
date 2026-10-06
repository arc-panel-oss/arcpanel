<?php

namespace App\Http\Controllers;

use App\Auth\Principal;
use App\Policies\TerminalPolicy;
use Illuminate\View\View;

/**
 * The web terminal page (web/list/terminal), E7 batch 2 spec §10.4. The
 * shell itself comes from the web terminal server behind /_shell/.
 */
final class TerminalController extends Controller
{
    public function __invoke(Principal $principal, TerminalPolicy $policy): View
    {
        abort_unless($policy->use($principal), 403);

        return view('terminal.index');
    }
}
