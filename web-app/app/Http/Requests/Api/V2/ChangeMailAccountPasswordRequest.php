<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Databases\DatabaseService;

/**
 * PUT /users/{user}/mail-domains/{domain}/accounts/{account}/password.
 */
final class ChangeMailAccountPasswordRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'regex:'.DatabaseService::PASSWORD_RULE],
        ];
    }
}
