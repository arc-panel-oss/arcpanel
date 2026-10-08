<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Validation\Rule;

/** Body of `copyFiles` and `moveFiles` (E34 batch 5 spec §4.2). */
final class TransferFilesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Entries relative to the home directory. */
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string', 'distinct'],
            /** Folder relative to the home directory; empty or null for the home directory. */
            'destination' => ['present', 'nullable', 'string'],
            /** A name that exists in the destination: `fail` (default; `409` with `conflicts`), `skip`, `overwrite` or `rename` (first free "name (n).ext"). */
            'on_conflict' => ['sometimes', Rule::in(['fail', 'skip', 'overwrite', 'rename'])],
        ];
    }
}
