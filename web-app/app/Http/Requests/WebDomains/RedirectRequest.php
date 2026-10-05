<?php

namespace App\Http\Requests\WebDomains;

use Illuminate\Validation\Rule;

final class RedirectRequest extends TabRequest
{
    public function tab(): string
    {
        return 'redirect';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'target' => ['nullable', Rule::in(['www', 'bare', 'custom'])],
            'custom' => ['nullable', 'required_if:target,custom', 'string', 'max:2048', 'regex:/^\S+$/'],
            'code' => ['nullable', Rule::in(['301', '302'])],
        ];
    }

    /** @return array{enabled: bool, target: string, custom: ?string, code: int} */
    public function values(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'target' => (string) ($this->input('target') ?: 'www'),
            'custom' => $this->input('custom'),
            'code' => (int) ($this->input('code') ?: 301),
        ];
    }
}
