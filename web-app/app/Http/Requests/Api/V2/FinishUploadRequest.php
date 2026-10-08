<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Validation\Rule;

/** Body of `finishUpload` (E34 batch 5 spec §4.3). */
final class FinishUploadRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A file that exists at the target: `fail` (default; `409` with `conflicts`), `skip` (the upload is dropped), `overwrite` or `rename` (first free "name (n).ext"). */
            'on_conflict' => ['sometimes', Rule::in(['fail', 'skip', 'overwrite', 'rename'])],
        ];
    }
}
