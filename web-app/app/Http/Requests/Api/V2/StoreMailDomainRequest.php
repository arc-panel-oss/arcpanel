<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/mail-domains. The domain is lowercased and loses a leading "www.".
 */
final class StoreMailDomainRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => (string) preg_replace('/^www\./i', '', strtolower(trim($this->input('domain'))))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The mail domain. */
            'domain' => ['required', 'string', 'max:253', 'regex:/^[\pL\pN]([\pL\pN-]*[\pL\pN])?(\.[\pL\pN]([\pL\pN-]*[\pL\pN])?)+$/u'],
            /** Mark incoming spam. On when left out and the server has a spam filter. */
            'antispam' => ['sometimes', 'boolean'],
            /** Scan incoming mail. On when left out and the server has an anti-virus. */
            'antivirus' => ['sometimes', 'boolean'],
            /** Sign outgoing mail. On when left out. */
            'dkim' => ['sometimes', 'boolean'],
        ];
    }
}
