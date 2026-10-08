<?php

namespace App\Http\Requests\Api\V2;

/** Body of `createDirectory` and `createFile` (E34 batch 5 spec §4.2). */
final class CreateFileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Parent folder relative to the home directory; the home directory when left out. */
            'path' => ['sometimes', 'nullable', 'string'],
            /** Name of the new entry: no slash, not `.` or `..`. */
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
