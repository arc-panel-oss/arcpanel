<?php

namespace App\Http\Requests\WebDomains;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a web domain (web/add/web): the domain is lowercased and loses a
 * leading "www."; the IP must be one of the owner's (checked by the
 * controller against `v-list-user-ips`).
 */
final class StoreWebDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $domain = strtolower(trim((string) $this->input('domain')));
        $this->merge(['domain' => (string) preg_replace('/^www\./i', '', $domain)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $name = ['nullable', 'string', 'max:100', 'regex:/^[\w.-]+$/'];

        return [
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^[\pL\pN]([\pL\pN-]*[\pL\pN])?(\.[\pL\pN]([\pL\pN-]*[\pL\pN])?)+$/u'],
            'ip' => ['required', 'ip'],
            'aliases' => ['nullable', 'string', 'max:10000', 'regex:/^[\pL\pN\s.,*_-]*$/u'],
            'dns' => ['nullable', 'boolean'],
            'mail' => ['nullable', 'boolean'],
            'template' => $name,
            'backend_template' => $name,
            'proxy_template' => $name,
            'proxy_extensions' => ['nullable', 'string', 'max:4000', 'regex:/^[\w\s,.-]*$/'],
            'backend_port' => ['nullable', 'string', 'regex:/^(auto|\d{1,5})$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['domain' => __('Domain'), 'ip' => __('IP Address')];
    }
}
