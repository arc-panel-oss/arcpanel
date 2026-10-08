<?php

namespace App\Http\Requests\Api\V2;

/** Body of `renameFile` (E34 batch 5 spec §4.2). */
final class RenameFileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Entry relative to the home directory. */
            'path' => ['required', 'string'],
            /** New name in the same folder: no slash, not `.` or `..`. */
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
