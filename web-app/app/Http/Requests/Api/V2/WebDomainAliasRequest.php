<?php

namespace App\Http\Requests\Api\V2;

/** POST /users/{user}/web-domains/{domain}/aliases: one more name of the domain, lowercased. */
final class WebDomainAliasRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('alias'))) {
            $this->merge(['alias' => strtolower(trim($this->input('alias')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A host name such as www.example.com, or a wildcard such as *.example.com. */
            'alias' => ['required', 'string', 'max:253', 'regex:/^(\*\.)?[\pL\pN]([\pL\pN_-]*[\pL\pN])?(\.[\pL\pN]([\pL\pN_-]*[\pL\pN])?)+$/u'],
        ];
    }
}
