<?php

namespace App\Http\Controllers;

use App\Auth\Principal;
use App\Policies\SearchPolicy;
use App\Services\PaletteSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** GET /app/search?q= : every result of the palette search (web/search, E7 batch 3 spec §12.1). */
final class SearchController extends Controller
{
    public function __invoke(Request $request, Principal $principal, SearchPolicy $policy, PaletteSearch $search): View
    {
        abort_unless($policy->use($principal), 403);
        $query = trim((string) $request->query('q', ''));

        return view('search', [
            'query' => $query,
            'groups' => mb_strlen($query) >= 2 ? $search->search($query, PaletteSearch::PAGE_LIMIT) : [],
        ]);
    }
}
