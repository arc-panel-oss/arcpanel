<?php

namespace App\Http\Requests\WebDomains;

final class AdvancedRequest extends TabRequest
{
    public function tab(): string
    {
        return 'advanced';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $name = ['nullable', 'string', 'max:100', 'regex:/^[\w.-]+$/'];

        return [
            'template' => $name,
            'backend_template' => $name,
            'proxy' => ['nullable', 'boolean'],
            'proxy_template' => $name,
            'proxy_extensions' => ['nullable', 'string', 'max:4000', 'regex:/^[\w\s,.-]*$/'],
            'fastcgi_cache' => ['nullable', 'boolean'],
            'fastcgi_duration' => ['nullable', 'string', 'regex:/^\d+[smhd]$/'],
        ];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return [
            'template' => $this->input('template'),
            'backend_template' => $this->input('backend_template'),
            'proxy' => $this->boolean('proxy'),
            'proxy_template' => $this->input('proxy_template'),
            'proxy_extensions' => $this->input('proxy_extensions'),
            'fastcgi_cache' => $this->boolean('fastcgi_cache'),
            'fastcgi_duration' => $this->input('fastcgi_duration'),
        ];
    }
}
