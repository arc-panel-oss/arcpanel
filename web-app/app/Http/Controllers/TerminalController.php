<?php

namespace App\Http\Controllers;

use App\Auth\Principal;
use App\Policies\TerminalPolicy;
use App\Services\Terminal\TerminalTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * The web terminal page (web/list/terminal), E7 batch 2 spec §10.4. The
 * shell itself comes from the web terminal server behind /_shell/, which
 * opens it for a ticket signed here (E39 W2 spec §8).
 */
final class TerminalController extends Controller
{
    public function __invoke(Principal $principal, TerminalPolicy $policy): View
    {
        abort_unless($policy->use($principal), 403);

        return view('terminal.index');
    }

    /** A single-use ticket for the effective user, sent by the page as a WebSocket subprotocol. */
    public function ticket(Principal $principal, TerminalPolicy $policy, TerminalTickets $tickets): JsonResponse
    {
        abort_unless($policy->use($principal), 403);
        $ticket = $tickets->issue($principal->effectiveUser());
        abort_if($ticket === null, 503, 'The web terminal key is missing.');

        return response()->json(['ticket' => $ticket])->header('Cache-Control', 'no-store, private');
    }
}
