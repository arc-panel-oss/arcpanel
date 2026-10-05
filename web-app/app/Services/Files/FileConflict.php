<?php

namespace App\Services\Files;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Destinations that already exist (E3 spec D12): answered with 409 and the
 * names, so the page can ask skip, overwrite or keep both.
 */
final class FileConflict extends RuntimeException
{
    /** @param  list<string>  $names */
    public function __construct(public readonly array $names)
    {
        parent::__construct('Some items already exist in the destination.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'E_EXISTS', 'message' => $this->getMessage(), 'conflicts' => $this->names],
        ], 409);
    }
}
