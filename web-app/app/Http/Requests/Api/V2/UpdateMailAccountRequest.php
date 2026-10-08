<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/mail-domains/{domain}/accounts/{account}.
 * Only the tabs whose fields are sent are planned.
 */
final class UpdateMailAccountRequest extends ApiRequest
{
    /** The autoreply message spans lines. */
    protected function multiLineFields(): array
    {
        return ['autoreply'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quota_mb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999'],
            'aliases' => ['sometimes', 'array'],
            'aliases.*' => ['required', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'forwards' => ['sometimes', 'array'],
            'forwards.*' => ['required', 'string', 'max:255', 'regex:/^[\w.+-]+@[\w.-]+$/'],
            'forward_only' => ['sometimes', 'boolean'],
            /** Discard incoming mail. Refused together with forwards. */
            'discard_all' => ['sometimes', 'boolean'],
            /** The autoreply text, or null to turn it off. */
            'autoreply' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'rate_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999'],
        ];
    }
}
