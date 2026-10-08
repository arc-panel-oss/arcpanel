<?php

namespace App\Http\Requests\Api\V2;

/** Body of `changeFileMode` (E34 batch 5 spec §4.2). */
final class ChangeFileModeRequest extends ApiRequest
{
    private const MODE = 'regex:/^[0-7]?[0-7]{3}$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Entries relative to the home directory. */
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string', 'distinct'],
            /** Octal mode, e.g. `0644` or `755`. */
            'mode' => ['required', 'string', self::MODE],
            /** With `recursive`: the mode of the files inside a folder (default `mode`). */
            'files_mode' => ['sometimes', 'nullable', 'string', self::MODE],
            /** A folder: change everything inside too (folders get `mode`, files `files_mode`; symbolic links are skipped). Without it only the folder itself changes. */
            'recursive' => ['sometimes', 'boolean'],
        ];
    }
}
