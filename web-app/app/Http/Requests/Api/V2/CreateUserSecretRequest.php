<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Keys\UserSecretManager;

/**
 * POST /users/{user}/secrets: a new secret of the secret store (E38).
 */
final class CreateUserSecretRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A path of one to five parts of letters, digits, `_`, `.` and `-`, for example `/shop/db_password`; at most 128 characters. */
            'name' => ['required', 'string', 'max:128', 'regex:'.UserSecretManager::NAME_PATTERN, 'not_regex:#/\.\.?(/|$)#'],
            /** The value, 1 byte to 32 KiB; it is never returned. One final line break is dropped. */
            'value' => ['required', 'string', 'max:'.UserSecretManager::MAX_VALUE],
            /** At most 255 characters, without quotes, `$`, backquote, backslash or `|`. */
            'description' => ['nullable', 'string', 'max:255', 'not_regex:/[\'"$`\\\\|]/'],
        ];
    }

    /** @return list<string> */
    protected function multiLineFields(): array
    {
        return ['value'];
    }
}
