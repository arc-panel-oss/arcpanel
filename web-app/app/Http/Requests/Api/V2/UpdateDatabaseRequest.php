<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/databases/{database}. The user name is the short name.
 */
final class UpdateDatabaseRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'db_user' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
        ];
    }
}
