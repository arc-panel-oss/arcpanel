<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Databases\DatabaseService;

/**
 * PUT /users/{user}/databases/{database}/password.
 */
final class ChangeDatabasePasswordRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:256', 'regex:'.DatabaseService::PASSWORD_RULE],
        ];
    }
}
