<?php

namespace App\Http\Requests\Api\V2;

/** Query of `searchFiles` (E34 batch 5 spec §4.1). */
final class SearchFilesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Folder to search in, with its subfolders; the home directory when left out. */
            'path' => ['sometimes', 'nullable', 'string'],
            /** A name or a shell pattern (`*`, `?`, `[…]`), 1 to 100 characters without `/`; a term without a pattern character matches as `*q*`. */
            'q' => ['required', 'string', 'min:1', 'max:100', 'not_regex:/[\/\x00-\x1f\x7f]/'],
        ];
    }
}
