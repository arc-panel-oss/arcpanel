<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/mail-domains/{domain}. Fields left out keep their value.
 */
final class UpdateMailDomainRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('catchall'))) {
            $this->merge(['catchall' => strtolower(trim($this->input('catchall')))]);
        }
        if (is_string($this->input('webmail'))) {
            $this->merge(['webmail' => strtolower(trim($this->input('webmail')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'antispam' => ['sometimes', 'boolean'],
            'antivirus' => ['sometimes', 'boolean'],
            'dkim' => ['sometimes', 'boolean'],
            'reject' => ['sometimes', 'boolean'],
            /** An address, or null to deliver nothing for unknown accounts. */
            'catchall' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[\w.+-]+@[\w.-]+$/'],
            /** A webmail client the server has, or null to turn webmail off. */
            'webmail' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            /** Messages per hour. Administrators only. */
            'rate_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999'],
        ];
    }
}
