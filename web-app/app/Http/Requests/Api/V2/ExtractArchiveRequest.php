<?php

namespace App\Http\Requests\Api\V2;

/** Body of `extractArchive` (E34 batch 5 spec §4.2, D52). */
final class ExtractArchiveRequest extends ApiRequest
{
    /** Archive names the API extracts (D52); single-file .gz, .bz2 and .zst are refused. */
    public const FORMATS = '/\.(zip|tar\.gz|tgz|tar\.bz2|tbz|tar\.zst|7z)$/i';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Archive relative to the home directory: `.zip`, `.tar.gz`, `.tgz`, `.tar.bz2`, `.tbz`, `.tar.zst` or `.7z`. */
            'path' => ['required', 'string', 'regex:'.self::FORMATS],
            /** A folder that does not exist yet, relative to the home directory; it is created with its missing parents. */
            'destination' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['path.regex' => __('Choose a zip, tar.gz, tgz, tar.bz2, tbz, tar.zst or 7z archive.')];
    }
}
