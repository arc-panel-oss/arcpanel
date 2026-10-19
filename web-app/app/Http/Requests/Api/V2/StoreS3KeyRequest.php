<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Keys\UserSecretManager;
use Illuminate\Validation\Rule;

/**
 * POST /users/{user}/s3-keys (E44 spec §7, §10).
 */
final class StoreS3KeyRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** 1-32 letters, digits, spaces, `_ . -`. */
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,31}$/'],
            /** Limit the key to one bucket of the account; left out, it reaches every bucket of the account. */
            'bucket' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/'],
            /** `ro` reads only. */
            'access' => ['sometimes', Rule::in(['rw', 'ro'])],
            /** YYYY-MM-DD in the future: the key stops working at the start of that day (UTC). */
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            /** Also save the secret in the account's secret store under this name (E38), for example `/s3/media`. */
            'save_as' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:'.UserSecretManager::NAME_PATTERN],
        ];
    }
}
