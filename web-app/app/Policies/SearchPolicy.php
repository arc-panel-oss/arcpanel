<?php

namespace App\Policies;

use App\Auth\Principal;

/**
 * The search page (E7 batch 3 spec §12.1); PaletteSearch filters the
 * results by owner scope and capability.
 */
final class SearchPolicy
{
    public function use(Principal $principal): bool
    {
        return Capabilities::allows($principal->actingRole(), 'search.use');
    }
}
