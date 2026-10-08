<?php

namespace App\Http\Requests\Api\V2;

/** Body of `downloadFiles` (E34 batch 5 spec §4.4). */
final class DownloadFilesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Entries of one folder, relative to the home directory; not the home directory itself. One file is sent as it is, a folder or several entries as a zip. */
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string', 'distinct'],
            /** Show one file in the browser when its type allows it (images, audio, video, PDF, plain text; never SVG). Ignored for a zip. */
            'inline' => ['sometimes', 'boolean'],
        ];
    }
}
