<?php

namespace App\Http\Requests\Api\V2;

/** Query of `getFileInfo` and `getFolderSize` (E34 batch 5 spec §4.1). */
final class FilePathRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Path relative to the home directory; the home directory when left out. */
            'path' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
