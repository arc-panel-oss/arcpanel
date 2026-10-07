<?php

namespace App\Http\Requests\Api\V2;

/** PUT /users/{user}/password */
final class UserPasswordRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** At least 8 characters with a lower-case and an upper-case letter and a digit. */
            'password' => ['required', 'string', 'min:8', 'max:256', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/\d/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.regex' => __('Password does not match the minimum requirements.')];
    }
}
