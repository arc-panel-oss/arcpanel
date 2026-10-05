<?php

namespace App\Http\Controllers\Shell;

use App\Arc\ArcCommand;
use App\Auth\LegacySession;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\View\ShellComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Language from the user menu: `v-change-user-language` for the logged-in
 * user, then the session language both apps read.
 */
final class LanguageController extends Controller
{
    public function __invoke(Request $request, ArcCommand $arc, LegacySession $legacy, Principal $principal): RedirectResponse
    {
        $language = (string) $request->input('language');
        abort_unless(array_key_exists($language, ShellComposer::languages()), 422);

        $arc->run('v-change-user-language', [$principal->loginUser, $language]);
        $legacy->write(['language' => $language]);

        return back();
    }
}
