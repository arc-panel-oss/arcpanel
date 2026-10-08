<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `saveFile` (E34 batch 5 spec §4.2). `content` may span lines; a
 * NUL byte is refused, because binary content is out of scope (§8).
 */
final class SaveFileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** File relative to the home directory. */
            'path' => ['required', 'string'],
            /** The new text, at most 2 MiB as UTF-8 (`413` above). Line endings are written as sent. */
            'content' => ['present', 'nullable', 'string'],
            /** `hash` of the version read (readFile), or null to create a new file. A file that changed since answers `409` `E_INUSE`. */
            'hash' => ['present', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    /**
     * The controller reads `content` from the JSON body as sent, so
     * TrimStrings does not drop its final line break.
     *
     * @return list<string>
     */
    protected function multiLineFields(): array
    {
        return ['content'];
    }
}
