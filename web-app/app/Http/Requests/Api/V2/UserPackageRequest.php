<?php

namespace App\Http\Requests\Api\V2;

/** PUT /users/{user}/package */
final class UserPackageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A package the caller may assign (see GET /packages). */
            'package' => ['required', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/'],
        ];
    }
}
