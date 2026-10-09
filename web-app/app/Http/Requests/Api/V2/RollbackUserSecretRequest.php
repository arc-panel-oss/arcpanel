<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/secrets/{name}/rollback: copy a kept version into a new one (E38).
 */
final class RollbackUserSecretRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A kept version (see `versions` of getSecret). */
            'version' => ['required', 'integer', 'min:1', 'max:999999999'],
            /** Restart the running apps that reference the secret. Default false. */
            'restart' => ['sometimes', 'boolean'],
        ];
    }
}
