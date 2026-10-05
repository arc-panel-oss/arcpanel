<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use App\Services\PaletteSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /app/_palette?q= : grouped results of the command palette. */
final class PaletteController extends Controller
{
    public function __invoke(Request $request, PaletteSearch $search): JsonResponse
    {
        return response()->json(['groups' => $search->search((string) $request->query('q', ''))]);
    }
}
