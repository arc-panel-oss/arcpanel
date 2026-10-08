<?php

namespace App\Http\Requests\Api\V2;

/** Body of `compressFiles` (E34 batch 5 spec §4.2). */
final class CompressFilesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Entries of one folder, relative to the home directory. */
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string', 'distinct'],
            /** Name of the new zip archive in that folder, ending in `.zip`. */
            'name' => ['required', 'string', 'max:255', 'regex:/\.zip$/i'],
        ];
    }
}
