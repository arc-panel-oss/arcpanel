<?php

namespace App\Http\Requests\Api\V2;

/** Body of `deleteFiles` (E34 batch 5 spec §4.2). */
final class DeleteFilesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Entries relative to the home directory; a folder is deleted with its content. */
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string', 'distinct'],
        ];
    }
}
