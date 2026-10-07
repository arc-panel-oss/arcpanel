<?php

namespace App\Http\Requests\Api\V2;

/** POST /packages/{package}/copy */
final class CopyPackageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The name of the copy. */
            'name' => ['required', 'string', 'max:60', 'regex:/^[[:alnum:]][-.\w]*$/'],
        ];
    }
}
