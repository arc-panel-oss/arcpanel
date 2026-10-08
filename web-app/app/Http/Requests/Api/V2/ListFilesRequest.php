<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Validation\Rule;

/** Query of `listFiles` (E34 batch 5 spec §4.1); `limit` and `offset` follow D20 (App\Api\Paginates). */
final class ListFilesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Folder relative to the home directory; the home directory when left out. */
            'path' => ['sometimes', 'nullable', 'string'],
            /** Folders come first, then this order. */
            'sort' => ['sometimes', Rule::in(['name', 'size', 'mtime'])],
            'order' => ['sometimes', Rule::in(['asc', 'desc'])],
            /** List names that start with a dot (default true). */
            'hidden' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
        ];
    }
}
