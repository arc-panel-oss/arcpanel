<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}: the General tab without the password (PUT
 * /users/{user}/password); fields left out keep their value.
 */
final class UpdateUserRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The full name. */
            'name' => ['sometimes', 'required', 'string', 'max:100', 'not_regex:/[\'"\\\\\n\r]/'],
            'email' => ['sometimes', 'required', 'email', 'max:254'],
            'language' => ['sometimes', 'required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
        ];
    }
}
