<?php

namespace App\Http\Requests\Api\V2;

/** Body of `startUpload` (E34 batch 5 spec §4.3). */
final class StartUploadRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Folder relative to the home directory; the home directory when left out. */
            'path' => ['sometimes', 'nullable', 'string'],
            /** Name of the file: no slash, not `.` or `..`. */
            'name' => ['required', 'string', 'max:255'],
            /** Bytes of the whole file. */
            'size' => ['required', 'integer', 'min:0'],
            /** For a file of a folder upload: its path below `path`, ending in `name`; the missing folders are created when the upload finishes. */
            'relative_path' => ['sometimes', 'nullable', 'string', 'max:4096'],
            /** The file's modification time as the client knows it (for example Unix milliseconds). With the folder, the name and the size it finds the session to resume. */
            'modified_at' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
