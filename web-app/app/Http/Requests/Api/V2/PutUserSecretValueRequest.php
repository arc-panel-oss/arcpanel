<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Keys\UserSecretManager;

/**
 * PUT /users/{user}/secrets/{name}/value: the next version of a secret (E38).
 */
final class PutUserSecretValueRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The new value, 1 byte to 32 KiB; it is never returned. */
            'value' => ['required', 'string', 'max:'.UserSecretManager::MAX_VALUE],
            /** Restart the running apps that reference the secret, so they read the new value. Default false. */
            'restart' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string> */
    protected function multiLineFields(): array
    {
        return ['value'];
    }
}
