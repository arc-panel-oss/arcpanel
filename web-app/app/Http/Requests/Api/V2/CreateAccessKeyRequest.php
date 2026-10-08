<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/access-keys (E34 batch 6 §4.6, D62).
 */
final class CreateAccessKeyRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Permission sets (see GET /access-key-sets). At least one, except for the own key of an administrator, made with a key without sets: no set gives every command. */
            'permissions' => ['present', 'array', 'list', 'max:50'],
            'permissions.*' => ['string', 'distinct', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            /** A note for the key, at most 255 characters. */
            'comment' => ['nullable', 'string', 'max:255', 'not_regex:/[\'"\\\\]/'],
        ];
    }

    /** @return list<string> */
    public function sets(): array
    {
        return array_values(array_map('strval', (array) $this->input('permissions', [])));
    }
}
