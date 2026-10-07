<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Databases\DatabaseService;

/**
 * POST /users/{user}/mail-domains/{domain}/accounts.
 */
final class StoreMailAccountRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('account'))) {
            $this->merge(['account' => strtolower(trim($this->input('account')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The local part. */
            'account' => ['required', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'password' => ['required', 'string', 'regex:'.DatabaseService::PASSWORD_RULE],
            /** Megabytes, or null for unlimited. */
            'quota_mb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999'],
            'aliases' => ['sometimes', 'array'],
            'aliases.*' => ['required', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'forwards' => ['sometimes', 'array'],
            'forwards.*' => ['required', 'string', 'max:255', 'regex:/^[\w.+-]+@[\w.-]+$/'],
            'forward_only' => ['sometimes', 'boolean'],
            /** Messages per hour. Administrators only. */
            'rate_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999'],
            /** An address that receives the account's settings. */
            'send_credentials_to' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
