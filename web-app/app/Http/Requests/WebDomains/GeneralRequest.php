<?php

namespace App\Http\Requests\WebDomains;

final class GeneralRequest extends TabRequest
{
    public function tab(): string
    {
        return 'general';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'aliases' => ['nullable', 'string', 'max:10000', 'regex:/^[\pL\pN\s.,*_-]*$/u'],
            'ip' => ['required', 'ip'],
            'docroot_custom' => ['nullable', 'boolean'],
            'docroot_domain' => ['nullable', 'required_if:docroot_custom,1', 'string', 'max:253', 'regex:/^[\pL\pN.-]+$/u'],
            'docroot_folder' => ['nullable', 'string', 'max:255', 'regex:/^[\pL\pN\/._ -]*$/u', 'not_regex:/\.\./'],
        ];
    }

    /** @return array{aliases: ?string, ip: string, docroot_custom: bool, docroot_domain: ?string, docroot_folder: ?string} */
    public function values(): array
    {
        return [
            'aliases' => $this->input('aliases'),
            'ip' => (string) $this->input('ip'),
            'docroot_custom' => $this->boolean('docroot_custom'),
            'docroot_domain' => $this->input('docroot_domain'),
            'docroot_folder' => $this->input('docroot_folder'),
        ];
    }
}
