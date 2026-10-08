<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /users/{user}/web-domains/{domain}/ssl: an uploaded certificate, or
 * forced HTTPS and HSTS alone for the certificate the domain has.
 */
final class WebDomainSslRequest extends ApiRequest
{
    /** PEM text spans lines. */
    protected function multiLineFields(): array
    {
        return ['certificate', 'key', 'ca'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pem = ['sometimes', 'nullable', 'string', 'max:65536'];

        return [
            /** The certificate (PEM); left out, the domain keeps its certificate, Let's Encrypt included. */
            'certificate' => $pem,
            /** Its private key (PEM); required with a first certificate, left out it keeps the current key. */
            'key' => $pem,
            /** The intermediate certificates (PEM). */
            'ca' => $pem,
            /** Redirect HTTP to HTTPS; left out it keeps its value. */
            'force' => ['sometimes', 'boolean'],
            /** Send Strict-Transport-Security; left out it keeps its value. */
            'hsts' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{certificate?: ?string, key?: ?string, ca?: ?string, force?: bool, hsts?: bool} */
    public function values(): array
    {
        $values = $this->only(['certificate', 'key', 'ca']);
        foreach (['force', 'hsts'] as $field) {
            if ($this->has($field)) {
                $values[$field] = $this->boolean($field);
            }
        }

        return $values;
    }
}
