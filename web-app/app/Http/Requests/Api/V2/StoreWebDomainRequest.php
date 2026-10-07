<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/web-domains: the fields of the add page (web/add/web).
 * The domain is lowercased and loses a leading "www.".
 */
final class StoreWebDomainRequest extends WebDomainRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => (string) preg_replace('/^www\./i', '', strtolower(trim($this->input('domain'))))]);
        }
        if (is_array($this->input('aliases'))) {
            $this->merge(['aliases' => array_map(fn ($alias) => is_string($alias) ? strtolower(trim($alias)) : $alias, $this->input('aliases'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The domain name; IDNs are accepted as Unicode. */
            'domain' => ['required', 'string', 'max:253', 'regex:/^[\pL\pN]([\pL\pN-]*[\pL\pN])?(\.[\pL\pN]([\pL\pN-]*[\pL\pN])?)+$/u'],
            /** One of the user's addresses (v-list-user-ips); the first one when left out. */
            'ip' => ['sometimes', 'ip'],
            /** Replace the default alias www.DOMAIN. */
            'aliases' => ['sometimes', 'array', 'max:500'],
            'aliases.*' => ['string', 'max:253', 'regex:/^[\pL\pN*_.-]+$/u'],
            /** Also add a DNS zone for the domain, with the public address of the IP. */
            'dns' => ['sometimes', 'boolean'],
            /** Also add a mail domain. */
            'mail' => ['sometimes', 'boolean'],
            ...$this->templateRules(),
        ];
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return array_values(array_unique(array_filter((array) $this->input('aliases', []), 'is_string')));
    }
}
