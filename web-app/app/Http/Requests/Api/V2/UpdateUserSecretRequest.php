<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/secrets/{name}: the description of a secret (E38).
 */
final class UpdateUserSecretRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** At most 255 characters, without quotes, `$`, backquote, backslash or `|`; empty removes it. */
            'description' => ['present', 'nullable', 'string', 'max:255', 'not_regex:/[\'"$`\\\\|]/'],
        ];
    }
}
