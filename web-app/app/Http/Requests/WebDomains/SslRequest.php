<?php

namespace App\Http\Requests\WebDomains;

use App\Services\WebDomains\WebDomainService;
use Illuminate\Validation\Rule;

final class SslRequest extends TabRequest
{
    public function tab(): string
    {
        return 'ssl';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ssl' => ['nullable', 'boolean'],
            'letsencrypt' => ['nullable', 'boolean'],
            'key_algo' => ['nullable', Rule::in(WebDomainService::KEY_ALGORITHMS)],
            'certificate' => ['nullable', 'string', 'max:65536'],
            'key' => ['nullable', 'string', 'max:65536'],
            'ca' => ['nullable', 'string', 'max:65536'],
            'force' => ['nullable', 'boolean'],
            'hsts' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{ssl: bool, letsencrypt: bool, key_algo: string, certificate: ?string, key: ?string, ca: ?string, force: bool, hsts: bool} */
    public function values(): array
    {
        return [
            'ssl' => $this->boolean('ssl'),
            'letsencrypt' => $this->boolean('letsencrypt'),
            'key_algo' => (string) ($this->input('key_algo') ?: 'ecdsa-256'),
            'certificate' => $this->input('certificate'),
            'key' => $this->input('key'),
            'ca' => $this->input('ca'),
            'force' => $this->boolean('force'),
            'hsts' => $this->boolean('hsts'),
        ];
    }
}
